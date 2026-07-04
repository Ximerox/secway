<div>
    <h1>Einstellungen</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif

    <form wire:submit="save">
        <div class="card">
            <h2 style="margin-top:0;">S/MIME</h2>

            <label style="display:flex;align-items:flex-start;gap:10px;font-weight:400;cursor:pointer;">
                <input type="checkbox" wire:model="smime_auto" style="margin-top:3px;">
                <span>
                    <strong>Automatisch verschlüsseln, sobald ein Zertifikat vorhanden ist</strong><br>
                    <span class="muted">Empfänger mit hinterlegtem Zertifikat bekommen ihre Mails immer S/MIME-verschlüsselt — auch ohne Tag im Betreff, ohne dass der Absender etwas tun muss. Wenn deaktiviert, wird nur bei gesetztem Tag verschlüsselt.</span>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;font-weight:400;cursor:pointer;margin-top:14px;">
                <input type="checkbox" wire:model="smime_sign" style="margin-top:3px;">
                <span>
                    <strong>Signieren, wenn verschlüsselt wird</strong><br>
                    <span class="muted">Besitzt der Absender ein eigenes Adress-Zertifikat mit privatem Schlüssel, wird die Nachricht innerhalb der Verschlüsselung signiert (Echtheitsnachweis beim Empfänger). Domain-Zertifikate signieren grundsätzlich nicht.</span>
                </span>
            </label>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Mailfluss</h2>
            <label>Interne Domains (kommagetrennt)</label>
            <input type="text" wire:model="internal_domains" placeholder="example.org">
            @error('internal_domains')<div class="error">{{ $message }}</div>@enderror
            <p class="muted" style="margin-top:6px;">Mails an Empfänger dieser Domains gelten als <strong>eingehend</strong>: Das Gateway entschlüsselt S/MIME mit den eigenen Zertifikaten, prüft Signaturen und erntet Absender-Zertifikate. Alle anderen Empfänger gelten als ausgehend.</p>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Portal</h2>

            <div class="grid2">
                <div>
                    <label>Auslöse-Tag im Betreff</label>
                    <input type="text" wire:model="subject_tag">
                    @error('subject_tag')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Mails mit diesem Tag im Betreff gehen ins Abrufportal, sofern der Empfänger kein S/MIME-Zertifikat hat. Das Tag wird vor der Zustellung aus dem Betreff entfernt.</p>
                </div>
                <div>
                    <label>Aufbewahrung im Portal (Tage)</label>
                    <input type="text" wire:model="retention_days" style="max-width:120px;">
                    @error('retention_days')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Danach werden Nachricht und Anhänge automatisch und unwiederbringlich gelöscht. Gilt für neue Nachrichten ab dem Zeitpunkt der Änderung.</p>
                </div>
                <div>
                    <label>Zeitversatz Kennwort-Mail (Minuten)</label>
                    <input type="text" wire:model="password_delay_minutes" style="max-width:120px;">
                    @error('password_delay_minutes')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Die Kennwort-Mail wird um diese Zeitspanne nach der Link-Mail versendet (0 = beide gleichzeitig). Der Versand erfolgt minutengenau über den Scheduler.</p>
                </div>
                <div>
                    <label>Erinnerung nach (Stunden)</label>
                    <input type="text" wire:model="reminder_after_hours" style="max-width:120px;">
                    @error('reminder_after_hours')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Ruft ein Empfänger seine Nachricht nach dieser Zeit noch nicht ab, erhält er automatisch eine Erinnerung (0 = keine automatische Erinnerung). Manuell lässt sich jederzeit über die Nachrichten-Liste erinnern.</p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Portal-Antworten</h2>

            <label style="display:flex;align-items:flex-start;gap:10px;font-weight:400;cursor:pointer;">
                <input type="checkbox" wire:model="reply_enabled" style="margin-top:3px;">
                <span>
                    <strong>Empfänger dürfen über das Portal antworten</strong><br>
                    <span class="muted">Externe Empfänger können nach dem Entsperren direkt im Portal antworten — mit Text und Dateianhängen. Die Antwort wird dem internen Absender per E-Mail zugestellt. Alle Anhänge werden vor der Zustellung mit ClamAV auf Schadsoftware geprüft.</span>
                </span>
            </label>

            <div class="grid2" style="margin-top:14px;">
                <div>
                    <label>Max. Anhangsgröße pro Antwort (MB)</label>
                    <input type="text" wire:model="reply_max_size_mb" style="max-width:120px;">
                    @error('reply_max_size_mb')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Gesamtgröße aller Dateien einer Antwort. Die Limits von PHP (<code>upload_max_filesize</code>/<code>post_max_size</code>) und nginx (<code>client_max_body_size</code>) müssen mindestens ebenso groß sein — ebenso das Empfangslimit des Mailsystems.</p>
                </div>
                <div>
                    <label>Max. Antworten pro Nachricht</label>
                    <input type="text" wire:model="reply_max_per_message" style="max-width:120px;">
                    @error('reply_max_per_message')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Begrenzt, wie oft ein Empfänger auf dieselbe Portal-Nachricht antworten kann (Missbrauchsschutz — das Portal soll kein freier Mail-Kanal werden).</p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Betreiber &amp; Rechtliches</h2>

            <label>Name des Betreibers</label>
            <input type="text" wire:model="operator_name" style="max-width:320px;">
            @error('operator_name')<div class="error">{{ $message }}</div>@enderror
            <p class="muted" style="margin-top:6px;">Erscheint für Empfänger im Portal-Kopf, im Seitentitel und in den Benachrichtigungs-Mails („… · Sichere Nachricht").</p>

            <label style="margin-top:16px;">Impressum (HTML)</label>
            <textarea wire:model="legal_impressum" rows="10" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;"></textarea>
            @error('legal_impressum')<div class="error">{{ $message }}</div>@enderror

            <label style="margin-top:16px;">Datenschutzerklärung (HTML)</label>
            <textarea wire:model="legal_datenschutz" rows="14" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;"></textarea>
            @error('legal_datenschutz')<div class="error">{{ $message }}</div>@enderror
            <p class="muted" style="margin-top:6px;">Beide Texte werden unverändert auf <a href="{{ url('/impressum') }}" target="_blank">/impressum</a> bzw. <a href="{{ url('/datenschutz') }}" target="_blank">/datenschutz</a> ausgegeben. Erlaubt sind einfache HTML-Elemente wie &lt;h2&gt;, &lt;p&gt;, &lt;a&gt;, &lt;br&gt;, &lt;strong&gt;.</p>
        </div>

        <button type="submit" class="btn" wire:loading.attr="disabled">Speichern</button>
    </form>
</div>
