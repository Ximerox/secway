<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1f2937;font-size:15px;line-height:1.6;">
    <div style="background:#1d4e89;color:#ffffff;border-radius:10px 10px 0 0;padding:18px 24px;">
        <strong style="font-size:17px;">{{ \App\Models\Setting::operator() }} · Sichere Nachricht</strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px;padding:24px;">
        <p>Guten Tag,</p>
        <p>Ihre sichere Nachricht <strong>„{{ $subject }}“</strong> vom {{ $sentAt->format('d.m.Y H:i') }} wurde
            am {{ $expiredAt->format('d.m.Y') }} automatisch gelöscht. Folgende Empfänger haben sie <strong>nicht abgerufen</strong>:</p>
        <ul>
            @foreach ($unread as $email)
                <li>{{ $email }}</li>
            @endforeach
        </ul>
        <p>Falls die Information noch benötigt wird, senden Sie die Nachricht bitte erneut (Betreff wieder mit dem Kennzeichen für sicheren Versand)
            oder nehmen Sie auf anderem Weg Kontakt auf.</p>
        <p style="font-size:13px;color:#6b7280;margin-top:20px;">Diese Benachrichtigung wurde automatisch erstellt.</p>
    </div>
</div>
