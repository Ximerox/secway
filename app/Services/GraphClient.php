<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimaler Microsoft-Graph-Client (Client-Credentials-Flow).
 * Berechtigungen: User.Read.All, für Gruppenfilter/-regeln zusätzlich
 * GroupMember.Read.All (jeweils Application).
 * Zugangsdaten in config/mailgateway.php (graph.*).
 */
class GraphClient
{
    /** Benutzerfelder, die für Signatur-Platzhalter benötigt werden. */
    public const USER_FIELDS = 'id,userPrincipalName,mail,displayName,givenName,surname,jobTitle,'
        .'department,companyName,officeLocation,businessPhones,mobilePhone,faxNumber,'
        .'streetAddress,postalCode,city,country,proxyAddresses,accountEnabled';

    public function isConfigured(): bool
    {
        return (bool) (config('mailgateway.graph.tenant_id')
            && config('mailgateway.graph.client_id')
            && config('mailgateway.graph.client_secret'));
    }

    /** Alle Benutzer des Tenants (folgt der Graph-Paginierung). */
    public function users(): array
    {
        return $this->fetchAll(
            'https://graph.microsoft.com/v1.0/users?$select='.self::USER_FIELDS.'&$top=999'
        );
    }

    /**
     * Benutzer-Mitglieder einer Gruppe, inkl. verschachtelter Gruppen (transitiv).
     * Nicht-Benutzer-Objekte (Geräte, Untergruppen selbst) filtert der OData-Cast weg.
     */
    public function groupMembers(string $groupId): array
    {
        return $this->fetchAll(
            'https://graph.microsoft.com/v1.0/groups/'.$groupId
            .'/transitiveMembers/microsoft.graph.user?$select='.self::USER_FIELDS.'&$top=999'
        );
    }

    /** Nur die Objekt-IDs der Benutzer-Mitglieder einer Gruppe (für Regel-Gruppen). */
    public function groupMemberIds(string $groupId): array
    {
        $items = $this->fetchAll(
            'https://graph.microsoft.com/v1.0/groups/'.$groupId
            .'/transitiveMembers/microsoft.graph.user?$select=id&$top=999'
        );

        return array_column($items, 'id');
    }

    /** Alle Gruppen des Tenants (id + Anzeigename), z.B. für Auswahl-Dropdowns. */
    public function groups(): array
    {
        return $this->fetchAll(
            'https://graph.microsoft.com/v1.0/groups?$select=id,displayName&$top=999'
        );
    }

    /** Seitenweiser Abruf einer Graph-Collection. */
    protected function fetchAll(string $url): array
    {
        $items = [];

        while ($url) {
            $resp = Http::withToken($this->token())->timeout(60)->get($url);
            if (! $resp->successful()) {
                throw new RuntimeException('Graph-Abruf fehlgeschlagen ('.$resp->status().'): '.substr($resp->body(), 0, 500));
            }
            $items = array_merge($items, $resp->json('value') ?? []);
            $url = $resp->json('@odata.nextLink');
        }

        return $items;
    }

    /** OAuth2-Token, gecacht bis kurz vor Ablauf (Graph-Token gelten ~60-90 Min). */
    protected function token(): string
    {
        return Cache::remember('graph_access_token', now()->addMinutes(50), function () {
            $tenant = config('mailgateway.graph.tenant_id');
            $resp = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => config('mailgateway.graph.client_id'),
                'client_secret' => config('mailgateway.graph.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);
            if (! $resp->successful() || ! $resp->json('access_token')) {
                throw new RuntimeException('Graph-Token fehlgeschlagen ('.$resp->status().'): '.substr($resp->body(), 0, 300));
            }

            return $resp->json('access_token');
        });
    }
}
