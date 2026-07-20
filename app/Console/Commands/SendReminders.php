<?php

namespace App\Console\Commands;

use App\Mail\ReminderMail;
use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendReminders extends Command
{
    protected $signature = 'mail:send-reminders';

    protected $description = 'Erinnert Empfänger an noch nicht abgerufene Portalnachrichten';

    public function handle(): int
    {
        $sent = 0;

        // 1) Erinnerung X Stunden NACH Zustellung (falls noch nicht abgerufen).
        $hours = (int) \App\Models\Setting::get('reminder_after_hours', config('mailgateway.reminder_after_hours'));
        if ($hours > 0) {
            $due = MessageRecipient::whereNotNull('password_sent_at')  // vollständig benachrichtigt
                ->whereNull('first_viewed_at')                          // noch nicht abgerufen
                ->whereNull('reminder_sent_at')                         // noch nicht erinnert
                ->where('notified_at', '<=', now()->subHours($hours))
                ->with('message')
                ->get();
            foreach ($due as $recipient) {
                if (! $recipient->message || $recipient->message->isExpired()) {
                    continue;
                }
                $sent += $this->remind($recipient) ? 1 : 0;
            }
        }

        // 2) Letzte Erinnerung X Stunden VOR der automatischen Löschung.
        // Unabhängig von (1): auch wer die erste Erinnerung ignoriert hat, soll
        // eine letzte Chance bekommen. Eigener Zeitstempel → genau einmal.
        $beforeHours = (int) \App\Models\Setting::get('reminder_before_expiry_hours', config('mailgateway.reminder_before_expiry_hours'));
        if ($beforeHours > 0) {
            $dueFinal = MessageRecipient::whereNotNull('password_sent_at')
                ->whereNull('first_viewed_at')
                ->whereNull('final_reminder_sent_at')
                ->whereHas('message', fn ($q) => $q
                    ->where('expires_at', '>', now())                     // noch nicht abgelaufen
                    ->where('expires_at', '<=', now()->addHours($beforeHours))) // läuft bald ab
                ->with('message')
                ->get();
            foreach ($dueFinal as $recipient) {
                if (! $recipient->message || $recipient->message->isExpired()) {
                    continue;
                }
                $sent += $this->remind($recipient, final: true) ? 1 : 0;
            }
        }

        $this->info("{$sent} Erinnerung(en) versendet.");

        return self::SUCCESS;
    }

    /**
     * Versendet eine Erinnerung an einen Empfänger (auch manuell aus dem Admin).
     * $final = true → „letzte Erinnerung vor Löschung" (eigener Zeitstempel/Text).
     */
    public function remind(MessageRecipient $recipient, bool $final = false): bool
    {
        try {
            Mail::to($recipient->email)->send(new ReminderMail($recipient->message, $recipient, $final));
            if ($final) {
                $recipient->final_reminder_sent_at = now();
            } else {
                $recipient->reminder_sent_at = now();
            }
            $recipient->save();
            AuditEvent::log($final ? 'reminder_final_sent' : 'reminder_sent', $recipient->message, $recipient);

            return true;
        } catch (Throwable $e) {
            Log::error("mail:send-reminders: Empfänger {$recipient->id} fehlgeschlagen: ".$e->getMessage());

            return false;
        }
    }
}
