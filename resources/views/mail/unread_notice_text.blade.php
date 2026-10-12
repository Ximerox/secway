Guten Tag,

Ihre sichere Nachricht "{{ $subject }}" vom {{ $sentAt->format('d.m.Y H:i') }} wurde am {{ $expiredAt->format('d.m.Y') }} automatisch gelöscht. Folgende Empfänger haben sie NICHT abgerufen:

@foreach ($unread as $email)
- {{ $email }}
@endforeach

Falls die Information noch benötigt wird, senden Sie die Nachricht bitte erneut (Betreff wieder mit dem Kennzeichen für sicheren Versand) oder nehmen Sie auf anderem Weg Kontakt auf.

Diese Benachrichtigung wurde automatisch erstellt.
