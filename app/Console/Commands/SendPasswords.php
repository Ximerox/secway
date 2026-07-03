<?php

namespace App\Console\Commands;

use App\Mail\PasswordMail;
use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPasswords extends Command
{
    protected $signature = 'mail:send-passwords';

    protected $description = 'Versendet fällige Kennwort-Mails (zeitversetzt nach der Link-Mail)';

    public function handle(): int
    {
        $due = MessageRecipient::whereNotNull('pending_password')
            ->whereNull('password_sent_at')
            ->where('password_due_at', '<=', now())
            ->with('message')
            ->get();

        $sent = 0;
        foreach ($due as $recipient) {
            // Nachricht bereits gelöscht/abgelaufen → nichts mehr zu senden
            if (! $recipient->message) {
                $recipient->pending_password = null;
                $recipient->save();

                continue;
            }
            try {
                $password = Crypt::decryptString($recipient->pending_password);
                Mail::to($recipient->email)->send(new PasswordMail($recipient->message, $password));
                $recipient->password_sent_at = now();
                $recipient->pending_password = null;
                $recipient->save();
                AuditEvent::log('password_sent', $recipient->message, $recipient);
                $sent++;
            } catch (Throwable $e) {
                Log::error("mail:send-passwords: Empfänger {$recipient->id} fehlgeschlagen: ".$e->getMessage());
            }
        }

        $this->info("{$sent} Kennwort-Mail(s) versendet.");

        return self::SUCCESS;
    }
}
