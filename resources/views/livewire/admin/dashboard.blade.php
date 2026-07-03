<div>
    <h1>Übersicht</h1>

    <div class="cards">
        <div class="stat"><div class="num">{{ $messageCount }}</div><div class="lbl">Nachrichten gesamt</div></div>
        <div class="stat"><div class="num">{{ $messageCount7d }}</div><div class="lbl">Nachrichten (7 Tage)</div></div>
        <div class="stat"><div class="num">{{ $unviewedCount }}</div><div class="lbl">Noch nicht abgerufen</div></div>
        <div class="stat"><div class="num">{{ $certCount }}</div><div class="lbl">Partner-Zertifikate aktiv</div></div>
        <div class="stat"><div class="num">{{ $certExpiring }}</div><div class="lbl">Zertifikate laufen in 30 Tagen ab</div></div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Letzte Ereignisse</h2>
        <table>
            <thead><tr><th>Zeitpunkt</th><th>Ereignis</th><th>IP</th><th>Details</th></tr></thead>
            <tbody>
            @forelse ($events as $e)
                <tr>
                    <td>{{ $e->created_at->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $e->event }}</td>
                    <td>{{ $e->ip }}</td>
                    <td class="mono">{{ $e->details ? json_encode($e->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Noch keine Ereignisse.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
