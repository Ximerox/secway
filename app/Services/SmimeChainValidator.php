<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Kettenvalidierung für S/MIME-Zertifikate öffentlicher CAs.
 *
 * Prüft gegen den Mozilla-E-Mail-Root-Store (storage/app/smime-roots.pem,
 * gepflegt von `smime:update-roots`) plus System-TLS-Store und lädt fehlende
 * Zwischenzertifikate über die AIA-Erweiterung des Zertifikats nach — genau
 * wie Online-Prüfdienste. Hintergrund: Der Debian-Systemstore enthält nur
 * TLS-Wurzeln; reine S/MIME-Wurzeln (z. B. D-Trust SBR) fehlen dort, und
 * viele Mailclients liefern die Zwischenzertifikate nicht mit.
 *
 * Selbstsignierte/private CAs bestehen die Prüfung nicht und bleiben bewusst
 * der manuellen Freigabe vorbehalten.
 */
class SmimeChainValidator
{
    private const MAX_AIA_HOPS = 3;

    private const MAX_FETCH_BYTES = 65536;

    /** Vertrauensanker für openssl_x509_checkpurpose/openssl_pkcs7_verify. */
    public static function caInfo(): array
    {
        $ca = [];
        $roots = storage_path('app/smime-roots.pem');
        if (is_file($roots) && filesize($roots) > 10000) {
            $ca[] = $roots;
        }
        $ca[] = '/etc/ssl/certs/ca-certificates.crt';

        return $ca;
    }

    /**
     * @param  string  $leafPem  Absender-Zertifikat
     * @param  string[]  $untrustedPems  mitgelieferte Zusatz-Zertifikate (falls vorhanden)
     * @return array{trusted: bool, chain: string[]} chain = zum Erfolg verwendete Zwischenzertifikate
     */
    public function validate(string $leafPem, array $untrustedPems = []): array
    {
        $chain = array_values($untrustedPems);
        $current = $leafPem;

        for ($hop = 0; $hop <= self::MAX_AIA_HOPS; $hop++) {
            if ($this->check($leafPem, $chain)) {
                return ['trusted' => true, 'chain' => $chain];
            }
            if ($hop === self::MAX_AIA_HOPS) {
                break;
            }
            $issuer = $this->fetchIssuer($current);
            if ($issuer === null || in_array($issuer, $chain, true)) {
                break;
            }
            $chain[] = $issuer;
            $current = $issuer;
        }

        return ['trusted' => false, 'chain' => []];
    }

    private function check(string $leafPem, array $chain): bool
    {
        $untrustedFile = null;
        if ($chain !== []) {
            $untrustedFile = tempnam(sys_get_temp_dir(), 'mgw-chain-');
            file_put_contents($untrustedFile, implode("\n", $chain)."\n");
        }
        try {
            return openssl_x509_checkpurpose($leafPem, X509_PURPOSE_SMIME_SIGN, self::caInfo(), $untrustedFile) === true;
        } finally {
            if ($untrustedFile !== null) {
                @unlink($untrustedFile);
            }
        }
    }

    /** Aussteller-Zertifikat über die AIA-Erweiterung (caIssuers-URL) nachladen. */
    private function fetchIssuer(string $certPem): ?string
    {
        $info = @openssl_x509_parse($certPem);
        $aia = (string) ($info['extensions']['authorityInfoAccess'] ?? '');
        if (! preg_match('/CA Issuers - URI:(\S+)/', $aia, $m)) {
            return null;
        }

        $body = $this->fetchLimited(trim($m[1]));
        if ($body === null) {
            return null;
        }

        // PEM direkt oder DER → PEM
        if (str_contains($body, 'BEGIN CERTIFICATE')) {
            preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $body, $mm);

            return $mm[0] ?? null;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($body), 64)."-----END CERTIFICATE-----";

        return @openssl_x509_read($pem) !== false ? $pem : null;
    }

    /**
     * HTTP-Abruf mit manuell verfolgten Redirects: Die URL stammt aus einem
     * fremden Zertifikat — jede Station wird gegen den SSRF-Schutz geprüft.
     */
    private function fetchLimited(string $url): ?string
    {
        for ($i = 0; $i < 4; $i++) {
            if (! $this->urlAllowed($url)) {
                return null;
            }
            try {
                $resp = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($url);
            } catch (\Throwable) {
                return null;
            }
            if ($resp->redirect()) {
                $url = (string) $resp->header('Location');
                if (! str_starts_with($url, 'http')) {
                    return null;
                }

                continue;
            }
            if (! $resp->ok()) {
                return null;
            }
            $body = $resp->body();

            return ($body !== '' && strlen($body) <= self::MAX_FETCH_BYTES) ? $body : null;
        }

        return null;
    }

    /** SSRF-Schutz: nur http/https auf Standardports zu öffentlich gerouteten Zielen. */
    private function urlAllowed(string $url): bool
    {
        $p = parse_url($url);
        if ($p === false || ! in_array($p['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }
        if (isset($p['port']) && ! in_array($p['port'], [80, 443], true)) {
            return false;
        }
        $host = $p['host'] ?? '';
        if ($host === '') {
            return false;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false; // nicht auflösbar
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
