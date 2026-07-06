<?php
/*
 * Erzeugt die Add-in-Icons (SecWay: blauer Grund, weißer Briefumschlag) in den
 * von Office benötigten Größen. Ausgabeverzeichnis als Argument.
 *   php make-icons.php /pfad/zu/public/addin
 */
$dir = rtrim($argv[1] ?? __DIR__.'/assets', '/');
@mkdir($dir, 0755, true);

foreach ([16, 32, 64, 80, 128] as $s) {
    $img = imagecreatetruecolor($s, $s);
    imagealphablending($img, true);
    $blue = imagecolorallocate($img, 0x1d, 0x4e, 0x89);
    $white = imagecolorallocate($img, 0xff, 0xff, 0xff);
    imagefilledrectangle($img, 0, 0, $s, $s, $blue);

    // weißer Briefumschlag (Rechteck) mittig
    $l = (int) round($s * 0.24);
    $r = (int) round($s * 0.76);
    $t = (int) round($s * 0.32);
    $b = (int) round($s * 0.66);
    imagefilledrectangle($img, $l, $t, $r, $b, $white);

    // blaue Umschlag-Klappe (V) — Linienstärke skaliert mit der Größe
    imagesetthickness($img, max(1, (int) round($s / 32)));
    imageline($img, $l, $t, (int) (($l + $r) / 2), (int) round($t + ($b - $t) * 0.55), $blue);
    imageline($img, $r, $t, (int) (($l + $r) / 2), (int) round($t + ($b - $t) * 0.55), $blue);

    imagepng($img, $dir."/icon-{$s}.png");
    imagedestroy($img);
    echo "icon-{$s}.png\n";
}
