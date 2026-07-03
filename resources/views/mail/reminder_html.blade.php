<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1f2937;font-size:15px;line-height:1.6;">
    <div style="background:#1d4e89;color:#ffffff;border-radius:10px 10px 0 0;padding:18px 24px;">
        <strong style="font-size:17px;">{{ \App\Models\Setting::operator() }} · Sichere Nachricht</strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px;padding:24px;">
        <p>Guten Tag,</p>
        <p>eine vertrauliche Nachricht von <strong>{{ $senderName }}</strong> ({{ $senderEmail }}) wartet noch auf Ihren Abruf.</p>
        <p style="text-align:center;margin:28px 0;">
            <a href="{{ $url }}" style="background:#1d4e89;color:#ffffff;text-decoration:none;padding:13px 28px;border-radius:8px;font-weight:bold;display:inline-block;">Nachricht abrufen</a>
        </p>
        <p style="font-size:13px;color:#6b7280;">Falls die Schaltfläche nicht funktioniert:<br>
            <a href="{{ $url }}" style="color:#1d4e89;word-break:break-all;">{{ $url }}</a></p>
        <p>Das benötigte Kennwort haben Sie bereits in einer separaten E-Mail erhalten.</p>
        <p style="font-size:13px;color:#6b7280;margin-top:20px;">Die Nachricht ist noch bis zum {{ $expiresAt->format('d.m.Y') }} abrufbar und wird danach automatisch gelöscht.</p>
    </div>
</div>
