<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1f2937;font-size:15px;line-height:1.6;">
    <div style="background:#1d4e89;color:#ffffff;border-radius:10px 10px 0 0;padding:18px 24px;">
        <strong style="font-size:17px;">SecWay · Mail abgesichert zugestellt</strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px;padding:24px;">
        <p>Ihre ausgehende Mail wurde von der automatischen Datenschutz-Prüfung als
            <strong>schutzbedürftig</strong> eingestuft. Sie wurde deshalb nicht unverschlüsselt
            versendet, sondern <strong>{{ $methodLabel }}</strong> zugestellt.</p>
        <table style="font-size:14px;border-collapse:collapse;margin:14px 0;">
            <tr><td style="padding:3px 12px 3px 0;color:#6b7280;">Betreff</td><td>{{ $mailSubject }}</td></tr>
            <tr><td style="padding:3px 12px 3px 0;color:#6b7280;vertical-align:top;">Empfänger</td><td>{{ implode(', ', $recipients) }}</td></tr>
        </table>
        <p>Für Sie und die Empfänger ist nichts weiter zu tun — die Nachricht ist bereits sicher
            unterwegs. Diese Mail dient nur Ihrer Information.</p>
        <p style="color:#6b7280;font-size:13px;margin-top:20px;">Wenn Sie sicher sind, dass die Mail
            keine schutzbedürftigen Daten enthielt, können Sie sie beim nächsten Mal wie gewohnt senden;
            bei Rückfragen wenden Sie sich an Ihre IT.</p>
    </div>
</div>
