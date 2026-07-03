<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Schlüssel/Wert-Einstellungen, pflegbar über den Admin-Bereich.
 * Fallback-Standardwerte liegen in config/mailgateway.php.
 */
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var array<string, string|null> Prozess-lokaler Cache */
    private static array $memo = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = static::find($key)?->value;
        }

        return self::$memo[$key] ?? $default;
    }

    public static function getBool(string $key, bool $default): bool
    {
        $value = self::get($key);

        return $value === null ? $default : (bool) (int) $value;
    }

    /** Name des Betreibers, wie ihn Empfänger zu sehen bekommen (Portal, Mails). */
    public static function operator(): string
    {
        return (string) self::get('operator_name', config('mailgateway.operator_name'));
    }

    public static function set(string $key, mixed $value): void
    {
        $value = is_bool($value) ? (string) (int) $value : (string) $value;
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$memo[$key] = $value;
    }
}
