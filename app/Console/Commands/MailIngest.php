<?php

namespace App\Console\Commands;

use App\Mail\PasswordMail;
use App\Mail\SecureLinkMail;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use App\Models\SecureMessage;
use App\Models\Setting;
use App\Models\SmimeCertificate;
use App\Services\SmimeInboundService;
use App\Services\SmimeMailService;
use App\Support\Crypto;
use App\Support\InternalDomains;
use App\Support\RawMail;
use App\Support\SubjectTag;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Message;

class MailIngest extends Command
{
    protected $signature = 'mail:ingest {queue_id} {sender} {recipients*}';

    protected $description = 'Nimmt eine E-Mail von Postfix (pipe) entgegen und legt sie im Portal ab';

    // Exit-Codes nach sysexits(3), die der Postfix-pipe-Daemon auswertet
    private const EX_OK = 0;
    private const EX_UNAVAILABLE = 69;
    private const EX_TEMPFAIL = 75;

    public function handle(): int
    {
        try {
            return $this->process();
        } catch (Throwable $e) {
            Log::error('mail:ingest fehlgeschlagen: '.$e->getMessage(), ['exception' => $e]);

            return self::EX_UNAVAILABLE;
        }
    }

    private function process(): int
    {
        $queueId = (string) $this->argument('queue_id');
        $sender = strtolower(trim((string) $this->argument('sender')));
        $recipients = array_map(fn ($r) => strtolower(trim($r)), $this->argument('recipients'));

        $raw = stream_get_contents(STDIN);
        if ($raw === false || $raw === '') {
            Log::error("mail:ingest {$queueId}: leere Nachricht auf STDIN");

            return self::EX_UNAVAILABLE;
        }

        // Bounces (leerer Envelope-Sender kommt von Postfix als MAILER-DAEMON an) still verwerfen
        if (! str_contains($sender, '@')) {
            AuditEvent::log('ingest_dropped_bounce', details: ['queue_id' => $queueId]);

            return self::EX_OK;
        }

        $parsed = Message::from($raw, false);

        // Schleifen-Bremse: eigene Benachrichtigungsmails niemals erneut verarbeiten
        if ($parsed->getHeaderValue('X-MGW-Notification') !== null) {
            AuditEvent::log('ingest_loop_dropped', details: ['queue_id' => $queueId, 'sender' => $sender]);
            Log::warning("mail:ingest {$queueId}: eigene Benachrichtigung zurückerhalten (Transportregel prüfen!) — verworfen");

            return self::EX_OK;
        }

        $secret = (string) config('mailgateway.ingest_secret');
        $given = (string) $parsed->getHeaderValue(config('mailgateway.secret_header'), '');
        if ($secret === '' || ! hash_equals($secret, $given)) {
            // TEMPFAIL statt Verwerfen: seit alle ausgehenden Mails hier durchlaufen,
            // wäre stilles Verwerfen bei einem Konfigurationsfehler Mailverlust.
            // Die Mail bleibt in der Postfix-Queue, das Monitoring alarmiert.
            $reason = $given === '' ? 'Header fehlt' : 'Header falsch (beginnt mit "'.substr($given, 0, 8).'…", Länge '.strlen($given).')';
            AuditEvent::log('ingest_rejected', details: ['queue_id' => $queueId, 'sender' => $sender, 'reason' => $reason]);
            Log::warning("mail:ingest {$queueId}: Auth-{$reason} (Absender {$sender}) — Zustellung verzögert (TEMPFAIL)");

            return self::EX_TEMPFAIL;
        }

        // Empfänger aufteilen (Verhalten über Admin-Einstellungen steuerbar):
        //   Zertifikat vorhanden + (Auto-Verschlüsselung AN oder Tag gesetzt) → S/MIME
        //   sonst, Tag gesetzt                                               → Portal
        //   sonst                                                            → unverändert durchleiten
        $hasTag = SubjectTag::contains((string) $parsed->getSubject());
        $autoEncrypt = Setting::getBool('smime_auto', true);

        $inboundRcpts = [];
        $smime = [];
        $portalRcpts = [];
        $passRcpts = [];
        foreach ($recipients as $rcpt) {
            if (! filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                Log::warning("mail:ingest {$queueId}: ungültige Empfängeradresse übersprungen: {$rcpt}");

                continue;
            }
            // Empfänger in interner Domain = eingehende Mail (entschlüsseln/prüfen/ernten)
            if (InternalDomains::isInternal($rcpt)) {
                $inboundRcpts[] = $rcpt;

                continue;
            }
            $cert = SmimeCertificate::forRecipient($rcpt);
            if ($cert && ($autoEncrypt || $hasTag)) {
                $smime[$rcpt] = $cert;
            } elseif ($hasTag) {
                $portalRcpts[] = $rcpt;
            } else {
                $passRcpts[] = $rcpt;
            }
        }

        if ($inboundRcpts !== []) {
            $status = app(SmimeInboundService::class)->process($raw, $sender, $inboundRcpts);
            AuditEvent::log('inbound_processed', details: [
                'queue_id' => $queueId,
                'sender' => $sender,
                'recipients' => $inboundRcpts,
                'status' => $status,
                'content_type' => mb_substr((string) RawMail::findHeader(RawMail::split($raw)[0], 'content-type'), 0, 300),
            ]);
        }

        if ($passRcpts !== []) {
            app(SmimeMailService::class)->passThrough($raw, $sender, $passRcpts);
            AuditEvent::log('passed_through', details: [
                'queue_id' => $queueId, 'sender' => $sender, 'recipients' => $passRcpts,
            ]);
        }

        if ($smime !== []) {
            try {
                app(SmimeMailService::class)->encryptAndSend($raw, $sender, $smime, $parsed);
                AuditEvent::log('smime_sent', details: [
                    'queue_id' => $queueId,
                    'sender' => $sender,
                    'recipients' => array_keys($smime),
                    'signed' => SmimeCertificate::ownForAddress($sender) !== null,
                ]);
            } catch (Throwable $e) {
                // Fallback: bei S/MIME-Problemen niemals unverschlüsselt senden, sondern Portal
                Log::error("mail:ingest {$queueId}: S/MIME fehlgeschlagen — Portal-Fallback: ".$e->getMessage());
                AuditEvent::log('smime_fallback', details: [
                    'queue_id' => $queueId,
                    'recipients' => array_keys($smime),
                    'reason' => mb_substr($e->getMessage(), 0, 500),
                ]);
                $portalRcpts = array_merge($portalRcpts, array_keys($smime));
            }
        }

        if ($portalRcpts !== []) {
            $msg = $this->findOrStoreMessage($queueId, $sender, $raw, $parsed);
            foreach ($portalRcpts as $rcpt) {
                $this->notifyRecipient($msg, $rcpt);
            }
        }

        return self::EX_OK;
    }

    private function findOrStoreMessage(string $queueId, string $sender, string $raw, Message $parsed): SecureMessage
    {
        $existing = SecureMessage::where('queue_id', $queueId)->first();
        if ($existing) {
            return $existing;
        }

        $key = Crypto::newKey();
        $fromHeader = $parsed->getHeader(HeaderConsts::FROM);
        $bodyText = $parsed->getTextContent();
        $bodyHtml = $parsed->getHtmlContent();

        try {
            $msg = SecureMessage::create([
                'queue_id' => $queueId,
                'message_id_header' => mb_substr((string) $parsed->getHeaderValue(HeaderConsts::MESSAGE_ID, ''), 0, 512) ?: null,
                'sender_email' => $sender,
                'sender_name' => $fromHeader?->getPersonName() ?: null,
                'subject' => mb_substr($this->cleanSubject((string) $parsed->getSubject()), 0, 512) ?: null,
                'enc_key' => Crypt::encryptString(base64_encode($key)),
                'body_text' => $bodyText !== null ? Crypto::encrypt($bodyText, $key) : null,
                'body_html' => $bodyHtml !== null ? Crypto::encrypt($bodyHtml, $key) : null,
                'size_bytes' => strlen($raw),
                'expires_at' => now()->addDays((int) Setting::get('retention_days', config('mailgateway.retention_days'))),
            ]);
        } catch (QueryException) {
            // Paralleler pipe-Aufruf für dieselbe Queue-ID war schneller
            return SecureMessage::where('queue_id', $queueId)->firstOrFail();
        }

        $dir = $msg->storageDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $count = 0;
        foreach ($parsed->getAllAttachmentParts() as $part) {
            $content = $part->getContent();
            if ($content === null || $content === '') {
                continue;
            }
            $count++;
            $path = $dir.'/att-'.$count.'.bin';
            file_put_contents($path, Crypto::encrypt($content, $key));

            // Inline-Parts (z.B. Signatur-Bilder) erkennen: Content-ID vorhanden
            // und im HTML-Body per cid: referenziert
            $cid = trim((string) $part->getContentId(), '<> ');
            $isInline = $cid !== '' && $bodyHtml !== null && str_contains($bodyHtml, 'cid:'.$cid);

            Attachment::create([
                'secure_message_id' => $msg->id,
                'filename' => mb_substr($part->getFilename() ?: 'anhang-'.$count, 0, 512),
                'mime' => $part->getContentType(),
                'content_id' => $cid ?: null,
                'is_inline' => $isInline,
                'size_bytes' => strlen($content),
                'disk_path' => $path,
            ]);
        }

        AuditEvent::log('ingest_stored', $msg, details: [
            'queue_id' => $queueId,
            'attachments' => $count,
            'size_bytes' => strlen($raw),
        ]);

        return $msg;
    }

    private function notifyRecipient(SecureMessage $msg, string $email): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning("mail:ingest: ungültige Empfängeradresse übersprungen: {$email}");

            return;
        }
        if ($msg->recipients()->where('email', $email)->exists()) {
            return;
        }

        $password = $this->generatePassword();
        $delay = (int) Setting::get('password_delay_minutes', config('mailgateway.password_delay_minutes'));

        $recipient = MessageRecipient::create([
            'secure_message_id' => $msg->id,
            'email' => $email,
            'token' => bin2hex(random_bytes(32)),
            'password_hash' => Hash::make($password),
            'pending_password' => $delay > 0 ? Crypt::encryptString($password) : null,
            'password_due_at' => now()->addMinutes(max(0, $delay)),
        ]);

        Mail::to($email)->send(new SecureLinkMail($msg, $recipient));
        $recipient->notified_at = now();

        // Kennwort sofort oder zeitversetzt (dann übernimmt mail:send-passwords)
        if ($delay <= 0) {
            Mail::to($email)->send(new PasswordMail($msg, $password));
            $recipient->password_sent_at = now();
            $recipient->pending_password = null;
        }
        $recipient->save();

        AuditEvent::log('recipient_notified', $msg, $recipient, details: ['password_delay_min' => max(0, $delay)]);
    }

    private function cleanSubject(string $subject): string
    {
        return SubjectTag::strip($subject);
    }

    private function generatePassword(): string
    {
        // ohne leicht verwechselbare Zeichen (0/O, 1/l/I)
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $blocks = [];
        for ($b = 0; $b < 3; $b++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $blocks[] = $s;
        }

        return implode('-', $blocks);
    }
}
