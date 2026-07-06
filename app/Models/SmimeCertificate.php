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

    /** Alle PEM-Blöcke aus cert_pem: erster = Zertifikat selbst, Rest = CA-Kette. */
    public function pemBlocks(): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) $this->cert_pem, $m);

        return $m[0] ?? [];
    }

    /** Geparste Details des Zertifikats für die Anzeige im Admin. */
    public function details(): array
    {
        $blocks = $this->pemBlocks();
        $leaf = $blocks[0] ?? (string) $this->cert_pem;
        $info = @openssl_x509_parse($leaf) ?: [];

        $pub = @openssl_pkey_get_public($leaf);
        $pubInfo = $pub ? openssl_pkey_get_details($pub) : null;
        $keyType = match ($pubInfo['type'] ?? -1) {
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_EC => 'EC',
            OPENSSL_KEYTYPE_DSA => 'DSA',
            default => null,
        };

        return [
            'Inhaber (Subject)' => self::dnToString($info['subject'] ?? []),
            'Aussteller (Issuer)' => self::dnToString($info['issuer'] ?? []),
            'Seriennummer (hex)' => strtoupper((string) ($info['serialNumberHex'] ?? '')),
            'Gültig von' => isset($info['validFrom_time_t']) ? date('d.m.Y H:i', $info['validFrom_time_t']) : null,
            'Gültig bis' => isset($info['validTo_time_t']) ? date('d.m.Y H:i', $info['validTo_time_t']) : null,
            'SHA-256-Fingerprint' => $this->fingerprint,
            'E-Mail/SAN' => $info['extensions']['subjectAltName'] ?? null,
            'Schlüssel' => $pubInfo && $keyType ? $keyType.', '.$pubInfo['bits'].' Bit' : null,
            'Signaturalgorithmus' => $info['signatureTypeSN'] ?? null,
            'Verwendung (Key Usage)' => $info['extensions']['keyUsage'] ?? null,
            'Erw. Verwendung' => $info['extensions']['extendedKeyUsage'] ?? null,
            'CA-Kette hinterlegt' => count($blocks) > 1 ? (count($blocks) - 1).' Zwischen-/Wurzelzertifikat(e)' : 'nein',
            'Privater Schlüssel' => $this->key_pem ? 'vorhanden (verschlüsselt gespeichert)' : 'nicht vorhanden',
        ];
    }

    /** DN-Array aus openssl_x509_parse in lesbaren String ("C=DE, O=…, CN=…"). */
    private static function dnToString(array $dn): string
    {
        $parts = [];
        foreach ($dn as $key => $value) {
            foreach ((array) $value as $v) {
                $parts[] = $key.'='.$v;
            }
        }

        return implode(', ', $parts);
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
