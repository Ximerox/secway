<?php

namespace App\Support;

use RuntimeException;

/**
 * Virenprüfung über den lokalen clamd (clamdscan --fdpass).
 * --fdpass reicht den Dateideskriptor durch, damit clamd die Datei
 * unabhängig von Dateirechten lesen kann (PHP-Uploads liegen mit 0600 in /tmp).
 */
class ClamScanner
{
    /**
     * @return string|null Signaturname bei Fund, null wenn sauber.
     *
     * @throws RuntimeException wenn der Scanner nicht erreichbar ist —
     *                          der Aufrufer muss den Upload dann ablehnen (fail-closed).
     */
    public static function scan(string $path): ?string
    {
        $proc = proc_open(
            ['clamdscan', '--fdpass', '--no-summary', '--', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($proc)) {
            throw new RuntimeException('clamdscan konnte nicht gestartet werden');
        }

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit === 0) {
            return null;
        }
        if ($exit === 1) {
            return preg_match('/:\s*(.+) FOUND/', (string) $out, $m) ? trim($m[1]) : 'unbekannte Signatur';
        }

        throw new RuntimeException('Virenscanner nicht verfügbar: '.trim(($err ?: $out) ?? ''));
    }
}
