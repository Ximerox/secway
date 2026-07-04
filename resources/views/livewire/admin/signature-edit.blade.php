<div>
    <script src="{{ asset('vendor/tinymce/tinymce.min.js') }}"></script>

    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <h1 style="margin:0;">{{ $templateId ? 'Signaturblock bearbeiten' : 'Neuer Signaturblock' }}</h1>
        <a class="btn small ghost" href="{{ route('admin.signatures') }}" wire:navigate style="margin-left:auto;">← Zur Übersicht</a>
    </div>

    @if (session('ok'))
        <div class="alert ok" style="margin-top:12px;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="alert err" style="margin-top:12px;">{{ session('err') }}</div>
    @endif

    <div class="card" style="margin-top:12px;">
        {{-- Tab-Leiste (server-seitig, morph-sicher) --}}
        <div class="tabbar">
            @foreach (['inhalt' => 'Inhalt', 'absender' => 'Absender', 'empfaenger' => 'Empfänger', 'logik' => 'Zeitraum & Logik', 'elemente' => 'Bilder & QR', 'vorschau' => 'Vorschau & Test'] as $key => $label)
                <button type="button" @class(['tab', 'is-active' => $tab === $key]) wire:click="$set('tab', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>

        {{-- Inhalt (immer im DOM, nur ein-/ausgeblendet — der Editor bleibt erhalten) --}}
        <div @class(['tabpane', 'is-active' => $tab === 'inhalt'])>
            <div class="grid2">
                <div>
                    <label>Name des Signaturblocks</label>
                    <input type="text" wire:model="name" placeholder="z.B. Standard extern">
                    @error('name')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div style="margin-top:12px;">
                <label>Inhalt (HTML)</label>
                <div wire:ignore>
                    <textarea id="sig-html-editor"></textarea>
                </div>
                @error('html')<div class="error">{{ $message }}</div>@enderror
                <div class="muted" style="margin-top:6px;">
                    Platzhalter über den Toolbar-Knopf einfügen. Bedingte Zeilen:
                    <code>@{{#if telefon}}Tel: @{{telefon}}@{{/if}}</code> — der Block erscheint nur, wenn das
                    Attribut beim Absender gefüllt ist. Bilder und QR-Codes im Tab „Bilder &amp; QR" verwalten.
                </div>
            </div>
            <div style="margin-top:12px;">
                <label>Text-Variante (optional — für reine Text-Mails; leer = automatisch aus HTML abgeleitet)</label>
                <textarea wire:model="text_body" rows="4" style="width:100%; font-family:monospace;"></textarea>
                @error('text_body')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Absender --}}
        <div @class(['tabpane', 'is-active' => $tab === 'absender'])>
            <div class="grid2">
                <div>
                    <label>Anwenden bei Absender</label>
                    <select wire:model.live="sender_mode">
                        <option value="all">Alle Benutzer</option>
                        <option value="users">Bestimmte Adressen</option>
                        <option value="group">Entra-Gruppe</option>
                    </select>
                </div>
            </div>
            @if ($sender_mode === 'users')
                <div style="margin-top:10px;">
                    <label>Absenderadressen (kommagetrennt; ganze Domains als @domain.de)</label>
                    <textarea wire:model="sender_users" rows="2" style="width:100%;" placeholder="vorname.nachname@example.org, @example.org"></textarea>
                    @error('sender_users')<div class="error">{{ $message }}</div>@enderror
                </div>
            @elseif ($sender_mode === 'group')
                <div style="margin-top:10px;">
                    <label>Gruppe</label>
                    <select wire:model="sender_group_id">
                        <option value="">— bitte wählen —</option>
                        @foreach ($this->groupOptions as $gid => $gname)
                            <option value="{{ $gid }}">{{ $gname }}</option>
                        @endforeach
                    </select>
                    @error('sender_group_id')<div class="error">{{ $message }}</div>@enderror
                    <div class="muted" style="margin-top:4px;">
                        Mitgliedschaften werden beim Speichern aufgelöst (und stündlich per Entra-Sync aktualisiert).
                    </div>
                </div>
            @endif
            <div style="margin-top:10px;">
                <label>Absender-Ausnahmen (diese Absender NIE — Adressen oder @domain.de)</label>
                <textarea wire:model="sender_exclude" rows="2" style="width:100%;" placeholder="chef@example.org, @extern-dienstleister.de"></textarea>
                @error('sender_exclude')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Empfänger --}}
        <div @class(['tabpane', 'is-active' => $tab === 'empfaenger'])>
            <div class="grid2">
                <div>
                    <label>Gilt für Mails an</label>
                    <select wire:model="direction">
                        <option value="both">Alle Empfänger (intern + extern)</option>
                        <option value="external">Nur externe Empfänger</option>
                        <option value="internal">Nur interne Empfänger</option>
                    </select>
                </div>
            </div>
            <div class="grid2" style="margin-top:10px;">
                <div>
                    <label>Empfänger-Einschränkung (leer = alle passenden; Adressen oder @domain.de)</label>
                    <textarea wire:model="recipient_include" rows="2" style="width:100%;" placeholder="@partner.de, kunde@example.org"></textarea>
                    @error('recipient_include')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label>Empfänger-Ausnahmen (an diese NIE)</label>
                    <textarea wire:model="recipient_exclude" rows="2" style="width:100%;" placeholder="@no-signature.de"></textarea>
                    @error('recipient_exclude')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        {{-- Zeitraum & Logik --}}
        <div @class(['tabpane', 'is-active' => $tab === 'logik'])>
            <div class="grid2">
                <div>
                    <label>Gültig von (leer = sofort)</label>
                    <input type="date" wire:model="valid_from">
                    @error('valid_from')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label>Gültig bis (leer = dauerhaft)</label>
                    <input type="date" wire:model="valid_until">
                    @error('valid_until')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label>Priorität (kleinere Zahl = zuerst geprüft)</label>
                    <input type="number" wire:model="priority" min="1" max="999">
                    @error('priority')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="grid2" style="margin-top:12px;">
                <div>
                    <label>Wenn dieser Block <strong>angewandt</strong> wird</label>
                    <select wire:model="on_applied">
                        <option value="stop">Danach keine weiteren Blöcke mehr</option>
                        <option value="continue">Weitere passende Blöcke ebenfalls anwenden</option>
                    </select>
                </div>
                <div>
                    <label>Wenn dieser Block <strong>nicht</strong> angewandt wird</label>
                    <select wire:model="on_not_applied">
                        <option value="continue">Nächsten Block prüfen</option>
                        <option value="stop">Danach keine weiteren Blöcke mehr prüfen</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Bilder & QR --}}
        <div @class(['tabpane', 'is-active' => $tab === 'elemente'])>
            <h3 style="margin-top:0;">Bilder (werden beim Versand eingebettet, kein Nachladen)</h3>
            <input type="file" wire:model="upload" accept="image/*">
            @error('upload')<div class="error">{{ $message }}</div>@enderror
            <span class="muted" wire:loading wire:target="upload">wird hochgeladen …</span>
            @if ($images->isNotEmpty())
                <table style="margin-top:12px;">
                    <thead><tr><th>Vorschau</th><th>Datei</th><th>Größe</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($images as $img)
                        <tr>
                            <td><img src="{{ route('admin.sigimg', $img) }}" alt="" style="max-height:40px; max-width:160px;"></td>
                            <td class="muted">{{ $img->original_name }}</td>
                            <td class="muted">{{ number_format($img->size / 1024, 0, ',', '.') }} KB</td>
                            <td style="text-align:right; white-space:nowrap;">
                                <button type="button" class="btn small ghost" onclick="sigInsertImage('{{ route('admin.sigimg', $img) }}')">In Editor einfügen</button>
                                <button class="btn small danger" wire:click="deleteImage({{ $img->id }})" wire:confirm="Bild „{{ $img->original_name }}" löschen?">Löschen</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            <div style="display:flex; align-items:center; margin-top:22px;">
                <h3 style="margin:0;">QR-Codes (z.B. vCard zum Abscannen)</h3>
                <button type="button" class="btn small" style="margin-left:auto;" wire:click="newQr">Neuer QR-Code</button>
            </div>
            <div class="muted" style="margin-top:6px;">
                Der QR-Inhalt darf Platzhalter enthalten und wird pro Absender erzeugt und eingebettet.
            </div>
            @if ($qrEditId !== null)
                <div style="border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin-top:12px;">
                    <div class="grid2">
                        <div>
                            <label>Bezeichnung</label>
                            <input type="text" wire:model="qr_label" placeholder="z.B. vCard Kontaktdaten">
                            @error('qr_label')<div class="error">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label>Größe (px)</label>
                            <input type="number" wire:model="qr_size" min="80" max="400">
                            @error('qr_size')<div class="error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div style="margin-top:10px;">
                        <label>Inhalt (Platzhalter erlaubt)</label>
                        <textarea wire:model="qr_text" rows="8" style="width:100%; font-family:monospace;"></textarea>
                        @error('qr_text')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div style="margin-top:10px; display:flex; gap:10px;">
                        <button type="button" class="btn" wire:click="saveQr">QR-Code speichern</button>
                        <button type="button" class="btn ghost" wire:click="cancelQr">Abbrechen</button>
                    </div>
                </div>
            @endif
            @if ($qrCodes->isNotEmpty())
                <table style="margin-top:12px;">
                    <thead><tr><th>Vorschau</th><th>Bezeichnung</th><th>Größe</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($qrCodes as $qr)
                        <tr>
                            <td><img src="{{ route('admin.sigqr', $qr) }}" alt="" style="height:44px; width:44px;"></td>
                            <td><strong>{{ $qr->label }}</strong></td>
                            <td class="muted">{{ $qr->size }} px</td>
                            <td style="text-align:right; white-space:nowrap;">
                                <button type="button" class="btn small ghost" onclick="sigInsertImage('{{ route('admin.sigqr', $qr) }}')">In Editor einfügen</button>
                                <button type="button" class="btn small ghost" wire:click="editQr({{ $qr->id }})">Bearbeiten</button>
                                <button class="btn small danger" wire:click="deleteQr({{ $qr->id }})" wire:confirm="QR-Code „{{ $qr->label }}" löschen?">Löschen</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Vorschau & Test --}}
        <div @class(['tabpane', 'is-active' => $tab === 'vorschau'])>
            <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <div>
                    <label>Mit Daten von</label>
                    <select wire:model="preview_user">
                        @foreach ($previewUsers as $u)
                            <option value="{{ $u->mail }}">{{ $u->display_name }} ({{ $u->mail }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" class="btn" onclick="sigPreview()">Vorschau aktualisieren</button>
                <div style="margin-left:auto; display:flex; gap:8px; align-items:flex-end;">
                    <div>
                        <label>Testmail an</label>
                        <input type="email" wire:model="test_to" placeholder="ihre-adresse@example.org">
                    </div>
                    <button type="button" class="btn ghost" onclick="sigTest()">Senden</button>
                </div>
            </div>
            @error('test_to')<div class="error">{{ $message }}</div>@enderror
            @if ($previewHtml !== '')
                <iframe style="width:100%; height:260px; border:1px solid #e5e7eb; border-radius:8px; background:#ffffff; margin-top:12px;" srcdoc="{{ $previewHtml }}"></iframe>
            @else
                <p class="muted" style="margin-top:12px;">Auf „Vorschau aktualisieren" klicken, um den gerenderten Signaturblock zu sehen.</p>
            @endif
        </div>

        {{-- Fußleiste: immer sichtbar --}}
        <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:18px; padding-top:14px; border-top:1px solid #eef0f2;">
            <label style="display:flex; gap:8px; align-items:center; margin:0;">
                <input type="checkbox" wire:model="active" style="width:auto;"> Signaturblock aktiv
            </label>
            <button type="button" class="btn" onclick="sigSave()">Speichern</button>
            <a class="btn ghost" href="{{ route('admin.signatures') }}" wire:navigate>Abbrechen</a>
            <span class="muted" wire:loading>wird verarbeitet …</span>
        </div>
    </div>

    <script>
        window.SIG_WIRE_ID = '{{ $this->getId() }}';
        window.SIG_PLACEHOLDERS = @json(\App\Livewire\Admin\SignatureEdit::PLACEHOLDERS);
        window.SIG_INITIAL_HTML = @js($html);
    </script>
    <script>
    @verbatim
        function sigInitEditor(html) {
            if (!window.tinymce) return;
            tinymce.remove('#sig-html-editor');
            const ta = document.getElementById('sig-html-editor');
            if (!ta) return;
            ta.value = html;
            tinymce.init({
                selector: '#sig-html-editor',
                plugins: 'table image link code lists',
                toolbar: 'undo redo | fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist | table image link | platzhalter | code',
                menubar: false,
                height: 460,
                convert_urls: false,
                branding: false,
                promotion: false,
                setup: (editor) => {
                    editor.ui.registry.addMenuButton('platzhalter', {
                        text: 'Platzhalter',
                        fetch: (cb) => cb(Object.entries(window.SIG_PLACEHOLDERS).map(([key, label]) => ({
                            type: 'menuitem',
                            text: label + '  {{' + key + '}}',
                            onAction: () => editor.insertContent('{{' + key + '}}'),
                        }))),
                    });
                },
            });
        }
        function sigWire() { return window.Livewire.find(window.SIG_WIRE_ID); }
        async function sigCollect() {
            const ed = window.tinymce && tinymce.get('sig-html-editor');
            if (ed) { await sigWire().set('html', ed.getContent(), false); }
        }
        async function sigSave() { await sigCollect(); sigWire().call('save'); }
        async function sigPreview() { await sigCollect(); sigWire().call('preview'); }
        async function sigTest() { await sigCollect(); sigWire().call('sendTest'); }
        function sigInsertImage(url) {
            const ed = window.tinymce && tinymce.get('sig-html-editor');
            if (ed) ed.insertContent('<img src="' + url + '" alt="">');
        }
        document.addEventListener('livewire:navigated', () => {
            setTimeout(() => sigInitEditor(window.SIG_INITIAL_HTML || ''), 60);
        });
    @endverbatim
    </script>

    <style>
        .tabbar { display:flex; gap:4px; flex-wrap:wrap; border-bottom:1px solid #e5e7eb; margin-bottom:16px; }
        .tabbar .tab { background:none; border:none; padding:9px 14px; font-size:14.5px; color:#6b7280; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; }
        .tabbar .tab:hover { color:#1d4e89; }
        .tabbar .tab.is-active { color:#1d4e89; font-weight:600; border-bottom-color:#1d4e89; }
        .tabpane { display:none; }
        .tabpane.is-active { display:block; }
    </style>
</div>
