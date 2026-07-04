<?php

namespace App\Services;

use App\Models\EntraUser;
use App\Models\SignatureImage;
use App\Models\SignatureTemplate;
use Illuminate\Support\Facades\Storage;

/**
 * Füllt Signatur-Vorlagen mit Entra-Daten.
 *
 * Platzhalter-Syntax (eigene Engine, bewusst KEIN Blade-Eval von Admin-HTML):
 *   {{vorname}}                     — Wert einsetzen (HTML-escaped)
 *   {{#if telefon}} … {{/if}}       — Block nur, wenn Attribut nicht leer
 *                                     (nicht verschachtelbar)
 */
class SignatureRenderer
{
    public function renderHtml(SignatureTemplate $template, EntraUser $user): string
    {
        return $this->fill($template->html, $user->placeholderData(), escapeHtml: true);
    }

    public function renderText(SignatureTemplate $template, EntraUser $user): string
    {
        if (trim((string) $template->text_body) !== '') {
            return $this->fill($template->text_body, $user->placeholderData(), escapeHtml: false);
        }

        return $this->textFromHtml($this->renderHtml($template, $user));
    }

    /**
     * Render für den Versand: Bild-URLs (/admin/sig-img/{id}) werden zu cid:-Referenzen,
     * die zugehörigen Dateien kommen als Inline-Anhänge mit.
     *
     * @return array{html: string, text: string, images: array<string, array{path: string, mime: string}>}
     */
    public function forMail(SignatureTemplate $template, EntraUser $user): array
    {
        $html = $this->renderHtml($template, $user);
        $images = [];

        $html = preg_replace_callback('~src=["\'][^"\']*?/admin/sig-img/(\d+)["\']~i', function ($m) use (&$images) {
            $img = SignatureImage::find((int) $m[1]);
            if (! $img) {
                return $m[0];
            }
            $images[$img->cid()] = ['path' => Storage::path($img->path), 'mime' => $img->mime];

            return 'src="cid:'.$img->cid().'"';
        }, $html);

        return [
            'html' => $html,
            'text' => $this->renderText($template, $user),
            'images' => $images,
        ];
    }

    public function fill(string $template, array $data, bool $escapeHtml = true): string
    {
        // Bedingungsblöcke zuerst (nicht verschachtelbar)
        $out = (string) preg_replace_callback('/\{\{#if\s+([a-z0-9_]+)\}\}(.*?)\{\{\/if\}\}/is', function ($m) use ($data) {
            return trim((string) ($data[strtolower($m[1])] ?? '')) !== '' ? $m[2] : '';
        }, $template);

        // Platzhalter — unbekannte Namen werden zu Leerstring
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($data, $escapeHtml) {
            $value = (string) ($data[strtolower($m[1])] ?? '');

            return $escapeHtml ? e($value) : $value;
        }, $out);
    }

    /** Einfache Text-Ableitung aus HTML (für den text/plain-Teil). */
    public function textFromHtml(string $html): string
    {
        $text = preg_replace('/<(br|BR)\s*\/?\s*>/', "\n", $html);
        $text = preg_replace('/<\/(p|div|tr|table|h[1-6]|li)>/i', "\n", (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', (string) $text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);

        return trim((string) preg_replace('/^[ \t]+|[ \t]+$/m', '', (string) $text));
    }
}
