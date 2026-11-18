<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CertificateOrder;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Bestellung eigener S/MIME-Zertifikate bei der PSW Group.
 * Ablauf: Schlüssel + CSR lokal erzeugen → Bestellung (nur CSR) → Status per
 * Scheduler abfragen → ausgestelltes Zertifikat + Kette abholen und mit dem
 * lokalen Schlüssel als eigenes Zertifikat übernehmen.
 */
class CertificateOrderService
{
    public function __construct(private PswApi $api, private SmimeCertificateService $certs) {}

    /** Fehlende Pflichtangaben in der Konfiguration (leer = alles da). */
    public static function missingConfig(): array
    {
        $missing = [];
        if (! PswApi::configured()) {
            $missing[] = 'PSW_CLIENT_ID / PSW_CLIENT_SECRET';
        }
        foreach (['firstname' => 'PSW_CONTACT_FIRSTNAME', 'lastname' => 'PSW_CONTACT_LASTNAME',
            'email' => 'PSW_CONTACT_EMAIL', 'telephone' => 'PSW_CONTACT_PHONE'] as $k => $env) {
            if (blank(config("mailgateway.psw.contact.$k"))) {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    /** Legt die Bestellung an (Schlüssel/CSR) und übermittelt sie an PSW. */
    public function place(string $email, string $productNumber, ?string $by = null): CertificateOrder
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ungültige E-Mail-Adresse: '.$email);
        }
        $products = config('mailgateway.psw.products', []);
        if (! isset($products[$productNumber])) {
            throw new RuntimeException('Unbekanntes Produkt: '.$productNumber);
        }
        if ($missing = self::missingConfig()) {
            throw new RuntimeException('Konfiguration unvollständig: '.implode(', ', $missing));
        }
        if (CertificateOrder::where('email', $email)->whereIn('status', ['pending', 'ordered'])->exists()) {
            throw new RuntimeException("Für {$email} läuft bereits eine Bestellung.");
        }

        [$keyPem, $csrPem] = $this->generateKeyAndCsr($email);

        $order = CertificateOrder::create([
            'email' => $email,
            'product_number' => $productNumber,
            'product_name' => $products[$productNumber],
            'key_pem' => Crypt::encryptString($keyPem),
            'csr_pem' => $csrPem,
            'status' => 'pending',
            'ordered_by' => $by,
        ]);

        $this->submit($order);

        return $order;
    }

    /** RSA-Schlüssel + CSR (CN und E-Mail = Adresse). */
    private function generateKeyAndCsr(string $email): array
    {
        $bits = (int) config('mailgateway.psw.key_bits', 3072);
        $pkey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => max(3072, $bits)])
            ?: throw new RuntimeException('Schlüsselerzeugung fehlgeschlagen: '.openssl_error_string());

        $csr = openssl_csr_new([
            'countryName' => (string) config('mailgateway.psw.country', 'DE'),
            'commonName' => $email,
            'emailAddress' => $email,
        ], $pkey, ['digest_alg' => 'sha256'])
            ?: throw new RuntimeException('CSR-Erzeugung fehlgeschlagen: '.openssl_error_string());

        openssl_pkey_export($pkey, $keyPem);
        openssl_csr_export($csr, $csrPem);

        return [$keyPem, $csrPem];
    }

    /** Übermittelt eine (noch nicht übermittelte) Bestellung an PSW. */
    public function submit(CertificateOrder $order): void
    {
        if ($order->status !== 'pending') {
            throw new RuntimeException('Bestellung wurde bereits übermittelt.');
        }
        $c = config('mailgateway.psw.contact');
        $person = array_filter([
            'firstname' => $c['firstname'],
            'lastname' => $c['lastname'],
            'email' => $c['email'],
            'telephone' => $c['telephone'],
        ]);

        $payload = [
            'items' => [[
                'product' => $this->api->iri('/products/'.$order->product_number),
                'certificateRequest' => [
                    'csrFile' => $order->csr_pem,
                    'csrFields' => [
                        'emailAddress' => $order->email,
                        'countryName' => (string) config('mailgateway.psw.country', 'DE'),
                    ],
                    'approverContact' => $person,
                ],
            ]],
            'orderContact' => array_filter($person + ['organisationName' => $c['organisation'] ?? null]),
            'customerOrder' => 'SecWay-'.$order->id,
        ];

        try {
            $resp = $this->api->post('/orders', $payload);
        } catch (Throwable $e) {
            $order->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
            AuditEvent::log('cert_order_failed', details: ['order' => $order->id, 'email' => $order->email, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }

        $number = $resp['number'] ?? null;
        $order->update([
            'status' => 'ordered',
            'psw_order_number' => $number,
            'order_state' => self::stateCode($resp),
            'last_error' => $number ? null : 'Antwort ohne Bestellnummer: '.mb_substr(json_encode($resp), 0, 500),
            'checked_at' => now(),
        ]);
        AuditEvent::log('cert_ordered', details: [
            'order' => $order->id, 'email' => $order->email, 'product' => $order->product_number,
            'psw_order' => $number, 'by' => $order->ordered_by, 'test' => PswApi::isTestSystem(),
        ]);
    }

    /** Fragt den Stand ab; übernimmt das Zertifikat, sobald es gültig ist. */
    public function poll(CertificateOrder $order): void
    {
        if (! $order->isOpen() || ! $order->psw_order_number) {
            return;
        }

        try {
            $o = $this->api->get('/orders/'.$order->psw_order_number);
            $orderState = self::stateCode($o);
            $update = ['order_state' => $orderState, 'checked_at' => now(), 'last_error' => null];

            if ($orderState === 'cancelled') {
                $order->update($update + ['status' => 'cancelled']);

                return;
            }

            $certNumber = $order->psw_certificate_number;
            if (! $certNumber) {
                $items = PswApi::items($this->api->get('/orders/'.$order->psw_order_number.'/items', ['pagination' => 'false']));
                $certNumber = self::certificateNumber($items[0] ?? []);
                $update['psw_certificate_number'] = $certNumber;
            }
            if (! $certNumber) {
                $order->update($update);

                return;
            }

            $cert = $this->api->get('/certificates/'.$certNumber);
            $update['cert_state'] = $certState = self::stateCode($cert);

            if (in_array($certState, ['new', 'validating', 'unvalidated', 'error', 'paused'], true)) {
                $update['info'] = $this->validationInfo($certNumber);
            }
            $order->update($update);

            if ($certState === 'valid') {
                $this->adopt($order->fresh());
            }
        } catch (Throwable $e) {
            $order->update(['last_error' => mb_substr($e->getMessage(), 0, 2000), 'checked_at' => now()]);
        }
    }

    /** Zertifikat + Kette holen, mit lokalem Schlüssel als eigenes Zertifikat anlegen. */
    private function adopt(CertificateOrder $order): void
    {
        $leaf = $this->api->get('/certificates/'.$order->psw_certificate_number.'/key')['content'] ?? null;
        if (! is_string($leaf) || ! str_contains($leaf, 'BEGIN CERTIFICATE')) {
            throw new RuntimeException('Zertifikat noch nicht abrufbar.');
        }
        $chain = collect(PswApi::items($this->api->get('/certificates/'.$order->psw_certificate_number.'/chain', ['pagination' => 'false'])))
            ->pluck('content')->filter(fn ($p) => is_string($p) && str_contains($p, 'BEGIN CERTIFICATE'))
            ->reject(fn ($p) => trim($p) === trim($leaf))->all();

        $bundle = trim($leaf)."\n".implode("\n", array_map('trim', $chain))."\n".$order->privateKey();
        $smime = $this->certs->import($bundle, 'own', $order->email, null, 'psw');

        $order->update([
            'status' => 'issued',
            'issued_at' => now(),
            'smime_certificate_id' => $smime->id,
            'key_pem' => '', // Schlüssel liegt jetzt (verschlüsselt) beim Zertifikat
            'info' => null,
        ]);
        AuditEvent::log('cert_order_issued', details: [
            'order' => $order->id, 'email' => $order->email, 'cert' => $smime->id,
            'valid_until' => $smime->valid_until?->format('Y-m-d'),
        ]);
    }

    /** Kurzer Hinweis, worauf die Validierung wartet (ohne Links). */
    private function validationInfo(string $certNumber): ?string
    {
        try {
            $d = $this->api->get('/certificates/'.$certNumber.'/validation-data', ['pagination' => 'false']);
        } catch (Throwable) {
            return null;
        }
        $rows = PswApi::items($d) ?: [$d];
        $out = [];
        foreach ($rows as $r) {
            unset($r['_links']);
            $r = array_filter($r, fn ($v) => is_scalar($v) && $v !== '');
            if ($r) {
                $out[] = collect($r)->map(fn ($v, $k) => "$k: $v")->implode(', ');
            }
        }

        return $out ? mb_substr(implode(' | ', $out), 0, 1000) : null;
    }

    public function resendValidationEmail(CertificateOrder $order): void
    {
        $this->api->post('/jobs/certificates/resend-email', ['certificateNumber' => $order->psw_certificate_number]);
    }

    public function cancel(CertificateOrder $order): void
    {
        if ($order->psw_order_number) {
            $this->api->post('/jobs/orders/cancel', ['orderNumber' => $order->psw_order_number]);
        }
        $order->update(['status' => 'cancelled', 'key_pem' => '']);
        AuditEvent::log('cert_order_cancelled', details: ['order' => $order->id, 'email' => $order->email, 'psw_order' => $order->psw_order_number]);
    }

    /** Status-Code aus HAL (eingebettet oder nur als Link). */
    private static function stateCode(array $res): ?string
    {
        $s = $res['_embedded']['state']['code'] ?? ($res['state']['code'] ?? null);
        if (is_string($s)) {
            return $s;
        }
        if (is_string($res['state'] ?? null)) {
            return basename($res['state']);
        }
        $href = $res['_links']['state']['href'] ?? null;

        return is_string($href) ? basename($href) : null;
    }

    private static function certificateNumber(array $item): ?string
    {
        $n = $item['_embedded']['certificate']['number'] ?? null;
        if (is_string($n)) {
            return $n;
        }
        $href = $item['_links']['certificate']['href'] ?? null;

        return is_string($href) ? basename($href) : null;
    }
}
