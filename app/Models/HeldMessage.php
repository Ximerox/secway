<?php

namespace App\Models;

use App\Support\Crypto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Zurückgehaltene eingehende S/MIME-Mail, die mit keinem der eigenen
 * Zertifikate entschlüsselt werden konnte (Quarantäne). Die Roh-Mail liegt
 * verschlüsselt unter storage/app/held und wird beim Freigeben gelöscht —
 * in der Tabelle bleibt nur der Vorgang als Nachweis stehen.
 */
class HeldMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'hold_until' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public static function hold(string $raw, string $sender, array $recipients, ?string $subject, string $diagnosis): self
    {
        $key = Crypto::newKey();
        $dir = storage_path('app/held');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException('Quarantäne-Verzeichnis konnte nicht angelegt werden.');
        }
        $path = $dir.'/held-'.bin2hex(random_bytes(16)).'.bin';
        file_put_contents($path, Crypto::encrypt($raw, $key), LOCK_EX);

        $hours = (int) Setting::get('inbound_hold_hours', config('mailgateway.inbound_hold_hours'));

        return static::create([
            'sender' => $sender,
            'recipients' => array_values($recipients),
            'subject' => $subject !== null ? mb_substr($subject, 0, 500) : null,
            'size_bytes' => strlen($raw),
            'enc_key' => Crypt::encryptString(base64_encode($key)),
            'disk_path' => $path,
            'diagnosis' => mb_substr($diagnosis, 0, 1000) ?: null,
            'hold_until' => now()->addHours(max(1, $hours)),
        ]);
    }

    public function rawContent(): string
    {
        $encoded = file_get_contents($this->disk_path);
        if ($encoded === false) {
            throw new RuntimeException('Quarantäne-Datei fehlt: '.$this->disk_path);
        }

        return Crypto::decrypt($encoded, base64_decode(Crypt::decryptString($this->enc_key)));
    }

    /** Markiert den Vorgang als erledigt und löscht die gespeicherte Roh-Mail. */
    public function release(string $action): void
    {
        @unlink($this->disk_path);
        $this->update([
            'status' => 'released',
            'release_action' => $action,
            'released_at' => now(),
        ]);
    }
}
