<?php

namespace App\Services;

use App\Mail\HeldMailNotification;
use App\Models\AuditEvent;
use App\Models\HeldMessage;
use App\Models\Setting;
use App\Models\SmimeCertificate;
use App\Support\RawMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use ZBateson\MailMimeParser\Message;

/**
 * Verarbeitet eingehende Mails an interne Empfänger:
 *  - S/MIME-verschlüsselte Mails werden mit den eigenen Zertifikaten entschlüsselt
 *  - Signaturen werden geprüft und das Ergebnis als X-MGW-Signature-Kopfzeile vermerkt;
 *    Absender-Zertifikate werden geerntet (kettengeprüft → aktiv, sonst inaktiv)
 *  - Ausgeliefert wird die verifizierte Nutzlast im Klartext. Die Signatur-Hülle
 *    wird dabei entfernt: Sie ließe sich durch das Entschlüsseln + Umverpacken
 *    ohnehin nicht zuverlässig gültig halten, und der Client zeigt sie sonst als
 *    defekten „smime.p7s"-Anhang. Das Prüfergebnis steht in der Kopfzeile.
 */
class SmimeInboundService
{
    /** @return string[] Status-Schritte (für Audit/Header) */
    public function process(string $raw, string $sender, array $recipients, bool $allowHold = true): array
    {
        $tmpDir = sys_get_temp_dir().'/mgw-in-'.bin2hex(random_bytes(8));
        if (! mkdir($tmpDir, 0700)) {
            throw new RuntimeException('Temp-Verzeichnis konnte nicht angelegt werden.');
        }

        try {
            return $this->doProcess($raw, $sender, $recipients, $tmpDir, $allowHold);
        } finally {
            array_map('unlink', glob($tmpDir.'/*') ?: []);
            @rmdir($tmpDir);
        }
    }

    /**
     * Versucht eine zurückgehaltene Mail erneut zu verarbeiten.
     * Erfolgreiche Entschlüsselung → normale Zustellung + Freigabe.
     * $deliverAnyway → auch ohne Schlüssel unverändert zustellen (Frist/Handfreigabe).
     *
     * @return bool true, wenn die Mail zugestellt und freigegeben wurde
     */
    public function retryHeld(HeldMessage $held, bool $deliverAnyway = false, string $actionIfUndeciphered = 'as_is'): bool
    {
        $raw = $held->rawContent();

        if (! $this->probeDecrypt($raw) && ! $deliverAnyway) {
            $held->increment('retry_count');

            return false;
        }

        // allowHold=false: darf beim erneuten Scheitern nicht wieder einlagern
        $status = $this->process($raw, $held->sender, $held->recipients, allowHold: false);
        $decrypted = (bool) array_filter($status, fn ($s) => str_starts_with($s, 'decrypted') || str_starts_with($s, 'unwrapped_decrypted'));
        $held->release($decrypted ? 'decrypted' : $actionIfUndeciphered);

        AuditEvent::log('held_released', details: [
            'sender' => $held->sender,
            'recipients' => $held->recipients,
            'subject' => $held->subject,
            'action' => $held->release_action,
            'status' => $status,
        ]);

        return true;
    }

    /** Lagert die Mail in die Quarantäne ein und benachrichtigt den Admin. */
    private function holdMessage(string $raw, string $sender, array $recipients, string $topHeaders, string $diagnosis): void
    {
        // findHeader liefert die komplette Zeile inkl. "Subject:" — Präfix abschneiden
        $subject = RawMail::findHeader($topHeaders, 'subject');
        if ($subject !== null) {
            $subject = trim(preg_replace('/^subject\s*:\s*/i', '', $subject));
            $decoded = @iconv_mime_decode($subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            $subject = trim($decoded !== false ? $decoded : $subject);
        }

        $held = HeldMessage::hold($raw, $sender, $recipients, $subject, ltrim($diagnosis));

        AuditEvent::log('inbound_held', details: [
            'sender' => $sender,
            'recipients' => $recipients,
            'subject' => $held->subject,
            'diagnosis' => $held->diagnosis,
            'hold_until' => $held->hold_until->format('d.m.Y H:i'),
        ]);

        // Die Benachrichtigung darf das Einlagern nie scheitern lassen.
        $notify = (string) Setting::get('admin_notify_email', config('mailgateway.admin_notify_email'));
        if ($notify !== '') {
            try {
                Mail::to($notify)->send(new HeldMailNotification($held));
            } catch (\Throwable $e) {
                Log::error('Quarantäne-Benachrichtigung fehlgeschlagen', ['error' => $e->getMessage()]);
            }
        }
    }

    /** Prüft ohne Zustellung, ob die Mail inzwischen entschlüsselbar ist. */
    private function probeDecrypt(string $raw): bool
    {
        $tmpDir = sys_get_temp_dir().'/mgw-probe-'.bin2hex(random_bytes(8));
        if (! mkdir($tmpDir, 0700)) {
            throw new RuntimeException('Temp-Verzeichnis konnte nicht angelegt werden.');
        }

        try {
            [$topHeaders] = RawMail::split($raw);
            [$kind, $smimeFile] = $this->locateSmime($raw, $topHeaders, $tmpDir);
            if ($kind !== 'encrypted' && $kind !== 'encrypted-wrapped') {
                return true; // nicht (mehr) verschlüsselt — normal verarbeitbar
            }

            return $this->tryDecrypt($smimeFile, $tmpDir.'/probe-out.eml', $tmpDir);
        } finally {
            array_map('unlink', glob($tmpDir.'/*') ?: []);
            @rmdir($tmpDir);
        }
    }

    private function doProcess(string $raw, string $sender, array $recipients, string $tmpDir, bool $allowHold = true): array
    {
        // Debug-Mitschrift (Admin-Setting debug_inbound), zur Analyse von Verpackungsvarianten
        if (Setting::getBool('debug_inbound', false)) {
            $dir = storage_path('app/debug');
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir.'/in-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.eml', $raw);
        }

        $status = [];
        [$topHeaders] = RawMail::split($raw);
        [$kind, $smimeFile] = $this->locateSmime($raw, $topHeaders, $tmpDir);

        $deliverEntity = null;   // ersetzt Body + Content-Header, wenn gesetzt
        $sigHeader = null;       // X-MGW-Signature-Wert

        // 1) Entschlüsseln (direkt oder aus Multipart-Hülle ausgepackt)
        if ($kind === 'encrypted' || $kind === 'encrypted-wrapped') {
            $out = $tmpDir.'/decrypted.eml';
            if ($this->tryDecrypt($smimeFile, $out, $tmpDir)) {
                $status[] = $kind === 'encrypted-wrapped' ? 'unwrapped_decrypted' : 'decrypted';
                $deliverEntity = file_get_contents($out);

                // 2a) Innen signiert? Dann prüfen, ernten und Signatur-Hülle entfernen
                if ($this->isSigned(RawMail::split($deliverEntity)[0])) {
                    [$sigStatus, $content] = $this->verifyAndHarvest($out, $sender, $tmpDir);
                    $status[] = $sigStatus;
                    $sigHeader = $this->signatureHeader($sigStatus, $sender);
                    if ($content !== null) {
                        $deliverEntity = $content;
                    }
                }
            } else {
                $diagnosis = $this->describeRecipientInfos($smimeFile);

                // Quarantäne (optional): zurückhalten, Admin benachrichtigen, Zertifikat
                // kann nachgereicht werden. Sonst: unverändert zustellen statt verlieren.
                if ($allowHold && Setting::getBool('inbound_hold_enabled', (bool) config('mailgateway.inbound_hold_enabled'))) {
                    $this->holdMessage($raw, $sender, $recipients, $topHeaders, $diagnosis);

                    return ['held'.$diagnosis];
                }

                $status[] = 'decrypt_failed'.$diagnosis;
            }
        }
        // 2b) Nur signiert (direkt oder ausgepackt)
        elseif ($kind === 'multipart-signed' || $kind === 'opaque-signed' || $kind === 'opaque-signed-wrapped') {
            [$sigStatus, $content] = $this->verifyAndHarvest($smimeFile, $sender, $tmpDir);
            $status[] = $sigStatus;
            $sigHeader = $this->signatureHeader($sigStatus, $sender);
            if ($content !== null) {
                $deliverEntity = $content;
            }
        }

        // 3) Zustellen
        $final = $this->compose($topHeaders, $raw, $deliverEntity, $status, $sigHeader);
        RawMail::submit($final, $sender, $recipients);

        return $status;
    }

    /**
     * Findet den S/MIME-Inhalt: entweder direkt auf oberster Ebene oder —
     * wie von Exchange nach Transportregel-Änderungen erzeugt — als
     * smime.p7m-Teil in einer multipart-Hülle.
     *
     * @return array{0: ?string, 1: ?string} [Art, Dateipfad]
     */
    private function locateSmime(string $raw, string $topHeaders, string $tmpDir): array
    {
        $kind = $this->contentKind($topHeaders);
        if ($kind !== null) {
            $file = $tmpDir.'/current.eml';
            file_put_contents($file, $raw);

            return [$kind, $file];
        }

        foreach (Message::from($raw, false)->getAllParts() as $part) {
            $ct = strtolower((string) $part->getContentType());
            $name = strtolower((string) $part->getFilename());
            if (! str_contains($ct, 'pkcs7-mime') && ! str_ends_with($name, '.p7m')) {
                continue;
            }
            $content = $part->getContent();
            if ($content === null || $content === '') {
                continue;
            }
            $smimeType = str_contains($ct, 'signed-data') ? 'signed-data' : 'enveloped-data';
            $file = $tmpDir.'/unwrapped.eml';
            file_put_contents($file,
                'Content-Type: application/pkcs7-mime; smime-type='.$smimeType.'; name="smime.p7m"'."\n".
                'Content-Transfer-Encoding: base64'."\n\n".
                chunk_split(base64_encode($content), 64, "\n"));

            return [$smimeType === 'signed-data' ? 'opaque-signed-wrapped' : 'encrypted-wrapped', $file];
        }

        return [null, null];
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

    private function isSigned(string $headerBlock): bool
    {
        $kind = $this->contentKind($headerBlock);

        return $kind === 'multipart-signed' || $kind === 'opaque-signed';
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

    /**
     * Diagnose bei fehlgeschlagener Entschlüsselung: an welche Zertifikate
     * (Aussteller/Seriennummer) war die Mail verschlüsselt?
     */
    private function describeRecipientInfos(string $file): string
    {
        $out = (string) shell_exec('openssl cms -in '.escapeshellarg($file).' -cmsout -print -noout 2>/dev/null');
        if ($out === '') {
            return '';
        }
        $targets = [];
        if (preg_match_all('/issuer:\s*(.+?)\n\s*serialNumber:\s*(\S+)/s', $out, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $serial = $hit[2];
                if (str_starts_with($serial, '0x')) {
                    $serial = strtoupper(substr($serial, 2));
                } elseif (ctype_digit($serial)) {
                    // Zertifikats-Tools zeigen Serials hexadezimal — auch jenseits
                    // von PHP_INT_MAX umrechnen (bcmath), sonst findet man sie nicht
                    $serial = self::decimalToHex($serial);
                }
                $targets[] = 'Serial '.$serial.', ausgestellt von: '.trim($hit[1]);
            }
        }
        if ($targets === []) {
            return '';
        }

        // Der Umschlag nennt nur Aussteller + Serial des Zielzertifikats —
        // NICHT dessen Inhaber. Formulierung muss das klarmachen, sonst wird
        // der Aussteller (z.B. eine interne CA) für das Zertifikat gehalten.
        return ' (verschlüsselt an Zertifikat: '.implode(' | ', array_unique($targets)).')';
    }

    /** Dezimal → Hex für beliebig große Seriennummern (bcmath). */
    private static function decimalToHex(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0') > 0) {
            $hex = dechex((int) bcmod($dec, '16')).$hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return $hex === '' ? '0' : strtoupper($hex);
    }

    /**
     * Prüft die Signatur, erntet ggf. das Absender-Zertifikat und liefert
     * die signierte Nutzlast zurück (ohne Signatur-Hülle).
     *
     * @return array{0: string, 1: ?string} [Statuscode, Nutzlast oder null]
     */
    private function verifyAndHarvest(string $file, string $sender, string $tmpDir): array
    {
        $signerFile = $tmpDir.'/signer.pem';
        $contentFile = $tmpDir.'/signed-content.eml';

        // NOVERIFY = nur kryptografische Prüfung; schreibt zugleich die Nutzlast heraus
        if (@openssl_pkcs7_verify($file, PKCS7_NOVERIFY, $signerFile, [], null, $contentFile) !== true) {
            return ['signature_invalid', null];
        }
        $content = is_file($contentFile) ? file_get_contents($contentFile) : null;

        // Kettenprüfung gegen die System-CAs → aktiv vs. manuelle Freigabe
        $chainOk = @openssl_pkcs7_verify($file, 0, $tmpDir.'/signer-chain.pem', ['/etc/ssl/certs/ca-certificates.crt']) === true;

        $pem = (string) file_get_contents($signerFile);
        $x509 = @openssl_x509_read($pem);
        if ($x509 === false) {
            return [$chainOk ? 'signed_valid' : 'signed_untrusted', $content];
        }
        $info = openssl_x509_parse($x509);

        if (! in_array(strtolower($sender), $this->certEmails($info), true)) {
            return [($chainOk ? 'signed_valid' : 'signed_untrusted').'_address_mismatch', $content];
        }

        $now = time();
        if (($info['validFrom_time_t'] ?? 0) > $now || ($info['validTo_time_t'] ?? PHP_INT_MAX) < $now) {
            return ['signed_cert_expired', $content];
        }
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');
        if (SmimeCertificate::where('fingerprint', $fingerprint)->exists()) {
            return [$chainOk ? 'signed_valid' : 'signed_untrusted', $content];
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

        return [$chainOk ? 'signed_valid_harvested' : 'signed_untrusted_harvested', $content];
    }

    /** Menschenlesbarer X-MGW-Signature-Wert aus dem Statuscode. */
    private function signatureHeader(string $status, string $sender): string
    {
        if ($status === 'signature_invalid') {
            return 'INVALID (Signatur kryptografisch ungültig)';
        }
        if (str_contains($status, 'address_mismatch')) {
            return "invalid (Signatur-Zertifikat lautet nicht auf {$sender})";
        }
        if ($status === 'signed_cert_expired') {
            return "expired (Signatur-Zertifikat von {$sender} abgelaufen)";
        }
        if (str_starts_with($status, 'signed_valid')) {
            return "valid (signiert von {$sender}, Zertifikatskette vertrauenswürdig)";
        }
        if (str_starts_with($status, 'signed_untrusted')) {
            return "valid-untrusted (signiert von {$sender}, Aussteller nicht im Vertrauensspeicher)";
        }

        return $status;
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
     * verarbeiteter Inhalt, Marker-, Status- und Signatur-Header.
     * Das Ergebnis wird durchgängig auf CRLF normalisiert.
     */
    private function compose(string $topHeaders, string $raw, ?string $deliverEntity, array $status, ?string $sigHeader): string
    {
        $drop = [
            strtolower((string) config('mailgateway.secret_header')),
            'x-mgw-notification',
            'x-mgw-status',
            'x-mgw-signature',
        ];
        if ($deliverEntity !== null) {
            // Content-Header beschreibt jetzt die ausgelieferte Nutzlast selbst
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
        if ($sigHeader !== null) {
            $keep[] = 'X-MGW-Signature: '.$sigHeader;
        }

        if ($deliverEntity !== null) {
            $message = implode("\n", $keep)."\n".$deliverEntity;
        } else {
            [, $body] = RawMail::split($raw);
            $message = implode("\n", $keep)."\n\n".$body;
        }

        // Einheitliche CRLF-Zeilenenden (SMTP-konform)
        return preg_replace('/\r\n|\r|\n/', "\r\n", $message);
    }
}
