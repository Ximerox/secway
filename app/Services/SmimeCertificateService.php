<?php

namespace App\Services;

use App\Models\SmimeCertificate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class SmimeCertificateService
{
    /**
     * Importiert ein Zertifikat aus PEM, DER oder PKCS#12 (.p12/.pfx).
     *
     * @param  string  $raw  Roh-Inhalt der hochgeladenen Datei
     * @param  string  $type  'partner' oder 'own'
     * @param  string  $target  Empfänger-Domain, E-Mail-Adresse oder eigene Domain
     */
    public function import(string $raw, string $type, string $target, ?string $password = null, string $source = 'upload'): SmimeCertificate
    {
        $target = strtolower(trim($target));
        $scope = str_contains($target, '@') ? 'address' : 'domain';

        if ($scope === 'address' && ! filter_var($target, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ungültige E-Mail-Adresse: '.$target);
        }
        if ($scope === 'domain' && ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $target)) {
            throw new RuntimeException('Ungültige Domain: '.$target);
        }

        [$certPem, $keyPem] = $this->extract($raw, $password);

        if ($type === 'own' && $keyPem === null) {
            throw new RuntimeException('Eigene Zertifikate benötigen den privaten Schlüssel (PEM mit KEY-Block oder .p12/.pfx).');
        }
        if ($type === 'partner') {
            $keyPem = null; // Fremd-Zertifikate: niemals Schlüssel speichern
        }

        $x509 = openssl_x509_read($certPem);
        if ($x509 === false) {
            throw new RuntimeException('Zertifikat konnte nicht gelesen werden.');
        }

        if ($keyPem !== null) {
            $pkey = openssl_pkey_get_private($keyPem)
                ?: throw new RuntimeException('Privater Schlüssel konnte nicht gelesen werden.');
            if (! openssl_x509_check_private_key($x509, $pkey)) {
                throw new RuntimeException('Der private Schlüssel passt nicht zu diesem Zertifikat.');
            }
        }

        $info = openssl_x509_parse($x509);
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');
        if ($fingerprint === false) {
            throw new RuntimeException('Fingerprint konnte nicht berechnet werden.');
        }

        if ($existing = SmimeCertificate::where('fingerprint', $fingerprint)->first()) {
            throw new RuntimeException("Dieses Zertifikat ist bereits vorhanden (#{$existing->id}, {$existing->target}).");
        }

        return SmimeCertificate::create([
            'type' => $type,
            'scope' => $scope,
            'target' => $target,
            'cert_pem' => $certPem,
            'key_pem' => $keyPem !== null ? Crypt::encryptString($keyPem) : null,
            'subject' => mb_substr($this->dnToString($info['subject'] ?? []), 0, 512),
            'issuer' => mb_substr($this->dnToString($info['issuer'] ?? []), 0, 512),
            'valid_from' => isset($info['validFrom_time_t']) ? Carbon::createFromTimestamp($info['validFrom_time_t']) : null,
            'valid_until' => isset($info['validTo_time_t']) ? Carbon::createFromTimestamp($info['validTo_time_t']) : null,
            'fingerprint' => $fingerprint,
            'source' => $source,
        ]);
    }

    /**
     * Erkennt PEM / PKCS#12 / DER und liefert [certPem, keyPem|null].
     */
    private function extract(string $raw, ?string $password): array
    {
        // PEM (mehrere CERTIFICATE-Blöcke = Leaf + CA-Kette, alles behalten)
        if (str_contains($raw, '-----BEGIN')) {
            if (! preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $raw, $cms)) {
                throw new RuntimeException('PEM-Datei enthält keinen CERTIFICATE-Block.');
            }
            $cm = [implode("\n", $cms[0])];
            $key = null;
            if (preg_match('/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/s', $raw, $km)) {
                $key = $km[0];
                if (str_contains($key, 'ENCRYPTED') || $password) {
                    $pkey = openssl_pkey_get_private($key, (string) $password)
                        ?: throw new RuntimeException('Privater Schlüssel verschlüsselt — Passwort fehlt oder ist falsch.');
                    openssl_pkey_export($pkey, $key);
                }
            }

            return [$cm[0], $key];
        }

        // PKCS#12 (Zwischenzertifikate/Kette mitnehmen)
        $bundle = [];
        if (@openssl_pkcs12_read($raw, $bundle, (string) $password)) {
            $chain = implode("\n", $bundle['extracerts'] ?? []);

            return [trim($bundle['cert']."\n".$chain), $bundle['pkey'] ?? null];
        }

        // DER
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($raw), 64, "\n").'-----END CERTIFICATE-----'."\n";
        if (@openssl_x509_read($pem) !== false) {
            return [$pem, null];
        }

        // PKCS#12 mit falschem Passwort von "kein Zertifikat" unterscheiden
        if (str_starts_with($raw, "\x30\x82")) {
            throw new RuntimeException('P12/PFX-Datei erkannt, aber das Passwort ist falsch oder fehlt.');
        }

        throw new RuntimeException('Dateiformat nicht erkannt (unterstützt: PEM, DER, PKCS#12). Bei .p12/.pfx bitte das Passwort angeben.');
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
}
