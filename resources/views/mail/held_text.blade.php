SecWay - Mail zurückgehalten

Eine eingehende S/MIME-verschlüsselte Mail konnte mit keinem der
hinterlegten eigenen Zertifikate entschlüsselt werden und wurde
zurückgehalten:

Von:     {{ $held->sender }}
An:      {{ implode(', ', $held->recipients) }}
Betreff: {{ $held->subject ?: '(ohne Betreff)' }}
Größe:   {{ number_format($held->size_bytes / 1024, 0, ',', '.') }} KB
@if ($held->diagnosis)
Benötigt: {{ $held->diagnosis }}
@endif
Frist:   {{ $held->hold_until->format('d.m.Y H:i') }} Uhr

So geht es weiter: Das passende Zertifikat (mit privatem Schlüssel,
Typ "eigenes") unter Admin -> Zertifikate hochladen - die Mail wird
dann automatisch entschlüsselt und zugestellt (Prüfung alle 15 Minuten,
oder sofort über "Erneut entschlüsseln"). Ohne Zertifikat wird die Mail
nach Ablauf der Frist unverändert (verschlüsselt) zugestellt.

{{ $url }}
