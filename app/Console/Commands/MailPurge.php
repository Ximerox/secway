<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\SecureMessage;
use Illuminate\Console\Command;

class MailPurge extends Command
{
    protected $signature = 'mail:purge';

    protected $description = 'Löscht abgelaufene Nachrichten samt verschlüsselter Anhänge';

    public function handle(): int
    {
        $expired = SecureMessage::where('expires_at', '<', now())->get();

        foreach ($expired as $msg) {
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
