<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
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
