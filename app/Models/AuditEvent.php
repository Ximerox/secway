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
        'signature_client' => 'ausgehend',
        'signature_skipped' => 'ausgehend',
        'signature_failed' => 'ausgehend',
        'sent_items_updated' => 'ausgehend',
        'sent_items_failed' => 'ausgehend',
        'passed_through' => 'ausgehend',
        'send_override' => 'ausgehend',
        'llm_secured' => 'ausgehend',
        'llm_flagged' => 'ausgehend',
        'ingest_stored' => 'ausgehend',
        'recipient_notified' => 'ausgehend',
        'password_sent' => 'ausgehend',
        'reminder_sent' => 'ausgehend',
        'reminder_final_sent' => 'ausgehend',
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

    /** Alle Ereignisnamen, die explizit einer Richtung zugeordnet sind. */
    public static function mappedEvents(): array
    {
        return array_keys(self::DIRECTIONS);
    }

    /** Explizit dieser Richtung zugeordnete Ereignisnamen (ohne Präfix-Regel). */
    public static function eventsForDirection(string $direction): array
    {
        return array_keys(array_filter(self::DIRECTIONS, fn ($d) => $d === $direction));
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

    /** Richtungs-Badge fürs Protokoll: [Label mit Pfeil, CSS-Klasse]. */
    public function directionBadge(): array
    {
        return match ($this->direction()) {
            'ausgehend' => ['↗ ausgehend', 'dir-out'],
            'eingehend' => ['↙ eingehend', 'ok'],
            'Portal' => ['Portal', 'warn'],
            'abgewiesen' => ['abgewiesen', 'err'],
            default => [$this->direction(), 'off'],
        };
    }

    /**
     * Krypto-Kennzeichnung fürs Protokoll (ver-/entschlüsselt/signiert):
     * [Label, CSS-Klasse] oder null, wenn das Ereignis nichts Kryptografisches hat.
     */
    public function cryptoBadge(): ?array
    {
        if ($this->event === 'smime_sent') {
            return [($this->details['signed'] ?? false) ? '🔒 verschlüsselt + signiert' : '🔒 verschlüsselt', 'crypt'];
        }
        if ($this->event === 'smime_fallback') {
            return ['🔒 Verschlüsselung fehlgeschlagen → Portal', 'warn'];
        }
        if ($this->event === 'send_override') {
            return ['⚠ ungesichert — trotz Warnung gesendet', 'warn'];
        }
        if ($this->event === 'llm_secured') {
            return [($this->details['method'] ?? '') === 'smime'
                ? '🔒 nachträglich verschlüsselt (KI-Prüfung)'
                : '🔒 nachträglich ins Portal (KI-Prüfung)', 'crypt'];
        }
        if ($this->event === 'llm_flagged') {
            return ['⚠ KI-Prüfung hätte abgesichert (Nur-Log-Modus)', 'warn'];
        }
        if ($this->event === 'cert_harvested') {
            return ['Zertifikat geerntet', 'ok'];
        }
        if ($this->event === 'inbound_processed') {
            $labels = [];
            $class = 'crypt';
            foreach ((array) ($this->details['status'] ?? []) as $s) {
                if (str_starts_with($s, 'decrypted') || str_starts_with($s, 'unwrapped_decrypted')) {
                    $labels[] = '🔓 entschlüsselt';
                } elseif (str_starts_with($s, 'decrypt_failed')) {
                    $labels[] = '🔓 Entschlüsselung fehlgeschlagen';
                    $class = 'err';
                } elseif (str_starts_with($s, 'signature_invalid')) {
                    $labels[] = 'Signatur UNGÜLTIG';
                    $class = 'err';
                } elseif (str_starts_with($s, 'signed_valid')) {
                    $labels[] = '✓ signiert (gültig)';
                } elseif (str_starts_with($s, 'signed_untrusted')) {
                    $labels[] = 'signiert (Kette nicht vertrauenswürdig)';
                    $class = $class === 'crypt' ? 'warn' : $class;
                } elseif (str_starts_with($s, 'signed_cert_expired')) {
                    $labels[] = 'signiert (Zertifikat abgelaufen)';
                    $class = $class === 'crypt' ? 'warn' : $class;
                }
            }

            return $labels === [] ? null : [implode(' · ', array_unique($labels)), $class];
        }

        return null;
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
