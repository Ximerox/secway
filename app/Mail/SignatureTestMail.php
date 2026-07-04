<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;

class SignatureTestMail extends Mailable
{
    /**
     * @param  array<string, array{path: string, mime: string}>  $inlineImages  cid => Datei
     */
    public function __construct(
        public string $htmlBody,
        public array $inlineImages = [],
    ) {
        $this->withSymfonyMessage(function (Email $message) {
            foreach ($this->inlineImages as $cid => $img) {
                $message->embedFromPath($img['path'], $cid, $img['mime']);
            }
        });
    }

    public function headers(): Headers
    {
        // Marker, damit die EXO-Regel Gateway-eigene Mails nie zurückschickt
        return new Headers(text: ['X-MGW-Notification' => 'yes']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SecWay: Signatur-Vorschau');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p style="font-family:Arial,sans-serif;font-size:10pt;color:#666;">'
            .'Dies ist eine Test-Zustellung der Signatur-Vorlage — unterhalb der Linie erscheint die Signatur, '
            .'wie sie Empfängern angehängt würde.</p><hr>'
            .$this->htmlBody);
    }
}
