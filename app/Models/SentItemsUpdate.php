<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Message;

/**
 * Wartender Sent-Items-Update-Auftrag: die signierte Fassung einer Mail
 * soll die Kopie im Ordner „Gesendete Elemente" des Absenders ersetzen.
 * Abarbeitung: mail:update-sent-items (Scheduler, minütlich).
 */
class SentItemsUpdate extends Model
{
    protected $guarded = [];

    /** Legt einen Auftrag an; die Roh-Mail wird verschlüsselt zwischengespeichert. */
    public static function queueFor(string $sender, Message $parsed, string $raw): ?self
    {
        $imid = trim((string) $parsed->getHeaderValue(HeaderConsts::MESSAGE_ID, ''));
        if ($imid === '') {
            return null;
        }

        $dir = storage_path('app/siu');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $row = self::create(['sender' => strtolower($sender), 'internet_message_id' => $imid]);
        $path = $dir.'/'.$row->id.'.eml.enc';
        file_put_contents($path, Crypt::encryptString($raw));
        chmod($path, 0640);
        $row->update(['raw_path' => $path]);

        return $row;
    }

    public function rawMail(): string
    {
        return Crypt::decryptString((string) file_get_contents($this->raw_path));
    }

    /** Auftrag inkl. Zwischendatei entfernen. */
    public function cleanup(): void
    {
        if ($this->raw_path && is_file($this->raw_path)) {
            unlink($this->raw_path);
        }
        $this->delete();
    }
}
