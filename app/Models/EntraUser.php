<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntraUser extends Model
{
    protected $guarded = [];

    protected $casts = [
        'proxy_addresses' => 'array',
        'group_ids' => 'array',
        'raw' => 'array',
        'account_enabled' => 'boolean',
        'classify_enabled' => 'boolean',
        'signature_client_enabled' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /** Findet den Datensatz zu einer Absenderadresse (mail, UPN oder SMTP-Alias). */
    public static function forSender(string $email): ?self
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $user = self::whereRaw('LOWER(mail) = ?', [$email])->first()
            ?? self::whereRaw('LOWER(upn) = ?', [$email])->first();
        if ($user) {
            return $user;
        }

        // proxyAddresses enthält Einträge wie "SMTP:adresse@domain" (Aliase klein "smtp:")
        return self::whereRaw('JSON_SEARCH(LOWER(proxy_addresses), \'one\', ?) IS NOT NULL', ['smtp:'.$email])->first();
    }

    /**
     * Attribut-Werte für Signatur-Platzhalter (deutsche Schlüssel = Platzhalternamen
     * in den Vorlagen, z.B. {{vorname}}).
     */
    public function placeholderData(): array
    {
        return [
            'vorname' => $this->given_name,
            'nachname' => $this->surname,
            'name' => $this->display_name,
            'position' => $this->job_title,
            'abteilung' => $this->department,
            'firma' => $this->company_name,
            'buero' => $this->office_location,
            'telefon' => $this->business_phone,
            'mobil' => $this->mobile_phone,
            'fax' => $this->fax_number,
            'strasse' => $this->street_address,
            'plz' => $this->postal_code,
            'ort' => $this->city,
            'email' => $this->mail ?: $this->upn,
        ];
    }
}
