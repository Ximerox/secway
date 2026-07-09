<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SmimeCertificate;
use App\Support\RawMail;
use App\Support\SubjectTag;
use RuntimeException;
use ZBateson\MailMimeParser\Message;

/**
 * Verschlüsselt ausgehende Mails per S/MIME (CMS) und reicht sie an Postfix zurück.
 *
 * Aufbau: sign-then-encrypt. Signiert wird nur, wenn der Absender ein eigenes
 * Adress-Zertifikat mit privatem Schlüssel besitzt (Domain-Zertifikate signieren
 * nicht — die Absenderadresse würde nicht zum Zertifikat passen).
 * Ein CMS-Umschlag kann mehrere Empfänger-Zertifikate tragen, daher genügt
 * ein Versand für alle S/MIME-Empfänger.
 */
class SmimeMailService
{
    /**
     * @param  array<string, SmimeCertificate>  $recipients  Empfängeradresse => Verschlüsselungszertifikat
     */
    public function encryptAndSend(string $raw, string $sender, array $recipients, Message $parsed): void
    {
        $tmpDir = sys_get_temp_dir().'/mgw-smime-'.bin2hex(random_bytes(8));
        if (! mkdir($tmpDir, 0700)) {
            throw new RuntimeException('Temp-Verzeichnis konnte nicht angelegt werden.');
        }

        try {
            $this->process($raw, $sender, $recipients, $parsed, $tmpDir);
        } finally {
            array_map('unlink', glob($tmpDir.'/*') ?: []);
            @rmdir($tmpDir);
        }
    }

    private function process(string $raw, string $sender, array $recipients, Message $parsed, string $tmpDir): void
    {
        [$headerBlock, $body] = RawMail::split($raw);

        // 1) Innere MIME-Entität: nur Content-Header + Body
        file_put_contents($tmpDir.'/inner.eml', $this->buildInnerEntity($headerBlock, $body));
        $current = $tmpDir.'/inner.eml';

        // 2) Signieren (abschaltbar), falls der Absender ein Adress-Zertifikat mit Schlüssel hat
        $signCert = Setting::getBool('smime_sign', true) ? SmimeCertificate::ownForAddress($sender) : null;
        if ($signCert) {
            $blocks = $this->pemBlocks($signCert->cert_pem);
            $chainFile = null;
            if (count($blocks) > 1) {
                // Zwischenzertifikate (CA-Kette) in die Signatur einbetten
                $chainFile = $tmpDir.'/chain.pem';
                file_put_contents($chainFile, implode("\n", array_slice($blocks, 1)));
            }
            $signed = $tmpDir.'/signed.eml';
            // pkcs7 statt cms: openssl_cms_sign kann kein multipart/signed (detached)
            // mit S/MIME-Encoding erzeugen — openssl_pkcs7_sign schon
            $ok = openssl_pkcs7_sign(
                $current,
                $signed,
                $blocks[0],
                $signCert->privateKey(),
                null,
                PKCS7_DETACHED,
                $chainFile,
            );
            if (! $ok) {
                throw new RuntimeException('S/MIME-Signatur fehlgeschlagen: '.(openssl_error_string() ?: 'unbekannt'));
            }
            $current = $signed;
        }

        // 3) Verschlüsseln — ein Umschlag für alle Empfänger-Zertifikate
        $certs = array_map(fn (SmimeCertificate $c) => $this->pemBlocks($c->cert_pem)[0], array_values($recipients));
        $encrypted = $tmpDir.'/encrypted.eml';
        $ok = openssl_cms_encrypt(
            $current,
            $encrypted,
            $certs,
            null,
            0,
            OPENSSL_ENCODING_SMIME,
            OPENSSL_CIPHER_AES_256_CBC,
        );
        if (! $ok) {
            throw new RuntimeException('S/MIME-Verschlüsselung fehlgeschlagen: '.(openssl_error_string() ?: 'unbekannt'));
        }

        // 4) Ursprüngliche Kopfzeilen (ohne Content-*/interne Header) + CMS-Ausgabe
        $final = $this->composeFinal($headerBlock, file_get_contents($encrypted), $parsed);

        // 5) Zurück an Postfix (pickup — läuft nicht durch den Content-Filter)
        RawMail::submit($final, $sender, array_keys($recipients));
    }

    /**
     * Leitet eine Mail unverändert weiter (kein Zertifikat, kein Tag):
     * nur interne Header entfernen und den Schleifen-Marker setzen.
     */
    public function passThrough(string $raw, string $sender, array $recipients): void
    {
        [$headerBlock, $body] = RawMail::split($raw);
        $drop = [
            strtolower((string) config('mailgateway.secret_header')),
            'x-mgw-notification',
            'x-mgw-signed', // interner Marker des Compose-Add-ins, nie zum Empfänger
            'bcc',
        ];
        $keep = [];
        foreach (RawMail::headerLines($headerBlock) as $line) {
            if (! in_array(RawMail::headerName($line), $drop, true)) {
                $keep[] = $line;
            }
        }
        $keep[] = 'X-MGW-Notification: yes';

        // CRLF-normalisiert einspeisen (SMTP-konform; verhindert fehlerhafte
        // Mails, falls der Body gemischte Zeilenenden enthält)
        $message = preg_replace('/\r\n|\r|\n/', "\r\n", implode("\n", $keep)."\n\n".$body);
        RawMail::submit($message, $sender, $recipients);
    }

    /** Innere Entität: Content-Header der Originalmail + Body. */
    private function buildInnerEntity(string $headerBlock, string $body): string
    {
        $keep = [];
        $hasContentType = false;
        foreach (RawMail::headerLines($headerBlock) as $line) {
            $name = RawMail::headerName($line);
            if (in_array($name, ['content-type', 'content-transfer-encoding', 'content-disposition', 'content-id'], true)) {
                $keep[] = $line;
                $hasContentType = $hasContentType || $name === 'content-type';
            }
        }
        if (! $hasContentType) {
            $keep[] = 'Content-Type: text/plain; charset=utf-8';
        }

        return implode("\n", $keep)."\n\n".$body;
    }

    /** Finale Mail: transportrelevante Original-Header + bereinigter Betreff + CMS-Teil. */
    private function composeFinal(string $headerBlock, string $cmsOutput, Message $parsed): string
    {
        $drop = [
            'content-type', 'content-transfer-encoding', 'content-disposition', 'content-id',
            'mime-version', 'subject', 'bcc',
            strtolower((string) config('mailgateway.secret_header')),
            'x-mgw-notification',
            'x-mgw-signed', // interner Marker des Compose-Add-ins, nie zum Empfänger
        ];
        $keep = [];
        foreach (RawMail::headerLines($headerBlock) as $line) {
            if (! in_array(RawMail::headerName($line), $drop, true)) {
                $keep[] = $line;
            }
        }

        $subject = SubjectTag::strip((string) $parsed->getSubject());
        if ($subject !== '') {
            $keep[] = 'Subject: '.mb_encode_mimeheader($subject, 'UTF-8', 'B', "\n");
        }
        // Marker: verhindert, dass die EXO-Transportregel diese Mail erneut zum Gateway routet
        $keep[] = 'X-MGW-Notification: yes';

        return implode("\n", $keep)."\n".$cmsOutput;
    }

    private function submit(string $message, string $sender, array $recipients): void
    {
        $cmd = array_merge(['/usr/sbin/sendmail', '-oi', '-f', $sender], $recipients);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('sendmail konnte nicht gestartet werden.');
        }
        fwrite($pipes[0], $message);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($rc !== 0) {
            throw new RuntimeException("sendmail-Fehler (Exit {$rc}): ".trim((string) $err));
        }
    }

    /** @return string[] alle CERTIFICATE-Blöcke einer PEM-Datei (Leaf zuerst) */
    private function pemBlocks(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);
        if ($m[0] === []) {
            throw new RuntimeException('Zertifikat enthält keinen PEM-Block.');
        }

        return $m[0];
    }
}
