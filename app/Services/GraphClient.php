<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimaler Microsoft-Graph-Client (Client-Credentials-Flow).
 * Berechtigung: User.Read.All (Application). Zugangsdaten in config/mailgateway.php (graph.*).
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
        $users = [];
        $url = 'https://graph.microsoft.com/v1.0/users?$select='.self::USER_FIELDS.'&$top=999';

        while ($url) {
            $resp = Http::withToken($this->token())->timeout(60)->get($url);
            if (! $resp->successful()) {
                throw new RuntimeException('Graph /users fehlgeschlagen ('.$resp->status().'): '.substr($resp->body(), 0, 500));
            }
            $users = array_merge($users, $resp->json('value') ?? []);
            $url = $resp->json('@odata.nextLink');
        }

        return $users;
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
