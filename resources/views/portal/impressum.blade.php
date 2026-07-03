@extends('portal.layout')

@section('title', 'Impressum')

@section('content')
    <h1>Impressum</h1>

    <h2>Anbieter gemäß § 5 DDG</h2>
    <p>
        Stiftung „Kinder- und Jugendheim St. Raphael Unterdeufstetten"<br>
        Marktstraße 2<br>
        74579 Fichtenau-Unterdeufstetten
    </p>

    <h2>Vertreten durch</h2>
    <p>Stefan Reuter (Geschäftsführer)</p>

    <h2>Kontakt</h2>
    <p>
        Telefon: (07962) 71284-0<br>
        Telefax: (07962) 71284-30<br>
        E-Mail: <a href="mailto:info@straphael.de">info@straphael.de</a>
    </p>

    <h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
    <p>Stefan Reuter, Anschrift wie oben</p>

    <h2>Zweck dieses Dienstes</h2>
    <p class="muted">
        Diese Seite dient ausschließlich der sicheren Übermittlung vertraulicher Nachrichten
        der Stiftung St. Raphael an externe Empfänger. Eine darüber hinausgehende Nutzung
        ist nicht vorgesehen. Informationen zur Verarbeitung personenbezogener Daten finden
        Sie in der <a href="{{ url('/datenschutz') }}">Datenschutzerklärung</a>.
    </p>
@endsection
