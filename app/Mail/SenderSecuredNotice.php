<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Information an den internen Absender: Seine Mail wurde von der nachgelagerten
 * KI-Prüfung als schutzbedürftig eingestuft und deshalb NICHT unverschlüsselt,
 * sondern abgesichert (Portal oder S/MIME) zugestellt. Reine Info, keine
 * Rückfrage — es hängt nichts, die Mail ist bereits sicher unterwegs.
 */
class SenderSecuredNotice extends Mailable
{
    /**
     * @param  array<int,string>  $recipients  externe Empfänger, die abgesichert wurden
     * @param  string  $method  'portal' oder 'smime'
     */
    public function __construct(
        public string $mailSubject,
        public array $recipients,
        public string $method,
    ) {}

    public function headers(): Headers
    {
        return new Headers(text: ['X-MGW-Notification' => 'yes']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[SecWay] Ihre Mail wurde aus Datenschutzgründen abgesichert zugestellt',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.secured_html',
            text: 'mail.secured_text',
            with: [
                'mailSubject' => $this->mailSubject !== '' ? $this->mailSubject : '(ohne Betreff)',
                'recipients' => $this->recipients,
                'method' => $this->method,
                'methodLabel' => $this->method === 'smime' ? 'verschlüsselt (S/MIME)' : 'über das sichere Portal',
            ],
        );
    }
}
