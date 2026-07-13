<div>
    <h1>Sicher versenden</h1>
    <p class="muted" style="margin-top:-6px;">Regeln für das Outlook-Add-in, das Absender bei sensiblen Mails fragt, ob sie „sicher" versendet werden sollen.</p>

    @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
    @if (session('err'))<div class="alert err">{{ session('err') }}</div>@endif

    <div class="tabbar" style="margin-bottom:14px;">
        <button type="button" @class(['tab', 'is-active' => $tab === 'regeln']) wire:click="$set('tab', 'regeln')">Regeln &amp; Einstellungen</button>
        <button type="button" @class(['tab', 'is-active' => $tab === 'diagnose']) wire:click="$set('tab', 'diagnose')">Diagnose-Logs @if($debug)<span class="badge warn" style="margin-left:4px;">aktiv</span>@endif</button>
    </div>

    <div @class(['tabpane', 'is-active' => $tab === 'regeln'])>
    <form wire:submit="saveSettings">
        <div class="card">
            <h2 style="margin-top:0;">Sende-Rückfrage im Outlook-Add-in</h2>

            <label class="opt">
                <input type="checkbox" wire:model="enabled">
                <span>
                    <strong>Modul aktiv (Add-in fragt SecWay)</strong><br>
                    <span class="muted">Beim Senden prüft das Add-in die Mail gegen die Regeln unten. Liegt der Gesamt-Score über dem Schwellwert, zeigt Outlook die Rückfrage „Sicher versenden?".</span>
                </span>
            </label>

            <div class="grid2" style="margin-top:14px;">
                <div>
                    <label>Schwellwert (Gesamt-Score, ab dem gefragt wird)</label>
                    <input type="number" wire:model="threshold" min="1" max="1000" style="max-width:120px;">
                    @error('threshold')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Die Score-Beiträge aller zutreffenden Regeln werden addiert und gegen diesen Wert geprüft.</p>
                </div>
                <div>
                    <label class="opt" style="margin-top:0;">
                        <input type="checkbox" wire:model="smime_exception">
                        <span>
                            <strong>S/MIME-Ausnahme</strong><br>
                            <span class="muted">Nicht fragen, wenn alle Empfänger ein S/MIME-Zertifikat haben — die Mail wird ohnehin verschlüsselt.</span>
                        </span>
                    </label>
                </div>
            </div>

        </div>

        <div class="card">
            <h2 style="margin-top:0;">Nachgelagerte KI-Prüfung (Gateway)</h2>
            <p class="muted" style="margin-top:-4px;">Unabhängig vom Add-in: Würde eine Mail <strong>unverschlüsselt an externe Empfänger</strong> gehen, prüft das Gateway sie mit <strong>denselben Regeln wie das Plugin</strong> (Anhang-Namen, Stichworte, Geburtsdatum, KI) — nur mit dem <strong>review_score</strong> je Regel gewichtet und dem großen 7B-Modell als KI-Regel (nur auf diesem Server, kein Datenabfluss). Der bewusste „Trotzdem senden"-Override im Add-in wird respektiert. Fällt der KI-Dienst aus, zählen die übrigen Regeln weiter; ist gar nichts erreichbar, wird normal zugestellt (kein Mailstau).</p>

            <div class="grid2" style="margin-top:14px;">
                <div>
                    <label>Modus</label>
                    <select wire:model="llm_mode" style="max-width:360px;">
                        <option value="off">Aus — keine nachgelagerte Prüfung</option>
                        <option value="log">Nur Log — prüfen und protokollieren, Mail geht normal raus</option>
                        <option value="secure">Log und Absichern — ab Schwellwert umleiten (S/MIME oder Portal)</option>
                    </select>
                    @error('llm_mode')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">„Nur Log" eignet sich zum Kalibrieren: Im Protokoll erscheint <em>llm_flagged</em> für jede Mail, die abgesichert worden wäre. „Log und Absichern" leitet ab Schwellwert wirklich um (Zertifikat vorhanden → S/MIME, sonst → Portal) und informiert den Absender per Mail.</p>
                </div>
                <div>
                    <label>Schwellwert (Gesamt-Score, ab dem reagiert wird)</label>
                    <input type="number" wire:model="review_threshold" min="1" max="1000" style="max-width:120px;">
                    @error('review_threshold')<div class="error">{{ $message }}</div>@enderror
                    <p class="muted" style="margin-top:6px;">Summe der <strong>review_score</strong>-Beiträge aller zutreffenden Regeln, gegen diesen Wert geprüft — eigener Wert, unabhängig vom Plugin-Schwellwert oben. Höher = strenger.</p>
                </div>
            </div>
        </div>

        <button type="submit" class="btn" style="margin:-6px 0 18px;">Einstellungen speichern</button>
    </form>

    <div class="card">
        <div style="display:flex; align-items:center;">
            <h2 style="margin:0;">Regeln</h2>
            <button class="btn" style="margin-left:auto;" wire:click="newRule">Neue Regel</button>
        </div>
        <table style="margin-top:12px;">
            <thead><tr><th>Name</th><th>Typ</th><th>Score<br><span class="muted" style="font-weight:400;font-size:11px;">Plugin / Nachgel.</span></th><th>Status</th><th>Feuerte (90 T.)</th><th>löste Nachfrage aus</th><th></th></tr></thead>
            <tbody>
            @forelse ($rules as $r)
                @php $s = $stats[$r->id] ?? null; @endphp
                <tr>
                    <td><strong>{{ $r->name }}</strong></td>
                    <td class="muted">{{ $r->typeLabel() }}@if($r->type === 'keyword') (≥{{ $r->threshold }})@elseif($r->type === 'birthdate') (≥{{ $r->threshold }} J.)@elseif($r->type === 'llm' && ($r->threshold > 0 || $r->review_threshold > 0)) (KI-Faktor {{ $r->threshold }}% / {{ $r->review_threshold }}%)@endif</td>
                    <td class="mono" style="white-space:nowrap;">+{{ $r->score }} <span class="muted">/</span> +{{ $r->review_score }}</td>
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
                <p class="muted" style="margin-top:10px;">Ein lokales KI-Modell auf dem Server prüft Betreff und Text auf schutzbedürftige Sozialdaten (die Mailinhalte verlassen den Server nicht). Das Modell liefert ein Ja/Nein und einen eigenen Wert 0–100. Der Beitrag = <strong>feste Punkte bei „Ja"</strong> plus <strong>Faktor × KI-Wert</strong>. Beide Werte auf 0 ⇒ die KI zählt nicht mit.</p>
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
                @elseif ($type === 'llm')
                    <div>
                        <label>Faktor Plugin (% des KI-Werts, kleines Modell)</label>
                        <input type="number" wire:model="rule_threshold" min="0" max="100">
                        <span class="muted" style="font-size:12px;">z.B. 30 ⇒ knapp ein Drittel des KI-Werts; 0 ⇒ KI-Wert ignorieren</span>
                    </div>
                    <div>
                        <label>Faktor Nachgelagert (% des KI-Werts, großes Modell)</label>
                        <input type="number" wire:model="review_rule_threshold" min="0" max="100">
                        @error('review_rule_threshold')<div class="error">{{ $message }}</div>@enderror
                        <span class="muted" style="font-size:12px;">z.B. 100 ⇒ Beitrag = KI-Wert (0–100) direkt</span>
                    </div>
                @endif
                <div>
                    <label>Score Plugin{{ $type === 'llm' ? ' (Punkte bei „Ja")' : '' }}</label>
                    <input type="number" wire:model="score" min="0" max="1000">
                    @error('score')<div class="error">{{ $message }}</div>@enderror
                    <span class="muted" style="font-size:12px;">Live-Rückfrage im Outlook-Add-in</span>
                </div>
                <div>
                    <label>Score Nachgelagert</label>
                    <input type="number" wire:model="review_score" min="0" max="1000">
                    @error('review_score')<div class="error">{{ $message }}</div>@enderror
                    <span class="muted" style="font-size:12px;">Serverprüfung vor unverschl. Versand (0 = zählt dort nicht)</span>
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
        <div class="muted" style="margin-top:8px;">Add-in-Prüfungen protokollieren nur Score und ausgelöste Regeln — keine Betreffzeilen, Texte oder Anhangsnamen (außer im Diagnose-Modus). Die nachgelagerte Prüfung speichert Inhalte zur Kalibrierung, entfernt sie aber automatisch nach 7 Tagen.</div>
    </div>
    </div> {{-- Ende Tab „Regeln & Einstellungen" --}}

    <div @class(['tabpane', 'is-active' => $tab === 'diagnose'])>
        <div class="card" style="border:1px solid #fde68a;">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <h2 style="margin:0;">Add-in-Diagnose @if($debug)<span class="badge warn">aktiv</span>@endif</h2>
                @if ($debugLogs->isNotEmpty() || $reviewLogs->isNotEmpty())
                    <button class="btn small danger" style="margin-left:auto;" wire:click="purgeDebug" wire:confirm="Alle gespeicherten Mailinhalte löschen (Add-in-Diagnose UND nachgelagerte Prüfung)? Die Kennzahlen bleiben erhalten.">Alle Inhalte löschen</button>
                @endif
            </div>

            <label class="opt" style="margin-top:10px;">
                <input type="checkbox" wire:model.live="debug">
                <span>
                    <strong>Diagnose-/Lernmodus (Add-in-Prüfungen)</strong><br>
                    <span class="muted">Speichert zu jeder Add-in-Prüfung den <strong>kompletten Text</strong>, die Anhang-Namen und die Einzelwertung jeder Regel. Achtung: echte Mailinhalte (ggf. mit Sozialdaten) landen in der Datenbank — nur zur zeitlich begrenzten Analyse aktivieren, danach ausschalten und Inhalte löschen. Wirkt sofort beim Anhaken.</span>
                </span>
            </label>

            <p class="muted" style="margin:10px 0 0;">Geprüfte Mails mit vollständigem Inhalt und Einzelwertung. Schwellwert aktuell: <strong>{{ $threshold }}</strong> — ab diesem Gesamt-Score wird gefragt.</p>

            @forelse ($debugLogs as $d)
                @include('livewire.admin._classify-log-entry', ['d' => $d, 'threshold' => $threshold, 'overLabel' => 'gefragt', 'underLabel' => 'nicht gefragt'])
            @empty
                <p class="muted" style="margin-top:12px;">Noch keine Diagnose-Einträge. Aktivieren Sie den Modus oben und senden Sie eine Testmail über das Add-in.</p>
            @endforelse
            <div style="margin-top:14px;">{{ $debugLogs->links('pagination') }}</div>
        </div>

        <div class="card">
            <h2 style="margin:0;">Nachgelagerte Prüfung — Auswertung</h2>
            <p class="muted" style="margin:6px 0 0;">Jede vom Gateway nachgeprüfte Mail (wäre sonst unverschlüsselt an Externe gegangen) mit Einzelwertung und Inhalt — auch unterhalb der Schwelle. Schwellwert aktuell: <strong>{{ $reviewThreshold }}</strong>. Inhalte werden nach <strong>7 Tagen automatisch entfernt</strong>, die Kennzahlen bleiben; „Alle Inhalte löschen" oben räumt auch hier sofort auf.</p>

            @forelse ($reviewLogs as $d)
                @include('livewire.admin._classify-log-entry', ['d' => $d, 'threshold' => $reviewThreshold, 'overLabel' => 'über Schwelle', 'underLabel' => 'unter Schwelle'])
            @empty
                <p class="muted" style="margin-top:12px;">Noch keine nachgelagerten Prüfungen protokolliert (Modus „{{ $llm_mode }}"). Einträge entstehen, sobald eine Mail unverschlüsselt an Externe ginge und der Modus nicht „off" ist.</p>
            @endforelse
            <div style="margin-top:14px;">{{ $reviewLogs->links('pagination') }}</div>
        </div>
    </div> {{-- Ende Tab „Diagnose-Logs" --}}

    <style>table.plain{width:100%;border-collapse:collapse;font-size:13.5px;} table.plain td{padding:5px 4px;border-bottom:1px solid #f0f1f3;}</style>
</div>
