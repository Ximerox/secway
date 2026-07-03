@php
    $cats = \App\Livewire\Admin\Stats::CATEGORIES;
    $fmtDur = function (?int $min) {
        if ($min === null) return '—';
        if ($min < 60) return $min.' Min.';
        if ($min < 1440) return intdiv($min, 60).' Std. '.($min % 60).' Min.';
        return intdiv($min, 1440).' T. '.intdiv($min % 1440, 60).' Std.';
    };
@endphp
<div>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h1 style="margin:0;">Statistik</h1>
        <div style="display:flex;gap:6px;">
            @foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage'] as $d => $label)
                <button class="btn small {{ $daysShown === $d ? '' : 'ghost' }}" wire:click="$set('days', {{ $d }})">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Kennzahlen-Kacheln --}}
    <div class="stat-grid">
        @foreach ($cats as $ev => [$label, $color])
            <div class="card stat"><div class="num" style="color:{{ $color }};">{{ number_format($totals[$ev], 0, ',', '.') }}</div><div class="lbl">{{ $label }}</div></div>
        @endforeach
        <div class="card stat"><div class="num">{{ number_format($rejected, 0, ',', '.') }}</div><div class="lbl">abgewiesen / verworfen</div></div>
        <div class="card stat"><div class="num">{{ number_format($harvested, 0, ',', '.') }}</div><div class="lbl">Zertifikate geerntet</div></div>
    </div>

    {{-- Verlauf --}}
    <div class="card">
        <strong>Verarbeitete Mails pro Tag</strong>
        <div class="chart">
            @foreach ($chart as $date => $vals)
                @php $sum = array_sum($vals); $d = \Illuminate\Support\Carbon::parse($date); @endphp
                <div class="col" title="{{ $d->format('d.m.Y') }} – gesamt {{ $sum }}@foreach ($cats as $ev => [$label, $c]){{ $vals[$ev] ? ' | '.$label.': '.$vals[$ev] : '' }}@endforeach">
                    @foreach ($cats as $ev => [$label, $color])
                        @if ($vals[$ev] > 0)
                            <div class="seg" style="height:{{ max(2, round($vals[$ev] / $chartMax * 150)) }}px;background:{{ $color }};"></div>
                        @endif
                    @endforeach
                    @if ($sum === 0)<div class="seg" style="height:1px;background:#e5e7eb;"></div>@endif
                    @if ($daysShown <= 30 ? $d->dayOfWeek === 1 : $d->day === 1)
                        <div class="xlbl">{{ $d->format('d.m.') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="legend">
            @foreach ($cats as $ev => [$label, $color])
                <span><i style="background:{{ $color }};"></i>{{ $label }}</span>
            @endforeach
        </div>
    </div>

    <div class="two-col">
        {{-- Versandwege --}}
        <div class="card">
            <strong>Versandwege ausgehend ({{ number_format($outTotal, 0, ',', '.') }} Mails)</strong>
            @if ($outTotal > 0)
                @php
                    $p1 = round($totals['smime_sent'] / $outTotal * 100, 1);
                    $p2 = round($totals['ingest_stored'] / $outTotal * 100, 1);
                    $p3 = round(100 - $p1 - $p2, 1);
                @endphp
                <div class="donut" style="background:conic-gradient(#1d4e89 0 {{ $p1 }}%, #d97706 {{ $p1 }}% {{ $p1 + $p2 }}%, #94a3b8 {{ $p1 + $p2 }}% 100%);"><div class="hole"></div></div>
                <table class="plain">
                    <tr><td><i class="dot" style="background:#1d4e89;"></i>S/MIME verschlüsselt</td><td class="mono" style="text-align:right;">{{ $p1 }} %</td></tr>
                    <tr><td><i class="dot" style="background:#d97706;"></i>Portal (Link + Kennwort)</td><td class="mono" style="text-align:right;">{{ $p2 }} %</td></tr>
                    <tr><td><i class="dot" style="background:#94a3b8;"></i>Durchleitung (unverschlüsselt)</td><td class="mono" style="text-align:right;">{{ $p3 }} %</td></tr>
                </table>
            @else
                <p class="muted">Noch keine ausgehenden Mails im Zeitraum.</p>
            @endif
        </div>

        {{-- Portal-Verhalten --}}
        <div class="card">
            <strong>Portal-Abrufverhalten</strong>
            <table class="plain">
                <tr><td>Benachrichtigte Empfänger</td><td class="mono" style="text-align:right;">{{ number_format($rTotal, 0, ',', '.') }}</td></tr>
                <tr><td>davon abgerufen</td><td class="mono" style="text-align:right;">{{ number_format($rViewed, 0, ',', '.') }} ({{ $rTotal > 0 ? round($rViewed / $rTotal * 100) : 0 }} %)</td></tr>
                <tr><td>Ø Zeit bis zum ersten Abruf</td><td class="mono" style="text-align:right;">{{ $fmtDur($avgMinutes) }}</td></tr>
                <tr><td>Downloads gesamt</td><td class="mono" style="text-align:right;">{{ number_format($downloads, 0, ',', '.') }}</td></tr>
                <tr><td>Erinnerungen versendet</td><td class="mono" style="text-align:right;">{{ number_format($reminders, 0, ',', '.') }}</td></tr>
                <tr><td>Fehlgeschlagene Kennwort-Eingaben</td><td class="mono" style="text-align:right;">{{ number_format($unlockFails, 0, ',', '.') }}</td></tr>
            </table>
        </div>

        {{-- Top-Listen --}}
        <div class="card">
            <strong>Häufigste Empfänger-Domains</strong>
            @php $maxD = max([1, ...array_values($topRecipientDomains)]); @endphp
            <table class="plain">
                @forelse ($topRecipientDomains as $dom => $n)
                    <tr><td style="width:45%;">{{ $dom }}</td>
                        <td><div class="bar" style="width:{{ round($n / $maxD * 100) }}%;"></div></td>
                        <td class="mono" style="text-align:right;width:50px;">{{ $n }}</td></tr>
                @empty
                    <tr><td class="muted">Keine Daten im Zeitraum.</td></tr>
                @endforelse
            </table>
        </div>

        <div class="card">
            <strong>Aktivste interne Absender</strong>
            @php $maxS = max([1, ...array_values($topSenders)]); @endphp
            <table class="plain">
                @forelse ($topSenders as $addr => $n)
                    <tr><td style="width:45%;">{{ $addr }}</td>
                        <td><div class="bar" style="width:{{ round($n / $maxS * 100) }}%;background:#059669;"></div></td>
                        <td class="mono" style="text-align:right;width:50px;">{{ $n }}</td></tr>
                @empty
                    <tr><td class="muted">Keine Daten im Zeitraum.</td></tr>
                @endforelse
            </table>
        </div>

        {{-- Bestand --}}
        <div class="card">
            <strong>Bestand</strong>
            <table class="plain">
                <tr><td>Nachrichten im Portal</td><td class="mono" style="text-align:right;">{{ number_format($storeCount, 0, ',', '.') }}</td></tr>
                <tr><td>Belegter Speicher</td><td class="mono" style="text-align:right;">{{ number_format($storeBytes / 1048576, 1, ',', '.') }} MB</td></tr>
                <tr><td>Partner-Zertifikate aktiv</td><td class="mono" style="text-align:right;">{{ number_format($certsPartner, 0, ',', '.') }}</td></tr>
                <tr><td>davon automatisch geerntet</td><td class="mono" style="text-align:right;">{{ number_format($certsHarvested, 0, ',', '.') }}</td></tr>
            </table>
        </div>

        {{-- Ablaufende Zertifikate --}}
        <div class="card">
            <strong>Zertifikate, die in 60 Tagen ablaufen</strong>
            @if ($expiring->isEmpty())
                <p class="muted">Keine. ✓</p>
            @else
                <table class="plain">
                    @foreach ($expiring as $c)
                        <tr>
                            <td>{{ $c->target }}<br><span class="muted" style="font-size:12px;">{{ $c->type === 'own' ? 'eigenes' : 'Partner' }} · {{ $c->source === 'harvested' ? 'geerntet' : 'hochgeladen' }}</span></td>
                            <td class="mono" style="text-align:right;">{{ $c->valid_until->format('d.m.Y') }}<br><span class="muted" style="font-size:12px;">in {{ (int) now()->diffInDays($c->valid_until, false) }} T.</span></td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    <style>
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:14px; margin-bottom:14px; }
        .card.stat { text-align:center; padding:16px 10px; }
        .card.stat .num { font-size:28px; font-weight:700; }
        .card.stat .lbl { color:#6b7280; font-size:12.5px; margin-top:2px; }
        .chart { display:flex; align-items:flex-end; gap:2px; height:170px; margin-top:14px; padding-bottom:18px; position:relative; }
        .chart .col { flex:1; display:flex; flex-direction:column-reverse; position:relative; min-width:3px; border-radius:2px 2px 0 0; overflow:visible; }
        .chart .seg { width:100%; }
        .chart .xlbl { position:absolute; bottom:-18px; left:0; font-size:10.5px; color:#9ca3af; white-space:nowrap; }
        .legend { display:flex; gap:16px; flex-wrap:wrap; margin-top:10px; font-size:12.5px; color:#4b5563; }
        .legend i, .dot { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:5px; }
        .two-col { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:14px; }
        .donut { width:140px; height:140px; border-radius:50%; margin:14px auto; display:flex; align-items:center; justify-content:center; }
        .donut .hole { width:80px; height:80px; border-radius:50%; background:#fff; }
        table.plain { width:100%; border-collapse:collapse; margin-top:10px; font-size:13.5px; }
        table.plain td { padding:5px 4px; border-bottom:1px solid #f0f1f3; vertical-align:middle; }
        .bar { height:10px; background:#1d4e89; border-radius:3px; min-width:2px; }
    </style>
</div>
