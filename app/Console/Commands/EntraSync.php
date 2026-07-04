<?php

namespace App\Console\Commands;

use App\Models\EntraUser;
use App\Models\Setting;
use App\Services\GraphClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class EntraSync extends Command
{
    protected $signature = 'entra:sync';

    protected $description = 'Synchronisiert Benutzer aus Entra ID (Microsoft Graph) in den lokalen Cache';

    public function handle(GraphClient $graph): int
    {
        if (! $graph->isConfigured()) {
            $this->warn('Graph ist nicht konfiguriert (GRAPH_TENANT_ID / GRAPH_CLIENT_ID / GRAPH_CLIENT_SECRET in .env).');

            return self::FAILURE;
        }

        try {
            $all = $graph->users();
        } catch (Throwable $e) {
            Log::error('entra:sync: Abruf fehlgeschlagen: '.$e->getMessage());
            $this->error('Abruf fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        // Nur echte Postfach-Konten (mit Mailadresse) — Geräte-/Servicekonten fallen raus.
        $withMail = array_values(array_filter($all, fn ($u) => ! empty($u['mail'])));

        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($withMail as $u) {
            $row = EntraUser::updateOrCreate(['entra_id' => $u['id']], [
                'upn' => $u['userPrincipalName'] ?? '',
                'mail' => $u['mail'],
                'display_name' => $u['displayName'] ?? null,
                'given_name' => $u['givenName'] ?? null,
                'surname' => $u['surname'] ?? null,
                'job_title' => $u['jobTitle'] ?? null,
                'department' => $u['department'] ?? null,
                'company_name' => $u['companyName'] ?? null,
                'office_location' => $u['officeLocation'] ?? null,
                'business_phone' => $u['businessPhones'][0] ?? null,
                'mobile_phone' => $u['mobilePhone'] ?? null,
                'fax_number' => $u['faxNumber'] ?? null,
                'street_address' => $u['streetAddress'] ?? null,
                'postal_code' => $u['postalCode'] ?? null,
                'city' => $u['city'] ?? null,
                'country' => $u['country'] ?? null,
                'proxy_addresses' => $u['proxyAddresses'] ?? [],
                'account_enabled' => (bool) ($u['accountEnabled'] ?? true),
                'raw' => $u,
                'synced_at' => now(),
            ]);
            $row->wasRecentlyCreated ? $created++ : $updated++;
            $seen[] = $u['id'];
        }

        // Entfernte Konten aufräumen — aber nie alles löschen, wenn Graph leer liefert.
        $deleted = 0;
        if (count($seen) > 0) {
            $deleted = EntraUser::whereNotIn('entra_id', $seen)->delete();
        }

        Setting::set('entra_last_sync', now()->toDateTimeString());

        $this->info(sprintf(
            '%d Benutzer synchronisiert (%d neu, %d aktualisiert, %d entfernt; %d ohne Mailadresse übersprungen).',
            count($seen), $created, $updated, $deleted, count($all) - count($withMail)
        ));

        return self::SUCCESS;
    }
}
