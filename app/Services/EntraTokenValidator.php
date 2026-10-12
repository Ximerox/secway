<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Prüft Entra-ID-Zugriffstoken (JWT, RS256), die die Outlook-Add-ins per
 * Office-SSO holt (OfficeRuntime.auth.getAccessToken bzw. NAA).
 *
 * Geprüft werden Signatur (Schlüssel aus dem JWKS des Tenants, x5c-Zertifikat),
 * Aussteller (v1 oder v2), Zielgruppe (Client-ID oder Anwendungs-ID-URI),
 * Tenant, Gültigkeitszeitraum und der Bereich access_as_user. Ohne externe
 * JWT-Bibliothek: openssl_verify genügt für RS256.
 */
class EntraTokenValidator
{
    private const LEEWAY = 120; // Sekunden Uhrenabweichung

    public function isConfigured(): bool
    {
        return $this->tenantId() !== '' && $this->clientId() !== '';
    }

    /**
     * @return array<string, mixed> die Claims des gültigen Tokens
     *
     * @throws RuntimeException bei jedem Prüffehler (Meldung ohne Tokeninhalt)
     */
    public function validate(string $jwt): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Add-in-SSO ist nicht konfiguriert.');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Kein JWT.');
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode($this->b64($h64), true);
        $claims = json_decode($this->b64($p64), true);
        $signature = $this->b64($s64);
        if (! is_array($header) || ! is_array($claims) || $signature === '') {
            throw new RuntimeException('JWT nicht lesbar.');
        }
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new RuntimeException('Nicht unterstützter Token-Algorithmus.');
        }

        $key = $this->publicKey((string) $header['kid']);
        if (openssl_verify("{$h64}.{$p64}", $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Token-Signatur ungültig.');
        }

        $tenant = $this->tenantId();
        $issuers = ["https://login.microsoftonline.com/{$tenant}/v2.0", "https://sts.windows.net/{$tenant}/"];
        if (! in_array($claims['iss'] ?? '', $issuers, true)) {
            throw new RuntimeException('Falscher Token-Aussteller.');
        }
        if (strcasecmp((string) ($claims['tid'] ?? ''), $tenant) !== 0) {
            throw new RuntimeException('Falscher Tenant.');
        }
        $audiences = [$this->clientId(), $this->appIdUri()];
        if (! in_array($claims['aud'] ?? '', $audiences, true)) {
            throw new RuntimeException('Token ist nicht für diese Anwendung ausgestellt.');
        }

        $now = time();
        if (($claims['exp'] ?? 0) < $now - self::LEEWAY || ($claims['nbf'] ?? 0) > $now + self::LEEWAY) {
            throw new RuntimeException('Token abgelaufen oder noch nicht gültig.');
        }

        $scopes = preg_split('/\s+/', (string) ($claims['scp'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (! in_array('access_as_user', $scopes, true)) {
            throw new RuntimeException('Bereich access_as_user fehlt.');
        }
        if (empty($claims['oid'])) {
            throw new RuntimeException('Token ohne Benutzerkennung (oid).');
        }

        return $claims;
    }

    private function publicKey(string $kid): \OpenSSLAsymmetricKey
    {
        $keys = $this->jwks(false);
        if (! isset($keys[$kid])) {
            // Schlüsselrotation: höchstens alle 5 Minuten neu laden
            if (Cache::add('entra_jwks_refetch_lock', 1, 300)) {
                $keys = $this->jwks(true);
            }
        }
        if (! isset($keys[$kid])) {
            throw new RuntimeException('Unbekannter Signaturschlüssel.');
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($keys[$kid], 64, "\n")."-----END CERTIFICATE-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Signaturschlüssel nicht lesbar.');
        }

        return $key;
    }

    /** @return array<string, string> kid => x5c[0] (base64-DER) */
    private function jwks(bool $refresh): array
    {
        $cacheKey = 'entra_jwks_'.$this->tenantId();
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 86400, function () {
            $url = 'https://login.microsoftonline.com/'.$this->tenantId().'/discovery/v2.0/keys';
            $res = Http::timeout(10)->get($url);
            if (! $res->successful()) {
                throw new RuntimeException('JWKS nicht abrufbar (HTTP '.$res->status().').');
            }
            $out = [];
            foreach ($res->json('keys', []) as $k) {
                if (! empty($k['kid']) && ! empty($k['x5c'][0])) {
                    $out[$k['kid']] = $k['x5c'][0];
                }
            }

            return $out;
        });
    }

    private function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    private function tenantId(): string
    {
        return strtolower(trim((string) config('mailgateway.addin_sso.tenant_id')));
    }

    private function clientId(): string
    {
        return strtolower(trim((string) config('mailgateway.addin_sso.client_id')));
    }

    private function appIdUri(): string
    {
        $uri = trim((string) config('mailgateway.addin_sso.app_id_uri'));

        return $uri !== '' ? $uri : 'api://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/'.$this->clientId();
    }
}
