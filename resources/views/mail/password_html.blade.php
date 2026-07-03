<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1f2937;font-size:15px;line-height:1.6;">
    <div style="background:#1d4e89;color:#ffffff;border-radius:10px 10px 0 0;padding:18px 24px;">
        <strong style="font-size:17px;">{{ \App\Models\Setting::operator() }} · Sichere Nachricht</strong>
    </div>
    <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px;padding:24px;">
        <p>Guten Tag,</p>
        <p>mit diesem Kennwort öffnen Sie die vertrauliche Nachricht von <strong>{{ $senderName }}</strong>, zu der Sie in einer separaten E-Mail einen Abruf-Link erhalten haben:</p>
        <p style="text-align:center;margin:26px 0;">
            <span style="font-family:Consolas,Menlo,monospace;font-size:22px;letter-spacing:1px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:12px 22px;display:inline-block;">{{ $password }}</span>
        </p>
        <p style="font-size:13px;color:#6b7280;">Geben Sie das Kennwort niemals an Dritte weiter. Nach mehreren Fehlversuchen wird der Zugriff vorübergehend gesperrt.</p>
    </div>
</div>
