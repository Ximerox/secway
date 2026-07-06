<div>
    <h1>S/MIME-Zertifikate</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif

    <div class="card">
        <h2 style="margin-top:0;">Zertifikat importieren</h2>
        <form wire:submit="save">
            <div class="grid2">
                <div>
                    <label>Typ</label>
                    <select wire:model="type">
                        <option value="partner">Partner (Empfänger-Zertifikat, nur öffentlicher Schlüssel)</option>
                        <option value="own">Eigenes Zertifikat (mit privatem Schlüssel)</option>
                    </select>
                </div>
                <div>
                    <label>Domain oder E-Mail-Adresse</label>
                    <input type="text" wire:model="target" placeholder="z.B. partner-domain.de oder person@partner-domain.de">
                    @error('target')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label>Datei (PEM, DER, CER oder P12/PFX)</label>
                    <input type="file" wire:model="file">
                    @error('file')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label>Passwort (nur bei P12/PFX bzw. verschlüsseltem Schlüssel)</label>
                    <input type="password" wire:model="password" autocomplete="off">
                </div>
            </div>
            <button type="submit" class="btn" wire:loading.attr="disabled">Importieren</button>
            <span class="muted" wire:loading>wird geprüft …</span>
        </form>
    </div>

    <h2>Eigene Zertifikate (Signieren/Entschlüsseln)</h2>
    <div class="card">
        <table>
            <thead><tr><th>Domain</th><th>Subject</th><th>Gültig bis</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($own as $c)
                <tr wire:click="showDetails({{ $c->id }})" style="cursor:pointer;" title="Klicken für Details">
                    <td><strong>{{ $c->target }}</strong></td>
                    <td class="muted">{{ $c->subject }}</td>
                    <td>{{ $c->valid_until?->format('d.m.Y') }}</td>
                    <td>
                        @if ($c->isExpired()) <span class="badge err">abgelaufen</span>
                        @elseif (! $c->active) <span class="badge off">deaktiviert</span>
                        @elseif ($c->valid_until?->lt(now()->addDays(30))) <span class="badge warn">läuft bald ab</span>
                        @else <span class="badge ok">aktiv</span>
                        @endif
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn small ghost" wire:click.stop="toggleActive({{ $c->id }})">{{ $c->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                        <button class="btn small danger" wire:click.stop="delete({{ $c->id }})" wire:confirm="Zertifikat für {{ $c->target }} wirklich löschen?">Löschen</button>
                    </td>
                </tr>
                @if ($detailsId === $c->id)
                    @include('livewire.admin.certificate-details', ['c' => $c, 'span' => 5])
                @endif
            @empty
                <tr><td colspan="5" class="muted">Noch kein eigenes Zertifikat hinterlegt — wird zum Signieren und für eingehende Entschlüsselung benötigt.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2>Partner-Zertifikate (automatische Verschlüsselung an diese Ziele)</h2>
    <div class="card">
        <table>
            <thead><tr><th>Ziel</th><th>Ebene</th><th>Subject</th><th>Gültig bis</th><th>Quelle</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($partners as $c)
                <tr wire:click="showDetails({{ $c->id }})" style="cursor:pointer;" title="Klicken für Details">
                    <td><strong>{{ $c->target }}</strong></td>
                    <td>{{ $c->scope === 'domain' ? 'Domain' : 'Adresse' }}</td>
                    <td class="muted">{{ \Illuminate\Support\Str::limit($c->subject, 60) }}</td>
                    <td>{{ $c->valid_until?->format('d.m.Y') }}</td>
                    <td>{{ $c->source === 'upload' ? 'Upload' : 'geerntet' }}</td>
                    <td>
                        @if ($c->isExpired()) <span class="badge err">abgelaufen</span>
                        @elseif (! $c->active) <span class="badge off">deaktiviert</span>
                        @elseif ($c->valid_until?->lt(now()->addDays(30))) <span class="badge warn">läuft bald ab</span>
                        @else <span class="badge ok">aktiv</span>
                        @endif
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn small ghost" wire:click.stop="toggleActive({{ $c->id }})">{{ $c->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                        <button class="btn small danger" wire:click.stop="delete({{ $c->id }})" wire:confirm="Zertifikat für {{ $c->target }} wirklich löschen?">Löschen</button>
                    </td>
                </tr>
                @if ($detailsId === $c->id)
                    @include('livewire.admin.certificate-details', ['c' => $c, 'span' => 7])
                @endif
            @empty
                <tr><td colspan="7" class="muted">Noch keine Partner-Zertifikate. Ohne Zertifikat gehen Empfänger automatisch den Portal-Weg.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="muted">Bei mehreren gültigen Zertifikaten für dasselbe Ziel wird das mit der längsten Restlaufzeit verwendet. Adress-Zertifikate haben Vorrang vor Domain-Zertifikaten.</p>
</div>
