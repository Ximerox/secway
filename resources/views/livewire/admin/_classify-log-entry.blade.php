{{-- Ein Klassifizierungs-Log mit Einzelwertung + Inhalt (Add-in-Diagnose und
     nachgelagerte Prüfung teilen dieses Markup). Erwartet: $d (SendClassifyLog),
     $threshold (Vergleichsschwelle), $overLabel/$underLabel (Badge-Texte). --}}
<details style="margin-top:12px; border:1px solid #e5e7eb; border-radius:8px; padding:0;">
    <summary style="cursor:pointer; padding:10px 14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <span class="mono" style="white-space:nowrap;">{{ $d->created_at->format('d.m. H:i') }}</span>
        <span>Score <strong>{{ $d->score }}</strong></span>
        @if ($d->asked)<span class="badge warn">{{ $overLabel }}</span>@else<span class="badge off">{{ $underLabel }}</span>@endif
        <span class="muted">{{ $d->external_count }} externe/{{ $d->recipient_count }} Empf.</span>
        <span class="muted" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:340px;">{{ $d->debug_subject ?: '(ohne Betreff)' }}</span>
    </summary>
    <div style="padding:4px 14px 14px;">
        <table class="plain" style="margin-bottom:10px;">
            <thead><tr><th>Regel</th><th style="text-align:right;">Beitrag</th></tr></thead>
            <tbody>
            @foreach ($d->debug_rules ?? [] as $rr)
                <tr @if(($rr['contribution'] ?? 0) > 0) style="background:#fffbeb;" @endif>
                    <td>
                        {{ $rr['name'] ?? $rr['type'] ?? '?' }} <span class="muted">({{ $rr['type'] ?? '' }})</span>
                        @if (($rr['type'] ?? '') === 'llm')
                            <br><span class="muted" style="font-size:12px;">
                            @if (($rr['llm_available'] ?? false))
                                KI-Urteil: <strong>{{ ($rr['llm_sensibel'] ?? false) ? 'ja (sensibel)' : 'nein' }}</strong>, KI-Wert {{ $rr['llm_score'] ?? '?' }}@if(($rr['llm_factor'] ?? 0) > 0) · Faktor {{ $rr['llm_factor'] }}%@endif
                            @else
                                KI-Dienst nicht verfügbar
                            @endif
                            </span>
                        @endif
                    </td>
                    <td style="text-align:right;">@if(($rr['contribution'] ?? 0) > 0)<strong>+{{ $rr['contribution'] }}</strong>@else<span class="muted">0 / {{ $rr['max'] ?? '?' }}</span>@endif</td>
                </tr>
            @endforeach
            <tr><td style="text-align:right;"><strong>Summe</strong></td><td style="text-align:right;"><strong>{{ $d->score }}</strong> {{ $d->score >= $threshold ? '≥' : '<' }} {{ $threshold }}</td></tr>
            </tbody>
        </table>
        @if (!empty($d->debug_attachments))
            <div style="margin-bottom:8px;"><strong>Anhänge:</strong> <span class="mono">{{ implode(', ', $d->debug_attachments) }}</span></div>
        @endif
        <div><strong>Betreff:</strong> {{ $d->debug_subject ?: '(leer)' }}</div>
        <div style="margin-top:6px;"><strong>Text:</strong></div>
        <pre style="white-space:pre-wrap; word-break:break-word; background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; padding:10px; font-size:12.5px; max-height:340px; overflow:auto;">{{ $d->debug_body }}</pre>
    </div>
</details>
