<?php

namespace App\Support;

use App\Models\Setting;

class SubjectTag
{
    /** Aktuell konfiguriertes Auslöse-Tag (Admin-Einstellung, Fallback aus config). */
    public static function current(): string
    {
        return (string) Setting::get('subject_tag', config('mailgateway.subject_tag'));
    }

    public static function contains(string $subject): bool
    {
        $tag = self::current();

        return $tag !== '' && mb_stripos($subject, $tag) !== false;
    }

    /** Entfernt das Auslöse-Tag (z.B. "[sicher]") aus einem Betreff. */
    public static function strip(string $subject): string
    {
        $tag = self::current();
        if ($tag !== '') {
            $subject = str_ireplace($tag, '', $subject);
        }

        return trim($subject);
    }
}
