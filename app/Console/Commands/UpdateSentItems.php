<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\SentItemsUpdate;
use App\Models\Setting;
use App\Services\GraphClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Message;

/**
 * Ersetzt die Kopie in „Gesendete Elemente" durch die Fassung mit Signatur.
 *
 * Graph kann den Body gesendeter Mails nicht editieren — daher: neue Nachricht
 * mit gleichem Inhalt+Signatur direkt in sentitems anlegen (Draft-Flag und
 * Original-Zeitstempel per Extended Properties setzen), Original löschen.
 * Benötigt die Application-Berechtigung Mail.ReadWrite.
 */
class UpdateSentItems extends Command
{
    private const MAX_ATTEMPTS = 3;

    private const MAX_ATTACHMENT = 3 * 1024 * 1024; // Graph-Limit ohne Upload-Session

    protected $signature = 'mail:update-sent-items';

    protected $description = 'Ersetzt gesendete Mails im Postausgang durch die Fassung mit Signatur (Microsoft Graph)';

    public function handle(GraphClient $graph): int
    {
        $rows = SentItemsUpdate::orderBy('id')->limit(25)->get();
        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        if (! Setting::getBool('sent_items_update', false)) {
            // Funktion wurde zwischenzeitlich abgeschaltet — Reste aufräumen
            $rows->each->cleanup();

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($rows as $row) {
            try {
                if ($this->process($graph, $row)) {
                    $done++;
                }
            } catch (Throwable $e) {
                $this->failure($row, $e->getMessage());
            }
        }

        $this->info("{$done} Postausgang-Aktualisierung(en) durchgeführt, ".SentItemsUpdate::count().' offen.');

        return self::SUCCESS;
    }

    protected function process(GraphClient $graph, SentItemsUpdate $row): bool
    {
        $orig = $graph->findSentItem($row->sender, $row->internet_message_id);
        if ($orig === null) {
            // Kopie liegt evtl. noch nicht im Ordner — später erneut, irgendwann aufgeben
            if ($row->created_at->lt(now()->subMinutes(15))) {
                $this->failure($row, 'Original nicht in „Gesendete Elemente" gefunden', giveUp: true);
            }

            return false;
        }

        $msg = Message::from($row->rawMail(), false);

        $attachments = [];
        foreach ($msg->getAllAttachmentParts() as $part) {
            $content = (string) $part->getContent();
            if ($content === '') {
                continue;
            }
            if (strlen($content) > self::MAX_ATTACHMENT) {
                $this->failure($row, 'Anhang größer 3 MB — Aktualisierung übersprungen', giveUp: true);

                return false;
            }
            $cid = trim((string) $part->getContentId(), '<> ');
            $attachments[] = array_filter([
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $part->getFilename() ?: ($cid !== '' ? $cid : 'anhang.bin'),
                'contentType' => $part->getContentType() ?: 'application/octet-stream',
                'contentBytes' => base64_encode($content),
                'isInline' => $cid !== '',
                'contentId' => $cid !== '' ? $cid : null,
            ], fn ($v) => $v !== null);
        }

        $html = $msg->getHtmlContent();
        $body = $html !== null && trim($html) !== ''
            ? ['contentType' => 'html', 'content' => $html]
            : ['contentType' => 'text', 'content' => (string) $msg->getTextContent()];

        $payload = array_filter([
            'subject' => (string) $msg->getSubject(),
            'body' => $body,
            'from' => ['emailAddress' => ['address' => $row->sender]],
            'toRecipients' => $this->addresses($msg->getHeader(HeaderConsts::TO)),
            'ccRecipients' => $this->addresses($msg->getHeader(HeaderConsts::CC)) ?: null,
            'attachments' => $attachments !== [] ? $attachments : null,
            'singleValueExtendedProperties' => [
                // PR_MESSAGE_FLAGS: MSGFLAG_READ, ohne UNSENT → kein Entwurf
                ['id' => 'Integer 0x0E07', 'value' => '1'],
                // Original-Message-ID erhalten (Threading/Suche)
                ['id' => 'String 0x1035', 'value' => $row->internet_message_id],
                // Original-Zeitstempel erhalten
                ['id' => 'SystemTime 0x0039', 'value' => $orig['sentDateTime']],
                ['id' => 'SystemTime 0x0E06', 'value' => $orig['sentDateTime']],
            ],
        ], fn ($v) => $v !== null);

        $graph->createSentItem($row->sender, $payload);
        $graph->deleteMessage($row->sender, $orig['id']);

        AuditEvent::log('sent_items_updated', details: [
            'sender' => $row->sender,
            'subject' => mb_substr((string) $msg->getSubject(), 0, 200),
            'attachments' => count($attachments),
        ]);
        $row->cleanup();

        return true;
    }

    protected function failure(SentItemsUpdate $row, string $reason, bool $giveUp = false): void
    {
        $row->attempts++;
        $row->last_error = mb_substr($reason, 0, 1000);
        $row->save();

        if ($giveUp || $row->attempts >= self::MAX_ATTEMPTS) {
            Log::warning("mail:update-sent-items: Auftrag {$row->id} ({$row->sender}) aufgegeben: {$reason}");
            AuditEvent::log('sent_items_failed', details: [
                'sender' => $row->sender,
                'reason' => mb_substr($reason, 0, 500),
            ]);
            $row->cleanup();
        }
    }

    protected function addresses($header): array
    {
        if (! $header instanceof AddressHeader) {
            return [];
        }

        $out = [];
        foreach ($header->getAddresses() as $a) {
            $entry = ['address' => $a->getEmail()];
            if ($a->getName()) {
                $entry['name'] = $a->getName();
            }
            $out[] = ['emailAddress' => $entry];
        }

        return $out;
    }
}
