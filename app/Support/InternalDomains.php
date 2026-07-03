<?php

namespace App\Support;

use App\Models\Setting;

class InternalDomains
{
    /** @return string[] interne Domains (lowercase) */
    public static function list(): array
    {
        $raw = (string) Setting::get('internal_domains', config('mailgateway.internal_domains'));

        return array_values(array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', $raw),
        )));
    }

    public static function isInternal(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && in_array($domain, self::list(), true);
    }
}
