<?php

namespace App\Mail;

use App\Models\MessageRecipient;
use App\Models\SecureMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class SecureLinkMail extends Mailable
{
    public function headers(): Headers
    {
        return new Headers(text: ['X-MGW-Notification' => 'yes']);
    }

    public function __construct(
        public SecureMessage $msg,
        public MessageRecipient $recipient,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->msg->sender_name ?: $this->msg->sender_email;

        return new Envelope(
            from: new Address($this->msg->sender_email, $name),
            replyTo: [new Address($this->msg->sender_email)],
            subject: "Sichere Nachricht von {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.link_html',
            text: 'mail.link_text',
            with: [
                'url' => url('/m/'.$this->recipient->token),
                'senderName' => $this->msg->sender_name ?: $this->msg->sender_email,
                'senderEmail' => $this->msg->sender_email,
                'expiresAt' => $this->msg->expires_at,
            ],
        );
    }
}
