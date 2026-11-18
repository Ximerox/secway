<?php

namespace App\Console\Commands;

use App\Models\CertificateOrder;
use App\Services\CertificateOrderService;
use App\Services\PswApi;
use Illuminate\Console\Command;

/**
 * Fragt offene Zertifikatsbestellungen bei der PSW Group ab und übernimmt
 * ausgestellte Zertifikate (Scheduler, alle 15 Minuten). Ohne PSW-Zugang: nichts.
 */
class PswPoll extends Command
{
    protected $signature = 'psw:poll';

    protected $description = 'Offene PSW-Zertifikatsbestellungen abfragen und ausgestellte Zertifikate übernehmen';

    public function handle(CertificateOrderService $service): int
    {
        if (! PswApi::configured()) {
            return self::SUCCESS;
        }

        foreach (CertificateOrder::where('status', 'ordered')->get() as $order) {
            $service->poll($order);
            $order->refresh();
            $this->line("#{$order->id} {$order->email}: {$order->status} / {$order->order_state} / {$order->cert_state}"
                .($order->last_error ? ' — '.$order->last_error : ''));
        }

        return self::SUCCESS;
    }
}
