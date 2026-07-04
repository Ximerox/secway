<?php

namespace App\Mail;

use App\Models\MessageRecipient;
use App\Models\SecureMessage;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Antwort eines externen Portal-Empfängers, zugestellt an den internen
 * Ursprungs-Absender. Bewusst OHNE Reply-To auf die externe Adresse: eine
 * Outlook-Antwort darauf würde das Gateway-Tag umgehen und unverschlüsselt
 * hinausgehen. Stattdessen enthält der Mailtext einen mailto-Link, der das
 * Betreff-Tag bereits gesetzt hat.
 */
class PortalReplyMail extends Mailable
{
    /** @param array<int, array{path: string, name: string, mime: string}> $files */
    public function __construct(
        public SecureMessage $msg,
        public MessageRecipient $recipient,
        public string $replyText,
        public array $files = [],
    ) {}

    public function headers(): Headers
    {
        return new Headers(text: ['X-MGW-Notification' => 'yes']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'Portal-Antwort · '.$this->recipient->email),
            subject: 'AW: '.($this->msg->subject ?: '(Ohne Betreff)'),
        );
    }

    public function content(): Content
    {
        $tag = (string) Setting::get('subject_tag', config('mailgateway.subject_tag'));

        return new Content(
            view: 'mail.reply_html',
            text: 'mail.reply_text',
            with: [
                'replyText' => $this->replyText,
                'externalEmail' => $this->recipient->email,
                'originalSubject' => $this->msg->subject ?: '(Ohne Betreff)',
                'sentAt' => $this->msg->created_at,
                'mailto' => 'mailto:'.$this->recipient->email
                    .'?subject='.rawurlencode(trim($tag.' AW: '.($this->msg->subject ?: ''))),
                'fileNames' => array_column($this->files, 'name'),
            ],
        );
    }

    public function attachments(): array
    {
        return array_map(
            fn (array $f) => Attachment::fromPath($f['path'])->as($f['name'])->withMime($f['mime']),
            $this->files
        );
    }
}
