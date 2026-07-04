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

        $groupIds = preg_split('/[\s,]+/', (string) Setting::get('entra_sync_groups', ''), -1, PREG_SPLIT_NO_EMPTY);
        $enabledOnly = Setting::getBool('entra_sync_enabled_only', true);
        $excludes = preg_split('/[\s,]+/', (string) Setting::get('entra_sync_exclude', 'HealthMailbox*, DiscoverySearchMailbox*'), -1, PREG_SPLIT_NO_EMPTY);

        try {
            if ($groupIds !== []) {
                // Gruppenmodus: Vereinigung der (transitiven) Benutzer-Mitglieder.
                // Bewusst OHNE accountEnabled-Filter — freigegebene Postfächer sind
                // technisch deaktivierte Konten; die Gruppen definieren den Umfang.
                $all = [];
                foreach ($groupIds as $gid) {
                    foreach ($graph->groupMembers($gid) as $u) {
                        $all[$u['id']] = $u;
                    }
                }
                $all = array_values($all);
                $mode = count($groupIds).' Gruppe(n)';
            } else {
                $all = $graph->users();
                if ($enabledOnly) {
                    $all = array_values(array_filter($all, fn ($u) => ! empty($u['accountEnabled'])));
                }
                $mode = 'alle Benutzer'.($enabledOnly ? ' (nur aktivierte)' : '');
            }
        } catch (Throwable $e) {
            Log::error('entra:sync: Abruf fehlgeschlagen: '.$e->getMessage());
            $this->error('Abruf fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $fetched = count($all);

        // Ausschlussmuster (Wildcards, case-insensitiv) gegen UPN und Mail
        if ($excludes !== []) {
            $all = array_values(array_filter($all, function ($u) use ($excludes) {
                foreach ($excludes as $pattern) {
                    foreach ([$u['userPrincipalName'] ?? '', $u['mail'] ?? ''] as $value) {
                        if ($value !== '' && fnmatch(strtolower($pattern), strtolower($value))) {
                            return false;
                        }
                    }
                }

                return true;
            }));
        }
        $excluded = $fetched - count($all);

        // Nur Konten mit Mailadresse — ohne Adresse gibt es keinen Absender.
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

        // Nicht mehr im Umfang enthaltene Konten aufräumen — aber nie alles
        // löschen, wenn Graph nichts liefert (Schutz vor Fehlkonfiguration).
        $deleted = 0;
        if (count($seen) > 0) {
            $deleted = EntraUser::whereNotIn('entra_id', $seen)->delete();
        }

        Setting::set('entra_last_sync', now()->toDateTimeString());

        $this->info(sprintf(
            'Quelle %s: %d Benutzer synchronisiert (%d neu, %d aktualisiert, %d entfernt; %d per Muster ausgeschlossen, %d ohne Mailadresse übersprungen).',
            $mode, count($seen), $created, $updated, $deleted, $excluded, count($all) - count($withMail)
        ));

        return self::SUCCESS;
    }
}
