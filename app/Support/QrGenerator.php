<?php

namespace App\Support;

use RuntimeException;

/**
 * Erzeugt QR-Code-PNGs in einem isolierten Subprozess (siehe bin/qrgen.php:
 * das GD-Backend segfaultet beim Shutdown, was hier gekapselt bleibt).
 */
class QrGenerator
{
    public function png(string $text, int $size = 150): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('QR-Text ist leer.');
        }
        $size = max(50, min(600, $size));

        $out = tempnam(sys_get_temp_dir(), 'sqr');
        $php = config('mailgateway.php_binary', '/usr/bin/php');
        $script = base_path('bin/qrgen.php');

        $proc = proc_open(
            [$php, $script, (string) $size, $out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($proc)) {
            @unlink($out);
            throw new RuntimeException('QR-Subprozess konnte nicht gestartet werden.');
        }

        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc); // Exit-Code bewusst ignoriert (Shutdown-Segfault)

        $png = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($out);

        if ($png === '' || substr($png, 1, 3) !== 'PNG') {
            throw new RuntimeException('QR-Erzeugung fehlgeschlagen: '.trim((string) $err));
        }

        return $png;
    }
}
