<div>
    <h1>Sicher versenden</h1>
    <p class="muted" style="margin-top:-6px;">Regeln für das Outlook-Add-in, das Absender bei sensiblen Mails fragt, ob sie „sicher" versendet werden sollen.</p>

    @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
    @if (session('err'))<div class="alert err">{{ session('err') }}</div>@endif

    <div class="card">
        <h2 style="margin-top:0;">Einstellungen</h2>
        <form wire:submit="saveSettings">
            <label style="display:flex; gap:8px; align-items:center; margin:0;">
                <input type="checkbox" wire:model="enabled" style="width:auto;"> Modul aktiv (Add-in fragt SecWay)
            </label>
            <div class="grid2" style="margin-top:10px;">
                <div>
                    <label>Schwellwert (ab diesem Gesamt-Score wird gefragt)</label>
                    <input type="number" wire:model="threshold" min="1" max="1000">
                    @error('threshold')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <label style="display:flex; gap:8px; align-items:center; margin:0;">
                        <input type="checkbox" wire:model="smime_exception" style="width:auto;">
                        Nicht fragen, wenn alle Empfänger ein S/MIME-Zertifikat haben (wird ohnehin verschlüsselt)
                    </label>
                </div>
            </div>
            <button type="submit" class="btn">Einstellungen speichern</button>
        </form>
    </div>

    <div class="card">
        <div style="display:flex; align-items:center;">
            <h2 style="margin:0;">Regeln</h2>
            <button class="btn" style="margin-left:auto;" wire:click="newRule">Neue Regel</button>
        </div>
        <table style="margin-top:12px;">
            <thead><tr><th>Name</th><th>Typ</th><th>Score</th><th>Status</th><th>Feuerte (90 T.)</th><th>löste Nachfrage aus</th><th></th></tr></thead>
            <tbody>
            @forelse ($rules as $r)
                @php $s = $stats[$r->id] ?? null; @endphp
                <tr>
                    <td><strong>{{ $r->name }}</strong></td>
                    <td class="muted">{{ $r->typeLabel() }}{{ $r->type === 'keyword' ? ' (≥'.$r->threshold.')' : ($r->type === 'birthdate' ? ' (≥'.$r->threshold.' J.)' : '') }}</td>
                    <td>+{{ $r->score }}</td>
                    <td>@if ($r->active)<span class="badge ok">aktiv</span>@else<span class="badge off">inaktiv</span>@endif</td>
                    <td class="muted">{{ $s['fired'] ?? 0 }}×</td>
                    <td class="muted">{{ $s['asked'] ?? 0 }}×</td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn small ghost" wire:click="edit({{ $r->id }})">Bearbeiten</button>
                        <button class="btn small danger" wire:click="delete({{ $r->id }})" wire:confirm="Regel „{{ $r->name }}" löschen?">Löschen</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Noch keine Regel.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="muted" style="margin-top:8px;">
            „Feuerte" = wie oft die Regel anschlug. „löste Nachfrage aus" = in wie vielen dieser Fälle die Gesamtwertung über dem Schwellwert lag und Outlook die Sende-Rückfrage zeigte. Eine „sicher bestätigt"-Quote lässt sich nicht mehr erfassen: Outlooks eingebaute Rückfrage meldet die Nutzerentscheidung nicht zurück.
        </div>
    </div>

    @if ($editId !== null)
        <div class="card">
            <h2 style="margin-top:0;">{{ $editId ? 'Regel bearbeiten' : 'Neue Regel' }}</h2>
            <div class="grid2">
                <div>
                    <label>Name</label>
                    <input type="text" wire:model="name" placeholder="z.B. Jugendhilfe-Dokumente">
                    @error('name')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label>Typ</label>
                    <select wire:model.live="type">
                        <option value="attachment_name">Anhang-Dateiname enthält …</option>
                        <option value="keyword">Stichwörter im Text (mit Mindestanzahl)</option>
                        <option value="birthdate">Geburtsdatum (Datum in der Vergangenheit)</option>
                    </select>
                </div>
            </div>
            @if ($type !== 'birthdate')
                <div style="margin-top:10px;">
                    <label>Begriffe (komma- oder zeilengetrennt)</label>
                    <textarea wire:model="terms" rows="3" style="width:100%;" placeholder="{{ $type === 'attachment_name' ? 'Hilfeplan, Leistungsplan, PEP, Stammblatt' : 'Sorgerecht, psychisch, Krise, Diagnose' }}"></textarea>
                    @error('terms')<div class="error">{{ $message }}</div>@enderror
                </div>
            @endif
            <div class="grid2" style="margin-top:10px;">
                @if ($type === 'keyword')
                    <div>
                        <label>Mindestanzahl verschiedener Treffer</label>
                        <input type="number" wire:model="rule_threshold" min="1" max="100">
                    </div>
                @elseif ($type === 'birthdate')
                    <div>
                        <label>Mindestalter des Datums (Jahre in der Vergangenheit)</label>
                        <input type="number" wire:model="rule_threshold" min="0" max="100">
                    </div>
                @endif
                <div>
                    <label>Score-Beitrag bei Treffer</label>
                    <input type="number" wire:model="score" min="1" max="1000">
                    @error('score')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <label style="display:flex; gap:8px; align-items:center; margin:0;">
                        <input type="checkbox" wire:model="active" style="width:auto;"> aktiv
                    </label>
                </div>
            </div>
            <div style="margin-top:12px; display:flex; gap:10px;">
                <button type="button" class="btn" wire:click="saveRule">Speichern</button>
                <button type="button" class="btn ghost" wire:click="cancel">Abbrechen</button>
            </div>
        </div>
    @endif

    <div class="card">
        <h2 style="margin-top:0;">Auswertung (90 Tage)</h2>
        <table class="plain">
            <tr><td>Prüfungen gesamt</td><td style="text-align:right;">{{ number_format($logSummary['total'], 0, ',', '.') }}</td></tr>
            <tr><td>davon gefragt</td><td style="text-align:right;">{{ number_format($logSummary['asked'], 0, ',', '.') }}</td></tr>
            <tr><td>ohne Frage (S/MIME-Ausnahme)</td><td style="text-align:right;">{{ number_format($logSummary['smime'], 0, ',', '.') }}</td></tr>
        </table>
        <div class="muted" style="margin-top:8px;">Es werden nur Score und ausgelöste Regeln protokolliert — keine Betreffzeilen, Texte oder Anhangsnamen.</div>
    </div>
    <style>table.plain{width:100%;border-collapse:collapse;font-size:13.5px;} table.plain td{padding:5px 4px;border-bottom:1px solid #f0f1f3;}</style>
</div>
