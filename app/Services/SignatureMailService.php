<?php

namespace App\Services;

use App\Models\EntraUser;
use App\Models\SignatureTemplate;
use App\Support\InternalDomains;
use App\Support\RawMail;
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

        $out = $message->__toString();

        // Bilder der neuen Signatur anhängen — auf Raw-Ebene, struktur-erhaltend.
        // (zbatesons addAttachmentPart flacht multipart/alternative zu mixed ab,
        // dann zeigen Clients Text- UND HTML-Teil nacheinander an.)
        $newImages = [];
        foreach ($images as $cid => $img) {
            if (! str_contains($out, '<'.$cid.'>') && is_readable($img['path'])) {
                $newImages[$cid] = $img;
            }
        }
        if ($newImages !== []) {
            $out = $this->attachInlineImages($out, $newImages);
        }

        return [
            'raw' => $out,
            'applied' => $templates->pluck('name')->all(),
            'replaced' => $replaced,
            'skipped' => null,
        ];
    }

    /**
     * Hängt Inline-Bilder an, ohne die MIME-Struktur zu zerstören:
     * bestehendes multipart/mixed|related wird ergänzt, alles andere
     * (multipart/alternative, Einzelpart) in multipart/related eingepackt —
     * die Struktur, die Mail-Clients selbst für Inline-Bilder erzeugen.
     */
    protected function attachInlineImages(string $raw, array $images): string
    {
        [$headers, $body] = RawMail::split($raw);
        $ctLine = (string) RawMail::findHeader($headers, 'content-type');

        if (preg_match('~multipart/(mixed|related)~i', $ctLine)
            && preg_match('~boundary=("?)([^";\s]+)\1~i', $ctLine, $m)) {
            $boundary = $m[2];
            $closing = '--'.$boundary.'--';
            $pos = strrpos($body, $closing);
            if ($pos !== false) {
                $parts = '';
                foreach ($images as $cid => $img) {
                    $parts .= $this->imagePartMime($boundary, $cid, $img);
                }

                return $headers."\r\n\r\n".substr($body, 0, $pos).$parts.$closing.substr($body, $pos + strlen($closing));
            }
        }

        // Umschlag: multipart/related um die komplette bisherige Nachricht
        $boundary = '=MGW-REL-'.bin2hex(random_bytes(12));
        $inner = [];
        $outer = [];
        foreach (RawMail::headerLines($headers) as $line) {
            $folded = str_replace("\n", "\r\n", $line);
            if (in_array(RawMail::headerName($line), ['content-type', 'content-transfer-encoding'], true)) {
                $inner[] = $folded;
            } else {
                $outer[] = $folded;
            }
        }
        if ($inner === []) {
            $inner[] = 'Content-Type: text/plain';
        }
        $outer[] = 'Content-Type: multipart/related;'."\r\n\t".'boundary="'.$boundary.'"';

        $out = implode("\r\n", $outer)."\r\n\r\n";
        $out .= '--'.$boundary."\r\n".implode("\r\n", $inner)."\r\n\r\n".$body."\r\n";
        foreach ($images as $cid => $img) {
            $out .= $this->imagePartMime($boundary, $cid, $img);
        }

        return $out.'--'.$boundary."--\r\n";
    }

    protected function imagePartMime(string $boundary, string $cid, array $img): string
    {
        $name = basename($img['path']);

        return '--'.$boundary."\r\n"
            .'Content-Type: '.$img['mime'].'; name="'.$name.'"'."\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            .'Content-Disposition: inline; filename="'.$name.'"'."\r\n"
            .'Content-ID: <'.$cid.'>'."\r\n\r\n"
            .chunk_split(base64_encode((string) file_get_contents($img['path'])), 76, "\r\n");
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
