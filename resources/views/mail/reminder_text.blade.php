Guten Tag,
@if (($final ?? false))

LETZTE ERINNERUNG: Diese Nachricht wird am {{ $expiresAt->format('d.m.Y') }} automatisch und unwiderruflich gelöscht. Bitte rufen Sie sie vorher ab.
@endif

eine vertrauliche Nachricht von {{ $senderName }} ({{ $senderEmail }}) wartet noch auf Ihren Abruf.

Nachricht abrufen:
{{ $url }}

Das benötigte Kennwort haben Sie bereits in einer separaten E-Mail erhalten.

Die Nachricht ist noch bis zum {{ $expiresAt->format('d.m.Y') }} abrufbar und wird danach automatisch gelöscht.
