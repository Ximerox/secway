<div>
    <h1>Nachrichten im Portal</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif

    <div class="card" style="display:flex;gap:10px;align-items:center;">
        <label style="margin:0;">Anzeigen:</label>
        <select wire:model.live="filter" style="max-width:280px;">
            <option value="open">Nur noch nicht abgerufene</option>
            <option value="all">Alle gespeicherten</option>
        </select>
        <span class="muted">S/MIME-verschlüsselte und normal durchgeleitete Mails erscheinen hier nicht – sie werden direkt zugestellt, nicht im Portal abgelegt.</span>
    </div>

    <div class="card" style="padding:0;overflow:hidden;">
        <table>
            <thead>
                <tr>
                    <th style="width:150px;">Eingegangen</th>
                    <th>Von / Betreff</th>
                    <th>Empfänger</th>
                    <th style="width:80px;">Größe</th>
                    <th style="width:130px;">Läuft ab</th>
                    <th style="width:170px;"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($messages as $m)
                <tr>
                    <td class="mono" style="white-space:nowrap;">{{ $m->created_at->format('d.m.Y') }}<br>{{ $m->created_at->format('H:i') }} Uhr</td>
                    <td>
                        <div>{{ $m->sender_name ?: $m->sender_email }}</div>
                        <div class="muted">{{ $m->subject ?: '(ohne Betreff)' }}</div>
                        @if ($m->attachments_count > 0)
                            <div class="muted" style="font-size:12px;">📎 {{ $m->attachments_count }} Anhang/Anhänge</div>
                        @endif
                    </td>
                    <td>
                        @foreach ($m->recipients as $r)
                            <div style="margin-bottom:3px;">
                                {{ $r->email }}
                                @if ($r->first_viewed_at)
                                    <span class="badge ok" title="abgerufen am {{ $r->first_viewed_at->format('d.m.Y H:i') }}">abgerufen · {{ $r->download_count }}×</span>
                                @elseif ($r->reminder_sent_at)
                                    <span class="badge warn">erinnert</span>
                                @else
                                    <span class="badge off">offen</span>
                                @endif
                            </div>
                        @endforeach
                    </td>
                    <td class="muted">{{ number_format($m->size_bytes / 1024, 0, ',', '.') }} KB</td>
                    <td class="muted">{{ $m->expires_at->format('d.m.Y') }}<br><span style="font-size:12px;">in {{ (int) now()->diffInDays($m->expires_at, false) }} T.</span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        @if ($m->recipients->whereNull('first_viewed_at')->isNotEmpty())
                            <button class="btn small ghost" wire:click="remind({{ $m->id }})">Erinnern</button>
                        @endif
                        <button class="btn small ghost" wire:click="resendPassword({{ $m->id }})" wire:confirm="Dem Empfänger ein NEUES Kennwort senden? Das bisherige wird dabei ungültig.">Kennwort senden</button>
                        <button class="btn small danger" wire:click="purge({{ $m->id }})" wire:confirm="Diese Nachricht endgültig löschen? Der Empfänger kann sie dann nicht mehr abrufen.">Löschen</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted" style="padding:20px;">Keine Nachrichten.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;">{{ $messages->links('pagination') }}</div>
</div>
