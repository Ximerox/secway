<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Baut den S/MIME-Root-Store aus Mozillas offizieller CA-Liste (CCADB).
 * Debians ca-certificates enthält nur Wurzeln mit TLS-Vertrauen; reine
 * E-Mail-Wurzeln (z. B. D-Trust SBR, diverse S/MIME-only-CAs) fehlen dort.
 * Läuft wöchentlich im Scheduler; bei Fehlern bleibt der alte Store bestehen.
 */
class UpdateSmimeRoots extends Command
{
    protected $signature = 'smime:update-roots';

    protected $description = 'Mozilla-E-Mail-Root-Store (CCADB) nach storage/app/smime-roots.pem aktualisieren';

    private const CCADB_URL = 'https://ccadb.my.salesforce-sites.com/mozilla/IncludedCACertificateReportPEMCSV';

    public function handle(): int
    {
        $tmpCsv = tempnam(sys_get_temp_dir(), 'ccadb-');

        try {
            $resp = Http::timeout(120)->withOptions(['sink' => $tmpCsv])->get(self::CCADB_URL);
            if (! $resp->ok()) {
                $this->error('CCADB-Download fehlgeschlagen: HTTP '.$resp->status());

                return self::FAILURE;
            }

            $fh = fopen($tmpCsv, 'r');
            $header = fgetcsv($fh);
            if ($header === false) {
                $this->error('CCADB-CSV leer.');

                return self::FAILURE;
            }
            $trustIdx = array_search('Trust Bits', $header, true);
            $pemIdx = array_search('PEM Info', $header, true);
            $distrustIdx = array_search('Distrust for S/MIME After Date', $header, true);
            if ($trustIdx === false || $pemIdx === false) {
                $this->error('CCADB-CSV-Format unerwartet (Spalten nicht gefunden) — alter Store bleibt.');

                return self::FAILURE;
            }

            $out = '';
            $count = 0;
            while (($row = fgetcsv($fh)) !== false) {
                if (stripos((string) ($row[$trustIdx] ?? ''), 'Email') === false) {
                    continue;
                }
                // Von Mozilla für S/MIME zurückgezogene Wurzeln überspringen
                if ($distrustIdx !== false) {
                    $d = trim((string) ($row[$distrustIdx] ?? ''));
                    if ($d !== '' && strtotime($d) !== false && strtotime($d) < time()) {
                        continue;
                    }
                }
                $pem = trim((string) ($row[$pemIdx] ?? ''), "' \t\r\n");
                if (! str_contains($pem, 'BEGIN CERTIFICATE') || @openssl_x509_read($pem) === false) {
                    continue;
                }
                $out .= $pem."\n";
                $count++;
            }
            fclose($fh);

            // Plausibilität: Mozillas E-Mail-Store hat weit über 50 Wurzeln.
            if ($count < 50) {
                $this->error("Nur $count Wurzeln extrahiert — unplausibel, alter Store bleibt.");

                return self::FAILURE;
            }

            $target = storage_path('app/smime-roots.pem');
            file_put_contents($target.'.tmp', $out, LOCK_EX);
            rename($target.'.tmp', $target);
            $this->info("smime-roots.pem aktualisiert: $count E-Mail-Wurzeln.");

            return self::SUCCESS;
        } finally {
            @unlink($tmpCsv);
        }
    }
}
