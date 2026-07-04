<div>
    <h1>Benutzer (Entra ID)</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="alert err">{{ session('err') }}</div>
    @endif

    <div class="card">
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div>
                <strong>{{ $total }}</strong> Benutzer im Cache
                <span class="muted">— letzte Synchronisation: {{ $lastSync ? \Carbon\Carbon::parse($lastSync)->format('d.m.Y H:i') : 'noch nie' }} (automatisch stündlich)</span>
            </div>
            <div style="margin-left:auto;">
                <button class="btn" wire:click="sync" wire:loading.attr="disabled">Jetzt synchronisieren</button>
                <span class="muted" wire:loading wire:target="sync">läuft …</span>
            </div>
        </div>
        @if ($total > 0 && ($missingTitle > 0 || $missingPhone > 0))
            <div class="muted" style="margin-top:8px;">
                Hinweis für Signatur-Vorlagen: {{ $missingTitle }} Benutzer ohne Position, {{ $missingPhone }} ohne Telefonnummer —
                fehlende Attribute lassen sich in Vorlagen per Bedingungsblock ausblenden, gepflegte Daten sind trotzdem besser.
            </div>
        @endif
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Synchronisations-Filter</h2>
        <form wire:submit="saveFilter">
            <div>
                <label>Entra-Gruppen (Objekt-IDs, kommagetrennt) — leer = alle Benutzer des Tenants</label>
                <input type="text" wire:model="sync_groups" placeholder="z.B. aae8b795-10f6-4478-b3c0-736ae20d85c5, dc81d1c9-…" style="width:100%;">
                @error('sync_groups')<div class="error">{{ $message }}</div>@enderror
                <div class="muted" style="margin-top:4px;">
                    Bei Gruppenfilter werden die Mitglieder (auch verschachtelt) unverändert übernommen — inklusive
                    freigegebener Postfächer, die technisch deaktivierte Konten sind. Benötigt die
                    Graph-Berechtigung <code>GroupMember.Read.All</code>.
                </div>
            </div>
            <div class="grid2" style="margin-top:10px;">
                <div>
                    <label style="display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" wire:model="sync_enabled_only" style="width:auto;">
                        Nur aktivierte Konten (wirkt nur ohne Gruppenfilter)
                    </label>
                </div>
                <div>
                    <label>Ausschlussmuster (Wildcards, gegen UPN und E-Mail)</label>
                    <input type="text" wire:model="sync_exclude" placeholder="HealthMailbox*, DiscoverySearchMailbox*">
                    @error('sync_exclude')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <button type="submit" class="btn" style="margin-top:10px;" wire:loading.attr="disabled">Speichern &amp; synchronisieren</button>
            <span class="muted" wire:loading wire:target="saveFilter">läuft …</span>
        </form>
    </div>

    <div class="card">
        <input type="text" wire:model.live.debounce.400ms="q" placeholder="Suche: Name, E-Mail, Abteilung, Position …" style="max-width:420px;">
        <table style="margin-top:12px;">
            <thead><tr><th>Name</th><th>E-Mail</th><th>Position</th><th>Abteilung</th><th>Telefon</th><th>Mobil</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($users as $u)
                <tr>
                    <td><strong>{{ $u->display_name }}</strong></td>
                    <td>{{ $u->mail }}</td>
                    <td>{{ $u->job_title ?: '—' }}</td>
                    <td>{{ $u->department ?: '—' }}</td>
                    <td>{{ $u->business_phone ?: '—' }}</td>
                    <td>{{ $u->mobile_phone ?: '—' }}</td>
                    <td>
                        @if ($u->account_enabled) <span class="badge ok">aktiv</span>
                        @else <span class="badge off">deaktiviert</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Noch keine Benutzer im Cache — „Jetzt synchronisieren" klicken.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:14px;">{{ $users->links('pagination') }}</div>
    </div>
</div>
