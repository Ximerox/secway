<?php

namespace App\Mail;

use App\Models\SecureMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Hinweis an den (internen) Absender: Die Portal-Nachricht ist abgelaufen und
 * wurde gelöscht, ohne dass alle Empfänger sie abgerufen haben. Enthält bewusst
 * keinen Nachrichteninhalt (der ist zu diesem Zeitpunkt gelöscht).
 */
class UnreadNoticeMail extends Mailable
{
    /** @param string[] $unread Empfänger, die nicht abgerufen haben */
    public function __construct(
        public SecureMessage $msg,
        public array $unread,
    ) {}

    public function headers(): Headers
    {
        return new Headers(text: ['X-MGW-Notification' => (string) config('mailgateway.notification_marker')]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nicht abgerufen: '.mb_strimwidth((string) ($this->msg->subject ?: '(ohne Betreff)'), 0, 120, '…'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.unread_notice_html',
            text: 'mail.unread_notice_text',
            with: [
                'subject' => $this->msg->subject ?: '(ohne Betreff)',
                'sentAt' => $this->msg->created_at,
                'expiredAt' => $this->msg->expires_at,
                'unread' => $this->unread,
            ],
        );
    }
}
