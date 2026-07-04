{{ \App\Models\Setting::operator() }} · Antwort über das Portal

Guten Tag,

{{ $externalEmail }} hat über das Sicherheitsportal auf Ihre Nachricht
"{{ $originalSubject }}" vom {{ $sentAt->format('d.m.Y H:i') }} Uhr geantwortet:

----------------------------------------
{{ $replyText }}
----------------------------------------
@if (count($fileNames))

Anhänge ({{ count($fileNames) }}): {{ implode(', ', $fileNames) }}
(automatisch auf Schadsoftware geprüft)
@endif

Antworten Sie NICHT direkt auf diese E-Mail — sie stammt vom Portal,
nicht vom Absender. Senden Sie stattdessen eine neue E-Mail mit dem
Sicherheits-Tag im Betreff an {{ $externalEmail }}, damit auch Ihre
Antwort wieder geschützt zugestellt wird.
