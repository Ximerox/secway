<div>
    <h1>Protokoll</h1>

    <div class="card" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label>Richtung</label>
            <select wire:model.live="direction">
                <option value="">alle</option>
                <option value="eingehend">eingehend</option>
                <option value="ausgehend">ausgehend</option>
                <option value="Portal">Portal-Abruf</option>
                <option value="abgewiesen">abgewiesen</option>
                <option value="System">System</option>
            </select>
        </div>
        <div style="flex:1 1 260px;">
            <label>Suche (Adresse, Ereignis, IP, Details)</label>
            <input type="text" wire:model.live.debounce.400ms="q" placeholder="z.B. @lrasha.de oder smime_sent">
        </div>
    </div>

    <div class="card" style="padding:0;overflow:hidden;">
        <table class="log">
            <thead>
                <tr>
                    <th style="width:150px;">Zeitpunkt</th>
                    <th style="width:110px;">Richtung</th>
                    <th>Ereignis / Von → An</th>
                </tr>
            </thead>
            <tbody>
            @php $lastGroup = null; $band = false; @endphp
            @forelse ($events as $e)
                @php
                    $g = $e->groupKey();
                    if ($g !== $lastGroup) { $band = ! $band; $lastGroup = $g; }
                    [$dirLabel, $dirClass] = $e->directionBadge();
                    $crypto = $e->cryptoBadge();
                    $extra = $e->extraDetails();
                @endphp
                <tr class="grp {{ $band ? 'b1' : 'b0' }}">
                    <td class="mono" style="white-space:nowrap;">{{ $e->created_at->format('d.m.Y') }}<br>{{ $e->created_at->format('H:i:s') }}</td>
                    <td><span class="badge {{ $dirClass }}" style="white-space:nowrap;">{{ $dirLabel }}</span></td>
                    <td>
                        <div><strong>{{ $e->event }}</strong>
                            @if ($crypto)
                                <span class="badge {{ $crypto[1] }}" style="margin-left:6px;">{{ $crypto[0] }}</span>
                            @endif
                            @if ($e->ip) <span class="muted">· IP {{ $e->ip }}</span> @endif
                        </div>
                        @if ($e->displaySender() || $e->displayRecipients())
                            <div class="mono" style="margin-top:2px;">
                                @if ($e->displaySender()) <span class="muted">von</span> {{ $e->displaySender() }} @endif
                                @if ($e->displayRecipients()) <span class="muted">→ an</span> {{ $e->displayRecipients() }} @endif
                            </div>
                        @endif
                        @if (! empty($extra))
                            <div class="muted mono" style="margin-top:2px;font-size:12px;">{{ json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted" style="padding:20px;">Keine Einträge.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;">{{ $events->links('pagination') }}</div>

    <style>
        .badge.dir-out { background:#eff6ff; color:#1d4e89; }
        .badge.crypt { background:#f5f3ff; color:#6d28d9; }
        table.log { width:100%; border-collapse:collapse; font-size:13.5px; table-layout:fixed; }
        table.log th { text-align:left; color:#6b7280; font-size:12px; text-transform:uppercase; letter-spacing:.03em; padding:10px 14px; border-bottom:2px solid #e5e7eb; }
        table.log td { padding:9px 14px; vertical-align:top; border-bottom:1px solid #f0f1f3; overflow-wrap:anywhere; }
        table.log tr.b0 td { background:#ffffff; }
        table.log tr.b1 td { background:#f6f8fb; }
        @media (max-width: 820px) {
            table.log { display:table; } /* bricht um statt zu scrollen */
            table.log th:nth-child(1) { width:92px; }
            table.log th:nth-child(2) { width:96px; }
            table.log th, table.log td { padding:8px 8px; }
        }
    </style>
</div>
