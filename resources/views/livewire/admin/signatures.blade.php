<div>
    <h1>Signaturblöcke</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="alert err">{{ session('err') }}</div>
    @endif

    <div class="card">
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div>
                <strong>Signaturblock-Modul:</strong>
                @if ($module_enabled) <span class="badge ok">eingeschaltet</span>
                @else <span class="badge off">ausgeschaltet</span>
                @endif
            </div>
            <label style="display:flex; gap:8px; align-items:center; margin:0;">
                <input type="checkbox" wire:model.live="module_enabled" style="width:auto;">
                Mails verarbeiten (aktive Vorlagen anwenden)
            </label>
            <label style="display:flex; gap:8px; align-items:center; margin:0;">
                <input type="checkbox" wire:model.live="sent_items_update" style="width:auto;">
                „Gesendete Elemente" nachträglich aktualisieren
            </label>
        </div>
        <div class="muted" style="margin-top:6px;">
            Die Aktualisierung ersetzt die Kopie im Postausgang des Absenders durch die Fassung mit Signaturblock
            (dauert bis zu ~1 Minute). Benötigt die Graph-Berechtigung <code>Mail.ReadWrite</code> (Application)
            mit Admin-Consent. Mails mit Anhängen über 3 MB werden ausgelassen.
        </div>
    </div>

    <div class="card">
        <div style="display:flex; align-items:center;">
            <h2 style="margin:0;">Vorlagen</h2>
            <a class="btn" style="margin-left:auto;" href="{{ route('admin.signatures.new') }}" wire:navigate>Neuer Signaturblock</a>
        </div>
        <table style="margin-top:12px;">
            <thead><tr><th>Prio</th><th>Name</th><th>Status</th><th>Absender</th><th>Empfänger</th><th>Zeitraum</th><th></th></tr></thead>
            <tbody>
            @forelse ($templates as $t)
                <tr>
                    <td>{{ $t->priority }}</td>
                    <td><strong>{{ $t->name }}</strong></td>
                    <td>
                        @if ($t->active) <span class="badge ok">aktiv</span>
                        @else <span class="badge off">inaktiv</span>
                        @endif
                    </td>
                    <td class="muted">{{ $t->senderLabel() }}</td>
                    <td class="muted">{{ $t->recipientLabel() }}</td>
                    <td class="muted">{{ $t->periodLabel() }}</td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a class="btn small ghost" href="{{ route('admin.signatures.edit', $t) }}" wire:navigate>Bearbeiten</a>
                        <button class="btn small danger" wire:click="delete({{ $t->id }})" wire:confirm="Signaturblock „{{ $t->name }}" wirklich löschen?">Löschen</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Noch kein Signaturblock — mit „Neuer Signaturblock" starten. Angehängt wird erst, wenn eine Vorlage aktiv ist und das Modul oben eingeschaltet ist.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="muted" style="margin-top:8px;">
            Vorlagen werden nach Priorität geprüft (kleinste zuerst). Ob nach einer Vorlage weitere geprüft
            werden, steuert die „Weiterverarbeitung" je Vorlage.
        </div>
    </div>
</div>
