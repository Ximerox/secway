<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CertificateOrder extends Model
{
    protected $guarded = [];

    protected $hidden = ['key_pem'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function privateKey(): string
    {
        return Crypt::decryptString($this->key_pem);
    }

    public function certificate()
    {
        return $this->belongsTo(SmimeCertificate::class, 'smime_certificate_id');
    }

    /** Noch bei PSW in Arbeit (Status wird abgefragt). */
    public function isOpen(): bool
    {
        return $this->status === 'ordered';
    }

    /** Anzeige: [Text, Badge-Klasse]. */
    public function badge(): array
    {
        return match ($this->status) {
            'pending' => ['nicht übermittelt', 'err'],
            'issued' => ['ausgestellt & übernommen', 'ok'],
            'failed' => ['Fehler', 'err'],
            'cancelled' => ['storniert', 'off'],
            default => [match ($this->cert_state) {
                'validating' => 'wird validiert',
                'valid' => 'ausgestellt, wird übernommen',
                'error' => 'Fehler bei PSW',
                'unvalidated' => 'nicht validiert',
                'paused' => 'pausiert',
                default => match ($this->order_state) {
                    'incomplete' => 'unvollständig (PSW fragt nach)',
                    'processing' => 'in Bearbeitung',
                    default => 'bestellt',
                },
            }, in_array($this->cert_state, ['error', 'unvalidated', 'paused'], true) || $this->order_state === 'incomplete' ? 'warn' : 'off'],
        };
    }
}
