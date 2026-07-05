<div>
    <h1>Zurückgehaltene Mails</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="alert">{{ session('err') }}</div>
    @endif

    <div class="card">
        <p class="muted" style="margin:0;">Eingehende S/MIME-Mails, die mit keinem der hinterlegten eigenen Zertifikate
            entschlüsselt werden konnten. Nach dem Import des passenden Zertifikats (Typ „eigenes", mit privatem
            Schlüssel) unter <a href="{{ route('admin.certs') }}">Zertifikate</a> hier „Erneut entschlüsseln" wählen —
            oder einfach warten, die Quarantäne prüft alle 15 Minuten automatisch. Nach Ablauf der Frist wird die
            Mail unverändert (verschlüsselt) zugestellt, damit nichts verloren geht.</p>
    </div>

    <div class="card" style="padding:0;overflow:hidden;">
        <table>
            <thead>
                <tr>
                    <th style="width:150px;">Eingegangen</th>
                    <th>Von / Betreff / An</th>
                    <th>Benötigtes Zertifikat</th>
                    <th style="width:130px;">Frist</th>
                    <th style="width:250px;"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($held as $h)
                <tr>
                    <td class="mono" style="white-space:nowrap;">{{ $h->created_at->format('d.m.Y') }}<br>{{ $h->created_at->format('H:i') }} Uhr</td>
                    <td>
                        <div>{{ $h->sender }}</div>
                        <div class="muted">{{ $h->subject ?: '(ohne Betreff)' }}</div>
                        <div class="muted" style="font-size:12px;">an {{ implode(', ', $h->recipients) }} · {{ number_format($h->size_bytes / 1024, 0, ',', '.') }} KB</div>
                    </td>
                    <td class="muted" style="font-size:12.5px;word-break:break-all;">{{ $h->diagnosis ?: '—' }}
                        @if ($h->retry_count > 0)
                            <div style="font-size:11.5px;">{{ $h->retry_count }} automatische Versuche</div>
                        @endif
                    </td>
                    <td class="muted">{{ $h->hold_until->format('d.m.Y H:i') }}<br>
                        <span style="font-size:12px;">{{ $h->hold_until->isPast() ? 'abgelaufen' : 'noch '.now()->diffForHumans($h->hold_until, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn small" wire:click="retry({{ $h->id }})">Erneut entschlüsseln</button>
                        <button class="btn small ghost" wire:click="deliver({{ $h->id }})" wire:confirm="Die Mail unverändert (verschlüsselt) zustellen? Der Empfänger kann sie nur lesen, wenn er selbst den Schlüssel hat.">Zustellen</button>
                        <button class="btn small danger" wire:click="remove({{ $h->id }})" wire:confirm="Diese Mail endgültig verwerfen? Sie wird NICHT zugestellt und kann nicht wiederhergestellt werden.">Verwerfen</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted" style="padding:20px;">Keine zurückgehaltenen Mails. 👍</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($released->isNotEmpty())
        <h2 style="margin-top:26px;">Zuletzt erledigt</h2>
        <div class="card" style="padding:0;overflow:hidden;">
            <table>
                <thead>
                    <tr>
                        <th style="width:150px;">Erledigt</th>
                        <th>Von / Betreff</th>
                        <th style="width:220px;">Ausgang</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($released as $h)
                    <tr>
                        <td class="mono" style="white-space:nowrap;">{{ $h->released_at->format('d.m.Y H:i') }}</td>
                        <td>
                            <div>{{ $h->sender }}</div>
                            <div class="muted">{{ $h->subject ?: '(ohne Betreff)' }}</div>
                        </td>
                        <td>
                            @switch($h->release_action)
                                @case('decrypted') <span class="badge ok">entschlüsselt zugestellt</span> @break
                                @case('as_is') <span class="badge warn">verschlüsselt zugestellt</span> @break
                                @case('auto_timeout') <span class="badge warn">Frist abgelaufen — verschlüsselt zugestellt</span> @break
                                @case('deleted') <span class="badge off">verworfen</span> @break
                                @default <span class="badge off">{{ $h->release_action }}</span>
                            @endswitch
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
