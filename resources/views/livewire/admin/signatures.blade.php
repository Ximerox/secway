<div>
    <script src="{{ asset('vendor/tinymce/tinymce.min.js') }}"></script>

    <h1>Signatur-Vorlagen</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="alert err">{{ session('err') }}</div>
    @endif

    <div class="card">
        <div style="display:flex; align-items:center;">
            <h2 style="margin:0;">Vorlagen</h2>
            <button class="btn" style="margin-left:auto;" wire:click="create">Neue Vorlage</button>
        </div>
        <table style="margin-top:12px;">
            <thead><tr><th>Name</th><th>Status</th><th>Vorhandene Signatur</th><th>Geändert</th><th></th></tr></thead>
            <tbody>
            @forelse ($templates as $t)
                <tr>
                    <td><strong>{{ $t->name }}</strong></td>
                    <td>
                        @if ($t->active) <span class="badge ok">aktiv</span>
                        @else <span class="badge off">inaktiv</span>
                        @endif
                    </td>
                    <td class="muted">{{ $t->existing_mode === 'replace' ? 'ersetzen' : 'überspringen' }}</td>
                    <td class="muted">{{ $t->updated_at?->format('d.m.Y H:i') }}</td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn small ghost" wire:click="edit({{ $t->id }})">Bearbeiten</button>
                        <button class="btn small danger" wire:click="delete({{ $t->id }})" wire:confirm="Vorlage „{{ $t->name }}" wirklich löschen?">Löschen</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Noch keine Vorlage — mit „Neue Vorlage" starten. Signaturen werden erst angehängt, wenn eine Vorlage aktiv ist und das Signatur-Modul eingeschaltet wird (kommt in einer späteren Ausbaustufe).</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($editId !== null)
        <div class="card">
            <h2 style="margin-top:0;">{{ $editId ? 'Vorlage bearbeiten' : 'Neue Vorlage' }}</h2>

            <div class="grid2">
                <div>
                    <label>Name</label>
                    <input type="text" wire:model="name" placeholder="z.B. Standard-Signatur">
                    @error('name')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label>Wenn bereits eine Signatur in der Mail steckt (Antwortverlauf)</label>
                    <select wire:model="existing_mode">
                        <option value="replace">Alte entfernen und an aktueller Stelle neu einfügen</option>
                        <option value="skip">Nicht erneut anhängen</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:12px;">
                <label>Signatur (HTML)</label>
                <div wire:ignore>
                    <textarea id="sig-html-editor"></textarea>
                </div>
                @error('html')<div class="error">{{ $message }}</div>@enderror
                <div class="muted" style="margin-top:6px;">
                    Platzhalter über den Toolbar-Knopf einfügen. Bedingte Zeilen: <code>@{{#if telefon}}Tel: @{{telefon}}@{{/if}}</code>
                    — der Block erscheint nur, wenn das Attribut beim Absender gefüllt ist.
                </div>
            </div>

            <div style="margin-top:12px;">
                <label>Text-Variante (optional — für reine Text-Mails; leer = automatisch aus HTML abgeleitet)</label>
                <textarea wire:model="text_body" rows="4" style="width:100%; font-family:monospace;" placeholder="@{{name}}&#10;@{{firma}}&#10;Tel: @{{telefon}}"></textarea>
                @error('text_body')<div class="error">{{ $message }}</div>@enderror
            </div>

            <div style="margin-top:12px; display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                <label style="display:flex; gap:8px; align-items:center; margin:0;">
                    <input type="checkbox" wire:model="active" style="width:auto;"> Vorlage aktiv
                </label>
                <button type="button" class="btn" onclick="sigSave()">Speichern</button>
                <span class="muted" wire:loading>wird verarbeitet …</span>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Vorschau &amp; Test</h2>
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
                        <input type="email" wire:model="test_to" placeholder="d.moeller@straphael.de">
                    </div>
                    <button type="button" class="btn ghost" onclick="sigTest()">Senden</button>
                </div>
            </div>
            @error('test_to')<div class="error">{{ $message }}</div>@enderror
            @if ($previewHtml !== '')
                <iframe style="width:100%; height:240px; border:1px solid #e5e7eb; border-radius:8px; background:#ffffff; margin-top:12px;" srcdoc="{{ $previewHtml }}"></iframe>
            @endif
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Bilder (werden beim Versand eingebettet, kein Nachladen)</h2>
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
        </div>
    @endif

    <script>
        window.sigWire = @this;
        window.SIG_PLACEHOLDERS = @json(\App\Livewire\Admin\Signatures::PLACEHOLDERS);
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
                toolbar: 'undo redo | bold italic underline forecolor fontsize | alignleft aligncenter | bullist numlist | table image link | platzhalter | code',
                menubar: false,
                height: 340,
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
        async function sigCollect() {
            const ed = window.tinymce && tinymce.get('sig-html-editor');
            if (ed) { await window.sigWire.set('html', ed.getContent(), false); }
        }
        async function sigSave() { await sigCollect(); window.sigWire.call('save'); }
        async function sigPreview() { await sigCollect(); window.sigWire.call('preview'); }
        async function sigTest() { await sigCollect(); window.sigWire.call('sendTest'); }
        function sigInsertImage(url) {
            const ed = window.tinymce && tinymce.get('sig-html-editor');
            if (ed) ed.insertContent('<img src="' + url + '" alt="">');
        }
        document.addEventListener('livewire:init', () => {
            Livewire.on('sig-editor', ({ html }) => {
                setTimeout(() => sigInitEditor(html), 60);
            });
        });
    @endverbatim
    </script>
</div>
