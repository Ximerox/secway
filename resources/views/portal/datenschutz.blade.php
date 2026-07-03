@extends('portal.layout')

@section('title', 'Datenschutzerklärung')

@section('content')
    <h1>Datenschutzerklärung</h1>
    <p class="muted">für das Portal zur sicheren Nachrichtenübermittlung (mailgateway.straphael.de)</p>

    <h2>1. Verantwortlicher</h2>
    <p>
        Stiftung „Kinder- und Jugendheim St. Raphael Unterdeufstetten"<br>
        Marktstraße 2, 74579 Fichtenau-Unterdeufstetten<br>
        Telefon: (07962) 71284-0 · E-Mail: <a href="mailto:info@straphael.de">info@straphael.de</a>
    </p>

    <h2>2. Datenschutzbeauftragter</h2>
    <p>
        Dirk Fromm<br>
        Bergfeldstraße 11, 83607 Holzkirchen<br>
        Telefon: +49 89 7167211-30 · E-Mail: <a href="mailto:dirk.fromm@ce21.de">dirk.fromm@ce21.de</a>
    </p>

    <h2>3. Zweck und Rechtsgrundlage der Verarbeitung</h2>
    <p>
        Dieses Portal dient der sicheren Zustellung vertraulicher Nachrichten und Dateien an Sie,
        wenn eine durchgehend verschlüsselte E-Mail-Zustellung (S/MIME) an Ihre Adresse nicht möglich ist.
        Rechtsgrundlage ist unser berechtigtes Interesse an der vertraulichen Übermittlung
        schutzbedürftiger Inhalte (Art. 6 Abs. 1 lit. f DSGVO) sowie – soweit die Kommunikation der
        Erfüllung eines Vertrags oder rechtlicher Pflichten dient – Art. 6 Abs. 1 lit. b und c DSGVO.
    </p>

    <h2>4. Verarbeitete Daten</h2>
    <p>
        <strong>Nachrichtendaten:</strong> Ihre E-Mail-Adresse, der Nachrichteninhalt und etwaige Anhänge.
        Diese werden auf unserem eigenen Server verschlüsselt gespeichert. Das Abruf-Kennwort wird
        ausschließlich als kryptografischer Hash abgelegt.<br>
        <strong>Nutzungsdaten:</strong> Zeitpunkte des Abrufs und der Downloads sowie – in technischen
        Protokollen zur Missbrauchs- und Angriffserkennung – die IP-Adresse Ihres Zugriffs.<br>
        <strong>Cookies:</strong> Es wird ausschließlich ein technisch notwendiges Sitzungs-Cookie
        verwendet. Es findet kein Tracking und keine Analyse statt.
    </p>

    <h2>5. Speicherdauer</h2>
    <p>
        Nachrichten und Anhänge werden nach Ablauf der beim Versand mitgeteilten Abruffrist
        automatisch und unwiederbringlich gelöscht. Technische Protokolle werden nach spätestens
        90 Tagen gelöscht.
    </p>

    <h2>6. Empfänger und Drittlandübermittlung</h2>
    <p>
        Die Verarbeitung erfolgt ausschließlich auf einem von der Stiftung selbst betriebenen Server
        in Deutschland. Es findet keine Weitergabe an Dritte und keine Übermittlung in Drittländer statt.
    </p>

    <h2>7. Verschlüsselung</h2>
    <p>
        Die Verbindung zu diesem Portal ist per TLS verschlüsselt. Gespeicherte Nachrichteninhalte
        und Anhänge sind zusätzlich serverseitig verschlüsselt.
    </p>

    <h2>8. Ihre Rechte</h2>
    <p>
        Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17),
        Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) sowie Widerspruch
        gegen die Verarbeitung (Art. 21 DSGVO). Wenden Sie sich dazu an die oben genannten Kontaktdaten.
    </p>
    <p>
        Sie haben außerdem das Recht auf Beschwerde bei einer Datenschutz-Aufsichtsbehörde,
        insbesondere beim Landesbeauftragten für den Datenschutz und die Informationsfreiheit
        Baden-Württemberg, Lautenschlagerstraße 20, 70173 Stuttgart.
    </p>

    <h2>9. Bereitstellungspflicht</h2>
    <p class="muted">
        Sie sind weder gesetzlich noch vertraglich verpflichtet, dieses Portal zu nutzen oder Daten
        bereitzustellen. Ohne den Abruf können wir Ihnen die vertrauliche Nachricht jedoch nicht
        auf diesem Weg zustellen.
    </p>
@endsection
