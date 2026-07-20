<?php

namespace App\Mail;

use App\Models\MessageRecipient;
use App\Models\SecureMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class ReminderMail extends Mailable
{
    public function __construct(
        public SecureMessage $msg,
        public MessageRecipient $recipient,
        public bool $final = false,
    ) {}

    public function headers(): Headers
    {
        return new Headers(text: ['X-MGW-Notification' => 'yes']);
    }

    public function envelope(): Envelope
    {
        $name = $this->msg->sender_name ?: $this->msg->sender_email;

        return new Envelope(
            from: new Address($this->msg->sender_email, $name),
            replyTo: [new Address($this->msg->sender_email)],
            subject: $this->final
                ? 'Letzte Erinnerung: Sichere Nachricht wird bald gelöscht'
                : 'Erinnerung: Sichere Nachricht wartet auf Abruf',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reminder_html',
            text: 'mail.reminder_text',
            with: [
                'url' => url('/m/'.$this->recipient->token),
                'senderName' => $this->msg->sender_name ?: $this->msg->sender_email,
                'senderEmail' => $this->msg->sender_email,
                'expiresAt' => $this->msg->expires_at,
                'final' => $this->final,
            ],
        );
    }
}
