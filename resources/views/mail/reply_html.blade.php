<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1f2937;font-size:15px;line-height:1.6;">
    <div style="background:#1d4e89;color:#ffffff;border-radius:10px 10px 0 0;padding:18px 24px;">
        <strong style="font-size:17px;">{{ \App\Models\Setting::operator() }} · Antwort über das Portal</strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px;padding:24px;">
        <p>Guten Tag,</p>
        <p><strong>{{ $externalEmail }}</strong> hat über das Sicherheitsportal auf Ihre Nachricht
            „{{ $originalSubject }}" vom {{ $sentAt->format('d.m.Y H:i') }} Uhr geantwortet:</p>
        <div style="border-left:3px solid #1d4e89;background:#f8fafc;padding:14px 18px;margin:18px 0;border-radius:0 8px 8px 0;white-space:pre-wrap;">{{ $replyText }}</div>
        @if (count($fileNames))
            <p style="font-size:14px;"><strong>Anhänge ({{ count($fileNames) }}):</strong> {{ implode(', ', $fileNames) }}<br>
                <span style="color:#6b7280;font-size:13px;">Alle Anhänge wurden vor der Zustellung automatisch auf Schadsoftware geprüft.</span></p>
        @endif
        <p style="text-align:center;margin:26px 0 18px;">
            <a href="{{ $mailto }}" style="background:#1d4e89;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:bold;display:inline-block;">Sicher antworten</a>
        </p>
        <p style="font-size:13px;color:#6b7280;">Antworten Sie <strong>nicht</strong> direkt auf diese E-Mail — sie stammt vom Portal, nicht vom Absender.
            Nutzen Sie die Schaltfläche oben: Sie öffnet eine neue E-Mail an {{ $externalEmail }} mit gesetztem
            Sicherheits-Tag im Betreff, damit auch Ihre Antwort wieder geschützt zugestellt wird.</p>
    </div>
</div>
