<?php

namespace App\Mail;

use App\Models\SecureMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class PasswordMail extends Mailable
{
    public function headers(): Headers
    {
        return new Headers(text: ['X-MGW-Notification' => 'yes']);
    }

    public function __construct(
        public SecureMessage $msg,
        public string $password,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->msg->sender_name ?: $this->msg->sender_email;

        return new Envelope(
            from: new Address($this->msg->sender_email, $name),
            replyTo: [new Address($this->msg->sender_email)],
            subject: 'Ihr Kennwort zur sicheren Nachricht',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.password_html',
            text: 'mail.password_text',
            with: [
                'password' => $this->password,
                'senderName' => $this->msg->sender_name ?: $this->msg->sender_email,
            ],
        );
    }
}
