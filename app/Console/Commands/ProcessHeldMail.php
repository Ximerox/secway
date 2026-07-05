<?php

namespace App\Console\Commands;

use App\Models\HeldMessage;
use App\Services\SmimeInboundService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Verarbeitet die Quarantäne (Scheduler, alle 15 Minuten):
 *  - erneuter Entschlüsselungsversuch (Zertifikat könnte nachgereicht sein)
 *  - nach Ablauf der Frist unverändert zustellen — nichts bleibt liegen
 */
class ProcessHeldMail extends Command
{
    protected $signature = 'mail:process-held';

    protected $description = 'Zurückgehaltene Mails erneut entschlüsseln bzw. nach Fristablauf zustellen';

    public function handle(SmimeInboundService $service): int
    {
        foreach (HeldMessage::where('status', 'held')->orderBy('id')->get() as $held) {
            try {
                $expired = now()->greaterThanOrEqualTo($held->hold_until);
                if ($service->retryHeld($held, $expired, 'auto_timeout')) {
                    $this->info("#{$held->id} freigegeben ({$held->release_action})");
                }
            } catch (\Throwable $e) {
                // Einzelfehler dürfen die übrigen Vorgänge nicht blockieren
                Log::error('Quarantäne-Verarbeitung fehlgeschlagen', [
                    'held_id' => $held->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
