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
                    <th style="width:100px;">Eingegangen</th>
                    <th style="width:22%;">Von / Betreff</th>
                    <th>Empfänger</th>
                    <th style="width:110px;">Größe / Ablauf</th>
                    <th style="width:80px;"></th>
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
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;min-width:0;">
                                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;" title="{{ $r->email }}">{{ $r->email }}</span>
                                @if ($r->first_viewed_at)
                                    <span class="badge ok" style="white-space:nowrap;flex-shrink:0;" title="abgerufen am {{ $r->first_viewed_at->format('d.m.Y H:i') }}">abgerufen · {{ $r->download_count }}×</span>
                                @elseif ($r->reminder_sent_at)
                                    <span class="badge warn" style="flex-shrink:0;">erinnert</span>
                                @else
                                    <span class="badge off" style="flex-shrink:0;">offen</span>
                                @endif
                                <button class="btn small ghost" style="padding:1px 6px;flex-shrink:0;" title="Neues Kennwort an {{ $r->email }} senden"
                                    wire:click="resendPassword({{ $r->id }})"
                                    wire:confirm="{{ $r->email }} ein NEUES Kennwort senden? Das bisherige wird ungültig — nur für diesen Empfänger.">🔑</button>
                            </div>
                        @endforeach
                    </td>
                    <td class="muted" style="white-space:nowrap;">{{ number_format($m->size_bytes / 1024, 0, ',', '.') }} KB<br><span style="font-size:12px;" title="läuft ab am {{ $m->expires_at->format('d.m.Y') }}">bis {{ $m->expires_at->format('d.m.') }} ({{ (int) now()->diffInDays($m->expires_at, false) }} T.)</span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        @if ($m->recipients->whereNull('first_viewed_at')->isNotEmpty())
                            <button class="btn small ghost" style="padding:2px 7px;" title="Alle offenen Empfänger erinnern" wire:click="remind({{ $m->id }})">🔔</button>
                        @endif
                        <button class="btn small danger" style="padding:2px 7px;" title="Nachricht endgültig löschen" wire:click="purge({{ $m->id }})" wire:confirm="Diese Nachricht endgültig löschen? Der Empfänger kann sie dann nicht mehr abrufen.">🗑</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted" style="padding:20px;">Keine Nachrichten.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;">{{ $messages->links('pagination') }}</div>
</div>
