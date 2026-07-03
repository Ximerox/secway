<?php

namespace App\Support;

use RuntimeException;

/**
 * Helfer für die Arbeit mit Roh-Mails (RFC-5322-Text) und die
 * Wiedereinspeisung über den lokalen Postfix (pickup, ohne Content-Filter).
 */
class RawMail
{
    /** @return array{0: string, 1: string} [Header-Block, Body] */
    public static function split(string $raw): array
    {
        if (! preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return [$raw, ''];
        }
        $pos = $m[0][1];

        return [substr($raw, 0, $pos), substr($raw, $pos + strlen($m[0][0]))];
    }

    /** Zerlegt einen Header-Block in logische (entfaltete) Header-Zeilen. */
    public static function headerLines(string $headerBlock): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', rtrim($headerBlock)) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $out !== []) {
                $out[count($out) - 1] .= "\n".$line;
            } else {
                $out[] = $line;
            }
        }

        return $out;
    }

    public static function headerName(string $logicalLine): string
    {
        return strtolower(trim(strtok($logicalLine, ':')));
    }

    /** Liefert die (entfaltete) Header-Zeile eines Namens oder null. */
    public static function findHeader(string $headerBlock, string $name): ?string
    {
        foreach (self::headerLines($headerBlock) as $line) {
            if (self::headerName($line) === strtolower($name)) {
                return $line;
            }
        }

        return null;
    }

    /** Übergibt eine fertige Mail an Postfix (sendmail/pickup). */
    public static function submit(string $message, string $sender, array $recipients): void
    {
        $cmd = array_merge(['/usr/sbin/sendmail', '-oi', '-f', $sender], $recipients);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('sendmail konnte nicht gestartet werden.');
        }
        fwrite($pipes[0], $message);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($rc !== 0) {
            throw new RuntimeException("sendmail-Fehler (Exit {$rc}): ".trim((string) $err));
        }
    }
}
