<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\SmimeCertificate;
use App\Support\RawMail;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Verarbeitet eingehende Mails an interne Empfänger:
 *  - S/MIME-verschlüsselte Mails werden mit den eigenen Zertifikaten entschlüsselt
 *  - Signaturen werden geprüft; Absender-Zertifikate werden geerntet
 *    (kettengeprüft → sofort aktiv, sonst inaktiv zur manuellen Freigabe)
 *  - Zustellung dann im Klartext (Signatur bleibt für den Client erhalten)
 */
class SmimeInboundService
{
    /** @return string[] Status-Schritte (für Audit/Header) */
    public function process(string $raw, string $sender, array $recipients): array
    {
        $tmpDir = sys_get_temp_dir().'/mgw-in-'.bin2hex(random_bytes(8));
        if (! mkdir($tmpDir, 0700)) {
            throw new RuntimeException('Temp-Verzeichnis konnte nicht angelegt werden.');
        }

        try {
            return $this->doProcess($raw, $sender, $recipients, $tmpDir);
        } finally {
            array_map('unlink', glob($tmpDir.'/*') ?: []);
            @rmdir($tmpDir);
        }
    }

    private function doProcess(string $raw, string $sender, array $recipients, string $tmpDir): array
    {
        $status = [];
        [$topHeaders] = RawMail::split($raw);

        $currentFile = $tmpDir.'/current.eml';
        file_put_contents($currentFile, $raw);
        $decrypted = false;

        // 1) Entschlüsseln (application/pkcs7-mime, enveloped-data)
        if ($this->contentKind($topHeaders) === 'encrypted') {
            $out = $tmpDir.'/decrypted.eml';
            if ($this->tryDecrypt($currentFile, $out, $tmpDir)) {
                $status[] = 'decrypted';
                $currentFile = $out;
                $decrypted = true;
            } else {
                // Kein passender Schlüssel: unverändert zustellen statt Mail zu verlieren
                $status[] = 'decrypt_failed';
            }
        }

        // 2) Signatur prüfen + Zertifikat ernten
        $innerHeaders = $decrypted ? RawMail::split(file_get_contents($currentFile))[0] : $topHeaders;
        $kind = $this->contentKind($innerHeaders);
        if ($kind === 'multipart-signed' || $kind === 'opaque-signed') {
            $status[] = $this->verifyAndHarvest($currentFile, $sender, $tmpDir);
        }

        // 3) Zustellen
        $final = $this->compose($topHeaders, $raw, $decrypted ? file_get_contents($currentFile) : null, $status);
        RawMail::submit($final, $sender, $recipients);

        return $status;
    }

    /** Erkennt die S/MIME-Art anhand des Content-Type-Headers. */
    private function contentKind(string $headerBlock): ?string
    {
        $ct = strtolower(preg_replace('/\s+/', ' ', (string) RawMail::findHeader($headerBlock, 'content-type')));
        if ($ct === '') {
            return null;
        }
        if (str_contains($ct, 'multipart/signed')) {
            return 'multipart-signed';
        }
        if (str_contains($ct, 'pkcs7-mime')) {
            return str_contains($ct, 'signed-data') ? 'opaque-signed' : 'encrypted';
        }

        return null;
    }

    /** Probiert alle eigenen Zertifikate mit privatem Schlüssel durch. */
    private function tryDecrypt(string $inFile, string $outFile, string $tmpDir): bool
    {
        $own = SmimeCertificate::where('type', 'own')->whereNotNull('key_pem')
            ->orderByDesc('active')->orderByDesc('valid_until')->get();

        foreach ($own as $cert) {
            $leaf = $tmpDir.'/own-cert.pem';
            preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $cert->cert_pem, $m);
            file_put_contents($leaf, $m[0] ?? $cert->cert_pem);
            if (@openssl_cms_decrypt($inFile, $outFile, file_get_contents($leaf), $cert->privateKey(), OPENSSL_ENCODING_SMIME)) {
                return true;
            }
        }

        return false;
    }

    private function verifyAndHarvest(string $file, string $sender, string $tmpDir): string
    {
        $signerFile = $tmpDir.'/signer.pem';
        if (@openssl_pkcs7_verify($file, PKCS7_NOVERIFY, $signerFile) !== true) {
            return 'signature_invalid';
        }

        // Kettenprüfung gegen die System-CAs entscheidet, ob das geerntete
        // Zertifikat sofort aktiv wird oder zur manuellen Freigabe ruht
        $chainOk = @openssl_pkcs7_verify($file, 0, $tmpDir.'/signer-chain.pem', ['/etc/ssl/certs/ca-certificates.crt']) === true;

        $pem = (string) file_get_contents($signerFile);
        $x509 = @openssl_x509_read($pem);
        if ($x509 === false) {
            return $chainOk ? 'signed_valid' : 'signed_untrusted';
        }
        $info = openssl_x509_parse($x509);

        // Zertifikat muss auf die Absenderadresse lauten
        if (! in_array(strtolower($sender), $this->certEmails($info), true)) {
            return ($chainOk ? 'signed_valid' : 'signed_untrusted').'_address_mismatch';
        }

        // Gültigkeitsfenster + Duplikate
        $now = time();
        if (($info['validFrom_time_t'] ?? 0) > $now || ($info['validTo_time_t'] ?? PHP_INT_MAX) < $now) {
            return 'signed_cert_expired';
        }
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');
        if (SmimeCertificate::where('fingerprint', $fingerprint)->exists()) {
            return $chainOk ? 'signed_valid' : 'signed_untrusted';
        }

        SmimeCertificate::create([
            'type' => 'partner',
            'scope' => 'address',
            'target' => strtolower($sender),
            'cert_pem' => $pem,
            'subject' => mb_substr($this->dnToString($info['subject'] ?? []), 0, 512),
            'issuer' => mb_substr($this->dnToString($info['issuer'] ?? []), 0, 512),
            'valid_from' => Carbon::createFromTimestamp($info['validFrom_time_t']),
            'valid_until' => Carbon::createFromTimestamp($info['validTo_time_t']),
            'fingerprint' => $fingerprint,
            'source' => 'harvested',
            'active' => $chainOk,
        ]);
        AuditEvent::log('cert_harvested', details: [
            'target' => strtolower($sender),
            'chain_trusted' => $chainOk,
            'active' => $chainOk,
        ]);

        return $chainOk ? 'signed_valid_harvested' : 'signed_untrusted_harvested';
    }

    /** E-Mail-Adressen aus Subject (emailAddress) und SubjectAltName. */
    private function certEmails(array $info): array
    {
        $emails = [];
        foreach ((array) ($info['subject']['emailAddress'] ?? []) as $e) {
            $emails[] = strtolower($e);
        }
        $san = (string) ($info['extensions']['subjectAltName'] ?? '');
        if (preg_match_all('/email:([^\s,]+)/i', $san, $m)) {
            foreach ($m[1] as $e) {
                $emails[] = strtolower($e);
            }
        }

        return array_unique($emails);
    }

    private function dnToString(array $dn): string
    {
        $parts = [];
        foreach ($dn as $k => $v) {
            foreach ((array) $v as $vv) {
                $parts[] = $k.'='.$vv;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Baut die Zustellfassung: Original-Kopfzeilen (bereinigt) + ggf.
     * entschlüsselter Inhalt, Marker- und Status-Header.
     */
    private function compose(string $topHeaders, string $raw, ?string $decryptedEntity, array $status): string
    {
        $drop = [
            strtolower((string) config('mailgateway.secret_header')),
            'x-mgw-notification',
            'x-mgw-status',
        ];
        if ($decryptedEntity !== null) {
            // Content-Header beschreibt jetzt der entschlüsselte Teil selbst
            array_push($drop, 'content-type', 'content-transfer-encoding', 'content-disposition', 'content-id');
        }

        $keep = [];
        foreach (RawMail::headerLines($topHeaders) as $line) {
            if (! in_array(RawMail::headerName($line), $drop, true)) {
                $keep[] = $line;
            }
        }
        $keep[] = 'X-MGW-Notification: yes';
        if ($status !== []) {
            $keep[] = 'X-MGW-Status: '.implode(', ', $status);
        }

        if ($decryptedEntity !== null) {
            return implode("\n", $keep)."\n".$decryptedEntity;
        }

        [, $body] = RawMail::split($raw);

        return implode("\n", $keep)."\n\n".$body;
    }
}
