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
            <input type="text" wire:model="internal_domains" placeholder="straphael.de">
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

        <button type="submit" class="btn" wire:loading.attr="disabled">Speichern</button>
    </form>
</div>
