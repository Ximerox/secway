<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class PostfixQueueService
{
    /** Lösch-Anforderungen für den root-Helfer (www-data darf kein postsuper). */
    private const DELETE_REQUEST_FILE = 'queue-delete.req';

    /** Aktuelle Postfix-Warteschlange (postqueue -j: eine JSON-Zeile pro Mail). */
    public function list(): array
    {
        $items = [];
        foreach (preg_split('/\n+/', trim($this->run(['/usr/sbin/postqueue', '-j']))) as $line) {
            $j = json_decode($line, true);
            if (! is_array($j) || empty($j['queue_id'])) {
                continue;
            }
            $items[] = [
                'id' => $j['queue_id'],
                'queue' => $j['queue_name'] ?? '',
                'arrival' => isset($j['arrival_time']) ? Carbon::createFromTimestamp($j['arrival_time']) : null,
                'size' => (int) ($j['message_size'] ?? 0),
                'sender' => $j['sender'] ?? '',
                'recipients' => collect($j['recipients'] ?? [])->pluck('address')->all(),
                'reason' => collect($j['recipients'] ?? [])->pluck('delay_reason')->filter()->unique()->implode(' | '),
            ];
        }

        return $items;
    }

    /** Sofortigen Zustellversuch für eine einzelne Mail anstoßen. */
    public function flush(string $id): void
    {
        $this->assertQueueId($id);
        $this->run(['/usr/sbin/postqueue', '-i', $id]);
    }

    /** Zustellversuch für die gesamte Warteschlange anstoßen. */
    public function flushAll(): void
    {
        $this->run(['/usr/sbin/postqueue', '-f']);
    }

    /**
     * Löschung anfordern; ausgeführt wird sie (innerhalb ~1 Minute) vom
     * root-Cron mgw-queue-helper.sh, der die IDs erneut validiert.
     */
    public function requestDelete(string $id): void
    {
        $this->assertQueueId($id);
        file_put_contents(
            storage_path('app/'.self::DELETE_REQUEST_FILE),
            $id."\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /** Angeforderte, noch nicht ausgeführte Löschungen. */
    public function pendingDeletes(): array
    {
        $file = storage_path('app/'.self::DELETE_REQUEST_FILE);

        return is_file($file) ? array_values(array_filter(array_map('trim', file($file)))) : [];
    }

    private function assertQueueId(string $id): void
    {
        // Kurzformat: Hex in Großschreibung (enable_long_queue_ids = no)
        if (! preg_match('/^[A-F0-9]{6,20}$/', $id)) {
            abort(422, 'Ungültige Queue-ID.');
        }
    }

    private function run(array $cmd): string
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return '';
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out;
    }
}
