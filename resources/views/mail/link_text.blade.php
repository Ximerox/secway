Guten Tag,

{{ $senderName }} ({{ $senderEmail }}) hat Ihnen eine vertrauliche Nachricht über die sichere Nachrichtenübermittlung von {{ \App\Models\Setting::operator() }} gesendet.

Zum Öffnen benötigen Sie ein Kennwort, das Ihnen in einer separaten E-Mail zugestellt wird.

Nachricht abrufen:
{{ $url }}

Die Nachricht ist bis zum {{ $expiresAt->format('d.m.Y') }} abrufbar und wird danach automatisch gelöscht.
