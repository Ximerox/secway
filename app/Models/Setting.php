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

    /**
     * Modus der nachgelagerten KI-Prüfung: 'off' | 'log' | 'secure'.
     * Fällt auf den früheren Bool-Schalter llm_review_enabled zurück
     * (an = 'secure'), solange llm_review_mode noch nie gespeichert wurde.
     */
    public static function llmReviewMode(): string
    {
        $mode = self::get('llm_review_mode');
        if (in_array($mode, ['off', 'log', 'secure'], true)) {
            return $mode;
        }

        return self::getBool('llm_review_enabled', false) ? 'secure' : 'off';
    }

    public static function set(string $key, mixed $value): void
    {
        $value = is_bool($value) ? (string) (int) $value : (string) $value;
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$memo[$key] = $value;
    }
}
