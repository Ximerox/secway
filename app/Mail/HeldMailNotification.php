<?php

namespace App\Mail;

use App\Models\HeldMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** Admin-Benachrichtigung: eingehende Mail wegen fehlenden Schlüssels zurückgehalten. */
class HeldMailNotification extends Mailable
{
    public function __construct(public HeldMessage $held) {}

    public function headers(): Headers
    {
        return new Headers(text: ['X-MGW-Notification' => 'yes']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[SecWay] Eingehende Mail zurückgehalten — Zertifikat fehlt',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.held_html',
            text: 'mail.held_text',
            with: [
                'held' => $this->held,
                'url' => url('/admin/zurueckgehalten'),
            ],
        );
    }
}
