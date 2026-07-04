<?php

namespace App\Services;

use App\Models\EntraUser;
use App\Models\SignatureTemplate;
use App\Support\InternalDomains;
use ZBateson\MailMimeParser\Message;

/**
 * Fügt ausgehenden Mails serverseitig Signaturen an (CodeTwo-Ersatz).
 *
 * Arbeitet auf der rohen MIME-Nachricht: Signatur wird in den HTML- und
 * Text-Teil eingefügt (vor der zitierten Historie), Bilder kommen als
 * CID-Inline-Anhänge mit. Ein unsichtbarer HTML-Marker kennzeichnet eigene
 * Signaturen, damit sie nie doppelt angehängt werden (skip) bzw. beim
 * erneuten Durchlauf ersetzt werden können (replace). Marker innerhalb der
 * zitierten Historie werden bewusst ignoriert — zitierten Text fassen wir
 * nie an.
 */
class SignatureMailService
{
    private const MARKER_RE = '~<!--SECWAY-SIG[^>]*-->.*?<!--/SECWAY-SIG-->~is';

    /** Muster, an denen im HTML die zitierte Historie beginnt. */
    private const HTML_QUOTE_PATTERNS = [
        '~<div[^>]{0,40}id=["\']appendonsend~i',                    // Outlook Web
        '~<div[^>]{0,40}class=["\']gmail_quote~i',                  // Gmail
        '~<hr[^>]{0,40}id=["\']stopSpelling~i',                     // Outlook (alt)
        '~<div[^>]{0,200}border-top:\s*solid~i',                    // Outlook Desktop (Trennlinie)
        '~<blockquote~i',                                           // Apple Mail u.a.
        '~-----\s*Ursprüngliche Nachricht\s*-----~iu',
        '~-----\s*Original(?:\s|-)Message\s*-----~i',
    ];

    /** Muster, an denen im Text-Teil die zitierte Historie beginnt. */
    private const TEXT_QUOTE_PATTERNS = [
        '~^-----\s*Ursprüngliche Nachricht\s*-----~imu',
        '~^-----\s*Original(?:\s|-)Message\s*-----~im',
        '~^_{20,}\s*$~m',                                           // Outlook-Trennlinie
        '~^Am .{5,120} schrieb .{2,120}:$~mu',                      // Gmail (deutsch)
        '~^>~m',                                                    // klassische Zitatzeile
    ];

    public function __construct(private SignatureRenderer $renderer) {}

    /**
     * Wendet die passenden Vorlagen auf eine rohe Mail an.
     *
     * @return array{raw: string, applied: array<int, string>, replaced: bool, skipped: ?string}
     */
    public function apply(string $raw, string $sender, array $recipients): array
    {
        $none = fn (?string $reason = null) => ['raw' => $raw, 'applied' => [], 'replaced' => false, 'skipped' => $reason];

        $user = EntraUser::forSender($sender);
        if (! $user) {
            return $none('Absender nicht im Entra-Cache');
        }

        $direction = collect($recipients)->contains(fn ($r) => ! InternalDomains::isInternal($r))
            ? 'external' : 'internal';

        $templates = SignatureTemplate::applicable($user, $direction);
        if ($templates->isEmpty()) {
            return $none(); // kein Treffer = kein Protokoll-Rauschen
        }

        $message = Message::from($raw, false);

        if ($reason = $this->skipReason($message)) {
            return $none($reason);
        }

        $htmlPart = $message->getHtmlPart();
        $textPart = $message->getTextPart();
        $html = $htmlPart?->getContent();
        $text = $textPart?->getContent();

        if (($html === null || trim($html) === '') && ($text === null || trim($text) === '')) {
            return $none('kein Text-/HTML-Inhalt');
        }

        // Signaturen rendern (in Regel-Reihenfolge), Bilder einsammeln
        $skipMode = $templates->first()->existing_mode === 'skip';
        $sigHtml = '';
        $sigText = '';
        $images = [];
        foreach ($templates as $t) {
            $parts = $this->renderer->forMail($t, $user);
            $sigHtml .= "\n<!--SECWAY-SIG t{$t->id}-->\n".$parts['html']."\n<!--/SECWAY-SIG-->\n";
            $sigText .= ($sigText !== '' ? "\n\n" : '').$parts['text'];
            $images += $parts['images'];
        }

        $replaced = false;

        if ($html !== null && trim($html) !== '') {
            $quotePos = $this->firstMatchPos($html, self::HTML_QUOTE_PATTERNS) ?? $this->htmlFallbackPos($html);

            // Vorhandene eigene Signaturen NUR im neuen Textbereich beachten
            $ownBlocks = $this->markerBlocksBefore($html, $quotePos);
            if ($ownBlocks !== [] && $skipMode) {
                return $none('Signatur bereits vorhanden (Vorlage steht auf „überspringen")');
            }
            if ($ownBlocks !== []) {
                $html = $this->removeBlocks($html, $ownBlocks);
                $replaced = true;
                $quotePos = $this->firstMatchPos($html, self::HTML_QUOTE_PATTERNS) ?? $this->htmlFallbackPos($html);
            }

            $html = substr($html, 0, $quotePos).$sigHtml.substr($html, $quotePos);
            $htmlPart->setRawHeader('Content-Type', 'text/html; charset=UTF-8');
            $htmlPart->setContent($html, 'UTF-8');
        } elseif ($text !== null) {
            // Nur-Text-Mail: kein Marker möglich — "-- "-Trenner als Heuristik
            $quotePos = $this->firstMatchPos($text, self::TEXT_QUOTE_PATTERNS) ?? strlen($text);
            if ($skipMode && preg_match('/^-- ?$/m', substr($text, 0, $quotePos))) {
                return $none('Signatur bereits vorhanden (Text-Trenner gefunden)');
            }
        }

        if ($text !== null && trim($text) !== '') {
            $pos = $this->firstMatchPos($text, self::TEXT_QUOTE_PATTERNS) ?? strlen($text);
            $insert = rtrim(substr($text, 0, $pos))."\n\n-- \n".$sigText."\n\n";
            $text = $insert.substr($text, $pos);
            $textPart->setRawHeader('Content-Type', 'text/plain; charset=UTF-8');
            $textPart->setContent($text, 'UTF-8');
        }

        // Beim Ersetzen: verwaiste Signatur-Bilder früherer Durchläufe entfernen
        if ($replaced) {
            foreach ($message->getAllAttachmentParts() as $p) {
                $cid = trim((string) $p->getContentId(), '<> ');
                if ($cid !== '' && str_ends_with($cid, '@secway')
                    && ! isset($images[$cid])
                    && ($html === null || ! str_contains($html, 'cid:'.$cid))) {
                    $message->removePart($p);
                }
            }
        }

        // Bilder der neuen Signatur anhängen (falls nicht schon vorhanden)
        $existingCids = array_map(
            fn ($p) => trim((string) $p->getContentId(), '<> '),
            $message->getAllAttachmentParts()
        );
        foreach ($images as $cid => $img) {
            if (in_array($cid, $existingCids, true) || ! is_readable($img['path'])) {
                continue;
            }
            $message->addAttachmentPart(file_get_contents($img['path']), $img['mime'], basename($img['path']), 'inline');
            $parts = $message->getAllAttachmentParts();
            $new = end($parts);
            $new->setRawHeader('Content-ID', '<'.$cid.'>');
        }

        return [
            'raw' => $message->__toString(),
            'applied' => $templates->pluck('name')->all(),
            'replaced' => $replaced,
            'skipped' => null,
        ];
    }

    /** Mails, die grundsätzlich nicht angefasst werden. */
    protected function skipReason(Message $message): ?string
    {
        $ct = strtolower((string) $message->getContentType());
        if (str_contains($ct, 'pkcs7') || str_contains($ct, 'multipart/signed')) {
            return 'S/MIME-signierte oder -verschlüsselte Mail';
        }
        if (str_contains($ct, 'multipart/report')) {
            return 'Zustell-/Lesebericht';
        }
        if (str_contains($ct, 'text/calendar')) {
            return 'Kalendernachricht';
        }
        foreach ($message->getAllParts() as $part) {
            $pct = strtolower((string) $part->getContentType());
            if (str_contains($pct, 'text/calendar') || str_contains($pct, 'application/ics')) {
                return 'Kalendernachricht';
            }
        }
        $auto = strtolower((string) $message->getHeaderValue('Auto-Submitted', ''));
        if ($auto !== '' && $auto !== 'no') {
            return 'automatisch erzeugte Mail (Auto-Submitted: '.$auto.')';
        }

        return null;
    }

    /** Früheste Fundstelle eines der Muster, sonst null. */
    protected function firstMatchPos(string $haystack, array $patterns): ?int
    {
        $min = null;
        foreach ($patterns as $re) {
            if (preg_match($re, $haystack, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $min = $min === null ? $pos : min($min, $pos);
            }
        }

        return $min;
    }

    /** Einfügeposition, wenn keine Zitat-Historie gefunden wurde: vor </body>, sonst ans Ende. */
    protected function htmlFallbackPos(string $html): int
    {
        $pos = stripos($html, '</body>');

        return $pos === false ? strlen($html) : $pos;
    }

    /** Eigene Marker-Blöcke, die VOR $limit beginnen (= im neuen Textbereich). */
    protected function markerBlocksBefore(string $html, int $limit): array
    {
        if (! preg_match_all(self::MARKER_RE, $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        return array_values(array_filter($m[0], fn ($hit) => $hit[1] < $limit));
    }

    /** Entfernt die übergebenen (Offset-)Treffer aus dem String, von hinten nach vorn. */
    protected function removeBlocks(string $html, array $blocks): string
    {
        usort($blocks, fn ($a, $b) => $b[1] <=> $a[1]);
        foreach ($blocks as [$match, $offset]) {
            $html = substr($html, 0, $offset).substr($html, $offset + strlen($match));
        }

        return $html;
    }
}
