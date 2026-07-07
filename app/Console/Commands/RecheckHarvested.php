<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\SmimeCertificate;
use App\Services\SmimeChainValidator;
use Illuminate\Console\Command;

/**
 * Prüft inaktive geerntete Zertifikate erneut (täglich im Scheduler):
 * Mit dem aktuellen S/MIME-Root-Store und AIA-Nachladen lässt sich die
 * Kette oft nachträglich validieren — dann wird das Zertifikat automatisch
 * aktiviert und die Kette am Zertifikat hinterlegt. Selbstsignierte/private
 * CAs bestehen die Prüfung nie und bleiben zur manuellen Freigabe stehen.
 */
class RecheckHarvested extends Command
{
    protected $signature = 'smime:recheck-harvested';

    protected $description = 'Kettenprüfung inaktiver geernteter Zertifikate wiederholen und ggf. aktivieren';

    public function handle(SmimeChainValidator $validator): int
    {
        $candidates = SmimeCertificate::where('source', 'harvested')
            ->where('active', false)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->get();

        foreach ($candidates as $cert) {
            $blocks = $cert->pemBlocks();
            if ($blocks === []) {
                continue;
            }
            $result = $validator->validate($blocks[0], array_slice($blocks, 1));
            if (! $result['trusted']) {
                $this->line("#{$cert->id} {$cert->target}: Kette weiterhin nicht validierbar (manuelle Freigabe nötig).");

                continue;
            }

            $cert->update([
                'active' => true,
                'cert_pem' => trim(implode("\n", [$blocks[0], ...$result['chain']]))."\n",
            ]);
            AuditEvent::log('cert_chain_validated', details: [
                'id' => $cert->id,
                'target' => $cert->target,
                'chain_added' => count($result['chain']),
            ]);
            $this->info("#{$cert->id} {$cert->target}: Kette validiert — aktiviert.");
        }

        return self::SUCCESS;
    }
}
