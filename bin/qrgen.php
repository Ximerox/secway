<?php

/**
 * Standalone-QR-Generator, bewusst als eigener Prozess.
 *
 * Grund: endroid/qr-code (GD-Backend) erzeugt zwar ein korrektes PNG, löst
 * aber beim PHP-Shutdown einen Segfault aus. In-Process würde das mail:ingest
 * (Exit 139 statt 0) und FPM-Worker abschießen. Hier gekapselt: das PNG wird
 * VOR dem Absturz vollständig geschrieben, der Aufrufer ignoriert den
 * Exit-Code und liest die Datei.
 *
 * Aufruf: php bin/qrgen.php <size> <outfile>   (QR-Text kommt über STDIN)
 */

require __DIR__.'/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

$size = max(50, min(600, (int) ($argv[1] ?? 150)));
$out = $argv[2] ?? null;
if ($out === null) {
    fwrite(STDERR, "Ausgabedatei fehlt\n");
    exit(2);
}

$text = stream_get_contents(STDIN);
if (trim((string) $text) === '') {
    fwrite(STDERR, "leerer QR-Text\n");
    exit(2);
}

$png = (new Builder(writer: new PngWriter(), data: $text, size: $size, margin: 8))->build()->getString();
file_put_contents($out, $png);
