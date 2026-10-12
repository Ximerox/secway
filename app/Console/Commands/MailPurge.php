<?php

namespace App\Console\Commands;

use App\Mail\UnreadNoticeMail;
use App\Models\AuditEvent;
use App\Models\SecureMessage;
use App\Models\Setting;
use App\Support\InternalDomains;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailPurge extends Command
{
    protected $signature = 'mail:purge';

    protected $description = 'Löscht abgelaufene Nachrichten samt verschlüsselter Anhänge';

    public function handle(): int
    {
        $expired = SecureMessage::where('expires_at', '<', now())->with('recipients')->get();
        $notify = Setting::getBool('notify_sender_unread', (bool) config('mailgateway.notify_sender_unread'));

        foreach ($expired as $msg) {
            // Absender informieren, wenn Empfänger nicht abgerufen haben (vor dem
            // Löschen; ein Versandfehler verhindert die Löschung nicht).
            $unread = $msg->recipients->whereNull('first_viewed_at')->pluck('email')->values()->all();
            if ($notify && $unread !== [] && InternalDomains::isInternal((string) $msg->sender_email)) {
                try {
                    Mail::to($msg->sender_email)->send(new UnreadNoticeMail($msg, $unread));
                    AuditEvent::log('sender_notified_unread', $msg, details: ['sender' => $msg->sender_email, 'unread' => $unread]);
                } catch (Throwable $e) {
                    Log::error("mail:purge: Hinweis an {$msg->sender_email} fehlgeschlagen: ".$e->getMessage());
                }
            }
            foreach ($msg->attachments as $att) {
                @unlink($att->disk_path);
            }
            @rmdir($msg->storageDir());
            AuditEvent::log('purged', details: [
                'message_id' => $msg->id,
                'sender' => $msg->sender_email,
                'created_at' => (string) $msg->created_at,
            ]);
            $msg->delete();
        }

        $this->info($expired->count().' abgelaufene Nachrichten gelöscht.');

        return self::SUCCESS;
    }
}
