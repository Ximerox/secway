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
            <label style="display:flex; gap:8px; align-items:flex-start; margin:14px 0 0;">
                <input type="checkbox" wire:model="debug" style="width:auto; margin-top:3px;">
                <span>
                    <strong>Diagnose-/Lernmodus</strong> — speichert zu jeder geprüften Mail den <strong>kompletten Text</strong>, die Anhang-Namen und die Einzelwertung jeder Regel, um nachzuvollziehen, warum (nicht) gefragt wurde.
                    <span class="muted" style="display:block;">Achtung: Dabei werden echte Mailinhalte (ggf. mit Sozialdaten) in der Datenbank gespeichert. Nur zur zeitlich begrenzten Analyse aktivieren und danach wieder ausschalten sowie die Inhalte löschen.</span>
                </span>
            </label>
            <button type="submit" class="btn">Einstellungen speichern</button>
        </form>
    </div>

    @if ($debug || $debugLogs->isNotEmpty())
        <div class="card" style="border:1px solid #fde68a;">
            <div style="display:flex; align-items:center; gap:12px;">
                <h2 style="margin:0;">Diagnose @if($debug)<span class="badge warn">aktiv</span>@endif</h2>
                @if ($debugLogs->isNotEmpty())
                    <button class="btn small danger" style="margin-left:auto;" wire:click="purgeDebug" wire:confirm="Alle gespeicherten Mailinhalte der Diagnose löschen? (Kennzahlen bleiben erhalten)">Diagnose-Inhalte löschen ({{ $debugLogs->count() }})</button>
                @endif
            </div>
            <p class="muted" style="margin:6px 0 0;">Die letzten geprüften Mails mit vollständigem Inhalt und Einzelwertung. Schwellwert aktuell: <strong>{{ $threshold }}</strong> — ab diesem Gesamt-Score wird gefragt.</p>

            @forelse ($debugLogs as $d)
                <details style="margin-top:12px; border:1px solid #e5e7eb; border-radius:8px; padding:0;">
                    <summary style="cursor:pointer; padding:10px 14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        <span class="mono" style="white-space:nowrap;">{{ $d->created_at->format('d.m. H:i') }}</span>
                        <span>Score <strong>{{ $d->score }}</strong></span>
                        @if ($d->asked)<span class="badge warn">gefragt</span>@else<span class="badge off">nicht gefragt</span>@endif
                        <span class="muted">{{ $d->external_count }} externe/{{ $d->recipient_count }} Empf.</span>
                        <span class="muted" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:340px;">{{ $d->debug_subject ?: '(ohne Betreff)' }}</span>
                    </summary>
                    <div style="padding:4px 14px 14px;">
                        <table class="plain" style="margin-bottom:10px;">
                            <thead><tr><th>Regel</th><th style="text-align:right;">Beitrag</th></tr></thead>
                            <tbody>
                            @foreach ($d->debug_rules ?? [] as $rr)
                                <tr @if(($rr['contribution'] ?? 0) > 0) style="background:#fffbeb;" @endif>
                                    <td>{{ $rr['name'] ?? $rr['type'] ?? '?' }} <span class="muted">({{ $rr['type'] ?? '' }})</span></td>
                                    <td style="text-align:right;">@if(($rr['contribution'] ?? 0) > 0)<strong>+{{ $rr['contribution'] }}</strong>@else<span class="muted">0 / {{ $rr['max'] ?? '?' }}</span>@endif</td>
                                </tr>
                            @endforeach
                            <tr><td style="text-align:right;"><strong>Summe</strong></td><td style="text-align:right;"><strong>{{ $d->score }}</strong> {{ $d->score >= $threshold ? '≥' : '<' }} {{ $threshold }}</td></tr>
                            </tbody>
                        </table>
                        @if (!empty($d->debug_attachments))
                            <div style="margin-bottom:8px;"><strong>Anhänge:</strong> <span class="mono">{{ implode(', ', $d->debug_attachments) }}</span></div>
                        @endif
                        <div><strong>Betreff:</strong> {{ $d->debug_subject ?: '(leer)' }}</div>
                        <div style="margin-top:6px;"><strong>Text:</strong></div>
                        <pre style="white-space:pre-wrap; word-break:break-word; background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; padding:10px; font-size:12.5px; max-height:340px; overflow:auto;">{{ $d->debug_body }}</pre>
                    </div>
                </details>
            @empty
                <p class="muted" style="margin-top:12px;">Noch keine Diagnose-Einträge. Aktivieren Sie den Modus oben und senden Sie eine Testmail über das Add-in.</p>
            @endforelse
        </div>
    @endif

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
                        <option value="attachment_any">Irgendein Anhang vorhanden</option>
                        <option value="keyword">Stichwörter im Text (mit Mindestanzahl)</option>
                        <option value="birthdate">Geburtsdatum (Datum in der Vergangenheit)</option>
                        <option value="llm">Lokale KI-Prüfung (Sozialdaten-Erkennung)</option>
                    </select>
                </div>
            </div>
            @if (in_array($type, ['attachment_name', 'keyword']))
                <div style="margin-top:10px;">
                    <label>Begriffe (komma- oder zeilengetrennt)</label>
                    <textarea wire:model="terms" rows="3" style="width:100%;" placeholder="{{ $type === 'attachment_name' ? 'Hilfeplan, Leistungsplan, PEP, Stammblatt' : 'Sorgerecht, psychisch, Krise, Diagnose' }}"></textarea>
                    @error('terms')<div class="error">{{ $message }}</div>@enderror
                </div>
            @elseif ($type === 'llm')
                <p class="muted" style="margin-top:10px;">Ein lokales KI-Modell auf dem Server prüft Betreff und Text auf schutzbedürftige Sozialdaten. Keine Begriffsliste nötig; die Mailinhalte verlassen den Server nicht. Bei Treffer wird der Score-Beitrag addiert.</p>
            @elseif ($type === 'attachment_any')
                <p class="muted" style="margin-top:10px;">Reagiert allein darauf, dass die Mail einen echten Dateianhang hat. Inline-Bilder (z. B. Logos in der Signatur) zählen nicht. Keine weiteren Angaben nötig.</p>
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
