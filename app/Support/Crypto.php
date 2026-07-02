<?php

namespace App\Support;

use RuntimeException;

/**
 * AES-256-GCM-Verschlüsselung mit einem Datenschlüssel pro Nachricht.
 * Format: base64( IV[12] . TAG[16] . CIPHERTEXT )
 */
class Crypto
{
    public static function newKey(): string
    {
        return random_bytes(32);
    }

    public static function encrypt(string $plain, string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen');
        }

        return base64_encode($iv.$tag.$ct);
    }

    public static function decrypt(string $encoded, string $key): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Ungültige verschlüsselte Daten');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen');
        }

        return $plain;
    }
}
