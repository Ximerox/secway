<div>
    <h1>S/MIME-Zertifikate</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="alert err">{{ session('err') }}</div>
    @endif

    @if ($psw)
        <div class="card" id="psw-order">
            <h2 style="margin-top:0;">Zertifikat bestellen (PSW Group)
                @if ($pswTest) <span class="badge warn">Testsystem</span> @endif
            </h2>
            @if ($pswMissing)
                <div class="alert err">Konfiguration unvollständig, in der .env fehlt: {{ implode(', ', $pswMissing) }}</div>
            @endif
            <form wire:submit="placeOrder">
                <div class="grid2">
                    <div>
                        <label>E-Mail-Adresse</label>
                        <input type="email" wire:model="orderEmail" placeholder="person@straphael.de">
                        @error('orderEmail')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label>Produkt</label>
                        <select wire:model="orderProduct">
                            @foreach ($pswProducts as $num => $label)
                                <option value="{{ $num }}">{{ $label }} ({{ $num }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn" wire:loading.attr="disabled" @disabled($pswMissing)
                        wire:confirm="{{ $pswTest ? 'Testbestellung übermitteln?' : 'Kostenpflichtige Bestellung bei der PSW Group auslösen?' }}">Bestellen</button>
                <span class="muted" wire:loading wire:target="placeOrder">Schlüssel wird erzeugt und Bestellung übermittelt …</span>
            </form>
            <p class="muted" style="margin-bottom:0;">
                Schlüssel und CSR erzeugt das Gateway, an PSW geht nur der CSR. Nach der Bestätigung (Validierungsmail) holt das Gateway
                das Zertifikat automatisch ab (Abfrage alle 15 Minuten) und legt es als eigenes Zertifikat an.
            </p>

            @if ($orders->isNotEmpty())
                <table style="margin-top:14px;">
                    <thead><tr><th>Datum</th><th>Adresse</th><th>Produkt</th><th>PSW-Nr.</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($orders as $o)
                        @php [$label, $cls] = $o->badge(); @endphp
                        <tr>
                            <td style="white-space:nowrap;">{{ $o->created_at->format('d.m.Y H:i') }}</td>
                            <td><strong>{{ $o->email }}</strong></td>
                            <td class="muted">{{ $o->product_name }}</td>
                            <td class="mono">{{ $o->psw_order_number }}{{ $o->psw_certificate_number ? ' / '.$o->psw_certificate_number : '' }}</td>
                            <td>
                                <span class="badge {{ $cls }}">{{ $label }}</span>
                                @if ($o->info)<div class="muted" style="font-size:12px;max-width:420px;">{{ $o->info }}</div>@endif
                                @if ($o->last_error)<div class="error" style="font-size:12px;max-width:420px;">{{ $o->last_error }}</div>@endif
                                @if ($o->checked_at && $o->isOpen())<div class="muted" style="font-size:12px;">abgefragt {{ $o->checked_at->format('d.m. H:i') }}</div>@endif
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                @if ($o->isOpen())
                                    <button class="btn small ghost" wire:click="refreshOrder({{ $o->id }})">Status abrufen</button>
                                    @if ($o->psw_certificate_number)
                                        <button class="btn small ghost" wire:click="resendOrderMail({{ $o->id }})">Validierungsmail erneut</button>
                                    @endif
                                    <button class="btn small danger" wire:click="cancelOrder({{ $o->id }})" wire:confirm="Bestellung für {{ $o->email }} bei PSW stornieren?">Stornieren</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
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

    <div class="tabbar">
        <button type="button" @class(['tab', 'is-active' => $tab === 'own']) wire:click="$set('tab', 'own')">Eigene Zertifikate</button>
        <button type="button" @class(['tab', 'is-active' => $tab === 'partners']) wire:click="$set('tab', 'partners')">Partner-Zertifikate</button>
    </div>

    <div @class(['tabpane', 'is-active' => $tab === 'own'])>
        <div class="card">
            <h2 style="margin-top:0;">Sicherung</h2>
            @if ($unsaved > 0)
                <div class="alert err">{{ $unsaved }} eigene(s) Zertifikat(e) mit Schlüssel noch nie exportiert. Ohne Sicherung sind sie bei Verlust des Servers weg.</div>
            @endif
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="password" wire:model="backupPassword" placeholder="Passwort für die Sicherung (min. 12 Zeichen)"
                       style="max-width:320px;" autocomplete="new-password">
                <button class="btn small danger" wire:click="exportAll">Alle eigenen Zertifikate sichern (.zip)</button>
            </div>
            @error('backupPassword')<div class="error">{{ $message }}</div>@enderror
            <p class="muted" style="margin-bottom:0;">ZIP mit einer passwortgeschützten .p12-Datei je Zertifikat (inkl. privatem Schlüssel). Außerhalb des Servers sicher ablegen.</p>
        </div>
        <div class="card">
            <table>
                <thead><tr>
                    <th class="sortable" wire:click="sortOwn('target')">Domain @if ($ownSort === 'target')<span class="arrow">{{ $ownDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortOwn('subject')">Subject @if ($ownSort === 'subject')<span class="arrow">{{ $ownDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortOwn('valid_until')">Gültig bis @if ($ownSort === 'valid_until')<span class="arrow">{{ $ownDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortOwn('active')">Status @if ($ownSort === 'active')<span class="arrow">{{ $ownDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th></th>
                </tr></thead>
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
                            @if ($c->key_pem && ! $c->exported_at) <span class="badge warn">nicht gesichert</span> @endif
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            @if ($psw && $c->scope === 'address' && $c->valid_until?->lt(now()->addDays(45)))
                                <a href="#psw-order" class="btn small ghost" wire:click.stop="prefillOrder({{ $c->id }})">Neu bestellen</a>
                            @endif
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
    </div>

    <div @class(['tabpane', 'is-active' => $tab === 'partners'])>
        <div class="card">
            <table>
                <thead><tr>
                    <th class="sortable" wire:click="sortPartner('target')">Ziel @if ($partnerSort === 'target')<span class="arrow">{{ $partnerDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortPartner('scope')">Ebene @if ($partnerSort === 'scope')<span class="arrow">{{ $partnerDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortPartner('subject')">Subject @if ($partnerSort === 'subject')<span class="arrow">{{ $partnerDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortPartner('valid_until')">Gültig bis @if ($partnerSort === 'valid_until')<span class="arrow">{{ $partnerDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortPartner('source')">Quelle @if ($partnerSort === 'source')<span class="arrow">{{ $partnerDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th class="sortable" wire:click="sortPartner('active')">Status @if ($partnerSort === 'active')<span class="arrow">{{ $partnerDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
                    <th></th>
                </tr></thead>
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
</div>
