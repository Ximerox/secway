{{-- Detail-Panel eines Zertifikats (aufgeklappte Tabellenzeile) --}}
<tr>
    <td colspan="{{ $span }}" style="background:#f8fafc;padding:16px 14px;">
        <table style="width:auto;font-size:13px;">
            @foreach ($c->details() as $label => $value)
                @if ($value)
                    <tr>
                        <td style="color:#6b7280;padding:3px 18px 3px 0;border:0;white-space:nowrap;vertical-align:top;">{{ $label }}</td>
                        <td class="mono" style="border:0;padding:3px 0;word-break:break-all;">{{ $value }}</td>
                    </tr>
                @endif
            @endforeach
        </table>

        <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn small ghost" wire:click.stop="exportPublic({{ $c->id }})">Öffentliches Zertifikat exportieren (.cer)</button>
            @if ($c->type === 'own' && $c->key_pem)
                <input type="password" wire:model="exportPassword" wire:click.stop
                       placeholder="Export-Passwort (min. 8 Zeichen)"
                       style="max-width:250px;" autocomplete="new-password">
                <button class="btn small danger" wire:click.stop="exportWithKey({{ $c->id }})">Mit privatem Schlüssel exportieren (.p12)</button>
            @endif
        </div>
        @error('exportPassword')<div class="error">{{ $message }}</div>@enderror

        @if ($c->type === 'own' && $c->key_pem)
            <p class="muted" style="margin-top:10px;margin-bottom:0;">
                An Kommunikationspartner geben Sie ausschließlich das <strong>öffentliche</strong> Zertifikat (.cer)
                weiter — damit können sie verschlüsselt an Sie senden. Der Export <strong>mit privatem Schlüssel</strong>
                (.p12, passwortgeschützt) ist nur für Sicherung oder Serverumzug gedacht und darf das Haus nicht verlassen.
            </p>
        @endif
    </td>
</tr>
