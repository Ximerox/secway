<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SmimeCertificate extends Model
{
    protected $guarded = [];

    protected $hidden = ['key_pem'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /** Entschlüsselter privater Schlüssel (nur type=own). */
    public function privateKey(): ?string
    {
        return $this->key_pem ? Crypt::decryptString($this->key_pem) : null;
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function isUsable(): bool
    {
        return $this->active
            && ! $this->isExpired()
            && ($this->valid_from === null || $this->valid_from->isPast());
    }

    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('active', true)
            ->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }

    /**
     * Bestes Verschlüsselungs-Zertifikat für eine Empfängeradresse:
     * Adress-Zertifikat hat Vorrang vor Domain-Zertifikat.
     */
    public static function forRecipient(string $email): ?self
    {
        $email = strtolower(trim($email));
        $domain = substr(strrchr($email, '@') ?: '', 1);

        return static::usable()->where('type', 'partner')->where('target', $email)->orderByDesc('valid_until')->first()
            ?? static::usable()->where('type', 'partner')->where('target', $domain)->orderByDesc('valid_until')->first();
    }

    /** Eigenes Adress-Zertifikat (mit Schlüssel) eines Absenders — zum Signieren. */
    public static function ownForAddress(string $email): ?self
    {
        return static::usable()->where('type', 'own')->where('scope', 'address')
            ->where('target', strtolower(trim($email)))
            ->whereNotNull('key_pem')
            ->orderByDesc('valid_until')->first();
    }

    /** Aktives eigenes Zertifikat für eine Absender-Domain. */
    public static function ownForDomain(string $domain): ?self
    {
        return static::usable()->where('type', 'own')
            ->where('target', strtolower(trim($domain)))
            ->orderByDesc('valid_until')->first();
    }
}
