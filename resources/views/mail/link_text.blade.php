Guten Tag,

{{ $senderName }} ({{ $senderEmail }}) hat Ihnen eine vertrauliche Nachricht über die sichere Nachrichtenübermittlung von {{ AppModelsSetting::operator() }} gesendet.

Nachricht abrufen:
{{ $url }}

Zum Öffnen benötigen Sie ein Kennwort, das Ihnen in einer separaten E-Mail zugestellt wird.

Die Nachricht ist bis zum {{ $expiresAt->format('d.m.Y') }} abrufbar und wird danach automatisch gelöscht.
