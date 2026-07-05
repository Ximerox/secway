<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1f2937;font-size:15px;line-height:1.6;">
    <div style="background:#b45309;color:#ffffff;border-radius:10px 10px 0 0;padding:18px 24px;">
        <strong style="font-size:17px;">SecWay · Mail zurückgehalten</strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px;padding:24px;">
        <p>Eine eingehende S/MIME-verschlüsselte Mail konnte mit keinem der hinterlegten
            eigenen Zertifikate entschlüsselt werden und wurde <strong>zurückgehalten</strong>:</p>
        <table style="font-size:14px;border-collapse:collapse;margin:14px 0;">
            <tr><td style="padding:3px 12px 3px 0;color:#6b7280;">Von</td><td>{{ $held->sender }}</td></tr>
            <tr><td style="padding:3px 12px 3px 0;color:#6b7280;">An</td><td>{{ implode(', ', $held->recipients) }}</td></tr>
            <tr><td style="padding:3px 12px 3px 0;color:#6b7280;">Betreff</td><td>{{ $held->subject ?: '(ohne Betreff)' }}</td></tr>
            <tr><td style="padding:3px 12px 3px 0;color:#6b7280;">Größe</td><td>{{ number_format($held->size_bytes / 1024, 0, ',', '.') }} KB</td></tr>
            @if ($held->diagnosis)
                <tr><td style="padding:3px 12px 3px 0;color:#6b7280;vertical-align:top;">Benötigt</td><td style="word-break:break-all;">{{ $held->diagnosis }}</td></tr>
            @endif
            <tr><td style="padding:3px 12px 3px 0;color:#6b7280;">Frist</td><td>{{ $held->hold_until->format('d.m.Y H:i') }} Uhr</td></tr>
        </table>
        <p><strong>So geht es weiter:</strong> Laden Sie das passende Zertifikat (mit privatem
            Schlüssel, Typ „eigenes") unter <em>Admin → Zertifikate</em> hoch — die Mail wird dann
            automatisch entschlüsselt und zugestellt (Prüfung alle 15 Minuten, oder sofort über
            die Schaltfläche „Erneut entschlüsseln"). Ohne Zertifikat wird die Mail nach Ablauf
            der Frist unverändert (verschlüsselt) zugestellt.</p>
        <p style="text-align:center;margin:24px 0 8px;">
            <a href="{{ $url }}" style="background:#1d4e89;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:bold;display:inline-block;">Zurückgehaltene Mails öffnen</a>
        </p>
    </div>
</div>
