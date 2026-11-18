<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Schlanker Client für die PSW-Group-API (HAL+JSON, OAuth2 Client Credentials).
 * Bewusst ohne das PSW-SDK, um keine Fremdpakete in den Mailbetrieb zu ziehen.
 */
class PswApi
{
    public static function configured(): bool
    {
        return filled(config('mailgateway.psw.client_id')) && filled(config('mailgateway.psw.client_secret'));
    }

    public static function isTestSystem(): bool
    {
        return str_contains((string) config('mailgateway.psw.api_url'), 'test-api.');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('mailgateway.psw.api_url'), '/');
    }

    /** HAL-Links enthalten den Pfad inkl. "/v1" — auf die Basis-URL abbilden. */
    public function iri(string $path): string
    {
        $basePath = rtrim((string) parse_url($this->baseUrl(), PHP_URL_PATH), '/');

        return $basePath.'/'.ltrim($path, '/');
    }

    private function url(string $path): string
    {
        $basePath = rtrim((string) parse_url($this->baseUrl(), PHP_URL_PATH), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath.'/')) {
            $path = substr($path, strlen($basePath));
        }

        return $this->baseUrl().'/'.ltrim($path, '/');
    }

    private function token(bool $fresh = false): string
    {
        $key = 'psw_token_'.md5($this->baseUrl().config('mailgateway.psw.client_id'));
        if (! $fresh && ($t = Cache::get($key))) {
            return $t;
        }

        $resp = Http::asForm()->timeout(20)
            ->withBasicAuth((string) config('mailgateway.psw.client_id'), (string) config('mailgateway.psw.client_secret'))
            ->post($this->url('/oauth/token'), ['grant_type' => 'client_credentials']);

        if (! $resp->successful() || ! is_string($resp->json('access_token'))) {
            throw new RuntimeException('PSW-Anmeldung fehlgeschlagen (HTTP '.$resp->status().'): '
                .($resp->json('error_description') ?? mb_substr($resp->body(), 0, 200)));
        }

        $ttl = max(60, (int) ($resp->json('expires_in') ?? 3600) - 120);
        Cache::put($key, $resp->json('access_token'), $ttl);

        return $resp->json('access_token');
    }

    private function http(bool $fresh = false): PendingRequest
    {
        return Http::withToken($this->token($fresh))->acceptJson()
            ->withHeaders(['Accept' => 'application/hal+json'])->timeout(30);
    }

    private function send(string $method, string $path, array $data = []): array
    {
        $call = fn (bool $fresh) => $method === 'GET'
            ? $this->http($fresh)->get($this->url($path), $data)
            : $this->http($fresh)->asJson()->post($this->url($path), $data);

        $resp = $call(false);
        if ($resp->status() === 401) {
            $resp = $call(true); // Token abgelaufen/widerrufen
        }

        return $this->decode($resp, $method.' '.$path);
    }

    private function decode(Response $resp, string $what): array
    {
        if (! $resp->successful()) {
            $j = $resp->json();
            $msg = is_array($j)
                ? ($j['detail'] ?? $j['hydra:description'] ?? $j['title'] ?? $j['message'] ?? json_encode($j, JSON_UNESCAPED_UNICODE))
                : $resp->body();
            if (is_array($j) && ! empty($j['violations'])) {
                $msg .= ' — '.collect($j['violations'])->map(fn ($v) => ($v['propertyPath'] ?? '').': '.($v['message'] ?? ''))->implode('; ');
            }
            throw new RuntimeException("PSW {$what}: HTTP {$resp->status()} ".mb_substr((string) $msg, 0, 600));
        }

        return $resp->json() ?? [];
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    public function post(string $path, array $data): array
    {
        return $this->send('POST', $path, $data);
    }

    /** Eingebettete Elemente einer HAL-Collection. */
    public static function items(array $collection): array
    {
        return $collection['_embedded']['item'] ?? [];
    }
}
