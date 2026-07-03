<div wire:poll.15s>
    <h1>Warteschlange</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <strong>Postfix-Mailqueue</strong>
                <p class="muted" style="margin:4px 0 0;">Mails, die der Server gerade zustellt oder nach einem Fehler erneut versucht. Postfix wiederholt automatisch bis zu 5 Tage, danach erhält der Absender eine Unzustellbarkeitsmeldung. Die Ansicht aktualisiert sich alle 15 Sekunden.</p>
            </div>
            @if (count($items) > 0)
                <button class="btn ghost" wire:click="flushAll">Alle jetzt zustellen</button>
            @endif
        </div>
    </div>

    <div class="card" style="padding:0;overflow:hidden;">
        <table>
            <thead>
                <tr>
                    <th style="width:130px;">Queue-ID</th>
                    <th style="width:130px;">Seit</th>
                    <th>Von → An</th>
                    <th style="width:80px;">Größe</th>
                    <th>Grund</th>
                    <th style="width:200px;"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($items as $m)
                @php $requested = in_array($m['id'], $pendingDeletes, true); @endphp
                <tr>
                    <td class="mono">{{ $m['id'] }}<br>
                        @if ($m['queue'] === 'active')
                            <span class="badge ok">wird zugestellt</span>
                        @elseif ($m['queue'] === 'hold')
                            <span class="badge warn">angehalten</span>
                        @else
                            <span class="badge off">wartet</span>
                        @endif
                    </td>
                    <td class="muted" style="white-space:nowrap;">{{ $m['arrival']?->format('d.m. H:i') }}<br><span style="font-size:12px;">{{ $m['arrival']?->diffForHumans() }}</span></td>
                    <td class="mono" style="font-size:12.5px;">
                        {{ $m['sender'] ?: '(leer / Bounce)' }}<br>
                        <span class="muted">→</span> {{ implode(', ', $m['recipients']) }}
                    </td>
                    <td class="muted">{{ number_format($m['size'] / 1024, 0, ',', '.') }} KB</td>
                    <td class="muted" style="font-size:12.5px;max-width:320px;">{{ $m['reason'] ?: '—' }}</td>
                    <td style="text-align:right;white-space:nowrap;">
                        @if ($requested)
                            <span class="badge warn">Löschung angefordert…</span>
                        @else
                            <button class="btn small ghost" wire:click="flush('{{ $m['id'] }}')">Erneut zustellen</button>
                            <button class="btn small danger" wire:click="remove('{{ $m['id'] }}')" wire:confirm="Diese Mail endgültig aus der Warteschlange löschen? Der Absender erfährt davon nichts.">Löschen</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted" style="padding:20px;">Die Warteschlange ist leer – alles zugestellt. ✓</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <strong>Verzögerte Kennwort-Mails</strong>
        <p class="muted" style="margin:4px 0 10px;">Kennwörter, die nach dem eingestellten Zeitversatz noch auf ihren Versand warten.</p>
        @if ($pendingPasswords->isEmpty())
            <span class="muted">Keine wartenden Kennwort-Mails.</span>
        @else
            <table>
                <thead><tr><th>Empfänger</th><th>Zur Nachricht von</th><th style="width:150px;">Fällig</th><th style="width:130px;"></th></tr></thead>
                <tbody>
                @foreach ($pendingPasswords as $p)
                    <tr>
                        <td>{{ $p->email }}</td>
                        <td class="muted">{{ $p->message?->sender_email ?? '—' }}</td>
                        <td class="muted">{{ $p->password_due_at?->format('H:i:s') }} Uhr</td>
                        <td style="text-align:right;"><button class="btn small ghost" wire:click="sendPasswordNow({{ $p->id }})">Jetzt senden</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
