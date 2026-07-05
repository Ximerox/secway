<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** Zuordnung Ereignis → Richtung für die Log-Anzeige. */
    private const DIRECTIONS = [
        'inbound_processed' => 'eingehend',
        'cert_harvested' => 'eingehend',
        'inbound_held' => 'eingehend',
        'held_released' => 'eingehend',
        'held_deleted' => 'System',
        'smime_sent' => 'ausgehend',
        'smime_fallback' => 'ausgehend',
        'signature_applied' => 'ausgehend',
        'signature_skipped' => 'ausgehend',
        'signature_failed' => 'ausgehend',
        'sent_items_updated' => 'ausgehend',
        'sent_items_failed' => 'ausgehend',
        'passed_through' => 'ausgehend',
        'ingest_stored' => 'ausgehend',
        'recipient_notified' => 'ausgehend',
        'password_sent' => 'ausgehend',
        'reminder_sent' => 'ausgehend',
        'unlocked' => 'Portal',
        'unlock_failed' => 'Portal',
        'downloaded' => 'Portal',
        'reply_sent' => 'Portal',
        'reply_rejected' => 'Portal',
        'purged' => 'System',
        'ingest_rejected' => 'abgewiesen',
        'ingest_loop_dropped' => 'abgewiesen',
        'ingest_dropped_bounce' => 'abgewiesen',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function message()
    {
        return $this->belongsTo(SecureMessage::class, 'secure_message_id');
    }

    public function recipient()
    {
        return $this->belongsTo(MessageRecipient::class, 'message_recipient_id');
    }

    /** eingehend / ausgehend / Portal / abgewiesen / System */
    public function direction(): string
    {
        if (isset(self::DIRECTIONS[$this->event])) {
            return self::DIRECTIONS[$this->event];
        }

        return str_starts_with($this->event, 'admin_') || str_starts_with($this->event, 'cert_')
            || str_starts_with($this->event, 'settings_') ? 'System' : '—';
    }

    public function displaySender(): ?string
    {
        return $this->details['sender'] ?? $this->message?->sender_email;
    }

    /** Empfängeradresse(n) als String. */
    public function displayRecipients(): ?string
    {
        if (isset($this->details['recipients']) && is_array($this->details['recipients'])) {
            return implode(', ', $this->details['recipients']);
        }

        return $this->recipient?->email ?? $this->details['email'] ?? null;
    }

    /** Gruppenschlüssel: fasst alle Ereignisse eines Vorgangs (einer Mail) zusammen. */
    public function groupKey(): string
    {
        if ($this->secure_message_id) {
            return 'm'.$this->secure_message_id;
        }
        if (isset($this->details['queue_id'])) {
            return 'q'.$this->details['queue_id'];
        }

        return 'e'.$this->id;
    }

    /** Restliche Detailfelder (ohne die als Spalten gezeigten) für die Detailzeile. */
    public function extraDetails(): array
    {
        $d = $this->details ?? [];
        unset($d['sender'], $d['recipients'], $d['email'], $d['queue_id']);

        return $d;
    }

    public static function log(
        string $event,
        ?SecureMessage $message = null,
        ?MessageRecipient $recipient = null,
        ?string $ip = null,
        ?array $details = null,
    ): void {
        static::create([
            'event' => $event,
            'secure_message_id' => $message?->id,
            'message_recipient_id' => $recipient?->id,
            'ip' => $ip,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
