<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class SecureMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** Entschlüsselter Datenschlüssel (32 Byte) dieser Nachricht. */
    public function dataKey(): string
    {
        return base64_decode(Crypt::decryptString($this->enc_key));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function storageDir(): string
    {
        return storage_path('app/messages/'.$this->id);
    }
}
