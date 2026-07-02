<?php

namespace App\Console\Commands;

use App\Services\SmimeCertificateService;
use Illuminate\Console\Command;
use Throwable;

class SmimeImport extends Command
{
    protected $signature = 'smime:import {file : Pfad zur Zertifikatsdatei (PEM/DER/P12)}
        {--type=partner : partner oder own}
        {--target= : Empfänger-Domain oder E-Mail-Adresse}
        {--password= : Passwort für .p12/.pfx oder verschlüsselten Schlüssel}';

    protected $description = 'Importiert ein S/MIME-Zertifikat (z.B. Ciphermail-Export) in die Zertifikatsverwaltung';

    public function handle(SmimeCertificateService $service): int
    {
        $file = $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Datei nicht lesbar: {$file}");

            return self::FAILURE;
        }
        $target = (string) $this->option('target');
        if ($target === '') {
            $this->error('--target=<domain oder adresse> ist erforderlich.');

            return self::FAILURE;
        }

        try {
            $cert = $service->import(
                file_get_contents($file),
                (string) $this->option('type'),
                $target,
                $this->option('password') ?: null,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Importiert: #{$cert->id} [{$cert->type}/{$cert->scope}] {$cert->target}");
        $this->line("  Subject: {$cert->subject}");
        $this->line("  Gültig bis: {$cert->valid_until}");

        return self::SUCCESS;
    }
}
