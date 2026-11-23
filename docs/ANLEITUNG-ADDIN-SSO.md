# Anleitung: Entra-App für die Outlook-Add-ins (SSO statt festem Token)

Stand: 12.10.2026. Vorlage: Buchhofadmin (dort seit 11.10.2026 produktiv).

**Problem:** `public/addin/classify.js` („Sicher versenden?") und
`public/addin/sig/signature.js` („SecWay Signatur") enthalten feste Bearer-Tokens
(`MGW_CLASSIFY_TOKEN`, `MGW_SIGNATURE_TOKEN`). Beide Dateien sind öffentlich
abrufbar. Mit den Tokens kann jeder
- über `POST /api/signature` Name, Funktion und Telefon zu jeder internen Adresse abfragen,
- über `POST /api/classify` das Sprachmodell auf dem Gateway beschäftigen.

**Ziel:** Die Add-ins holen per Single Sign-on ein Entra-Token des angemeldeten
Benutzers. Der Server prüft das Token. Im JavaScript steht kein Geheimnis mehr.

Eine App-Registrierung reicht für beide Add-ins, weil beide unter
`secway.example.org` ausgeliefert werden.

---

## 1. App-Registrierung anlegen

`entra.microsoft.com` → Identität → Anwendungen → App-Registrierungen → Neue Registrierung

| Feld | Wert |
|---|---|
| Name | `SecWay Outlook-Add-ins` |
| Kontotypen | Nur Konten in diesem Organisationsverzeichnis |
| Umleitungs-URI | vorerst leer |

Eine **eigene** App anlegen, weder die Graph-App noch die Admin-Login-App
wiederverwenden. Die Add-in-App ist ein öffentlicher Client und braucht **kein Secret**.

## 2. Authentifizierung

Authentifizierung → Plattform hinzufügen → **Single-Page-Anwendung**
- Umleitungs-URI: `brk-multihub://secway.example.org`
  (exakt so: ohne Pfad, ohne Schrägstrich am Ende)
- Implizite Gewährung: nichts anhaken
- Öffentliche Clientflows zulassen: **Nein**

## 3. Eine API verfügbar machen

1. Anwendungs-ID-URI: `api://secway.example.org/<Client-ID>` (genau dieses Format)
2. Bereich hinzufügen:
   - Name `access_as_user`
   - Zustimmung: Administratoren und Benutzer
   - Anzeigename: `SecWay-Add-ins verwenden`
   - Beschreibung: „Erlaubt den Outlook-Add-ins, im Namen des angemeldeten
     Benutzers Signatur und Versandprüfung von SecWay abzurufen."
   - Status: aktiviert
3. Autorisierte Clientanwendung hinzufügen: `ea5a67f6-b6f3-4338-b240-c655ddc3cc8e`
   (Microsoft Office), Bereich `access_as_user` anhaken.

## 4. Token-Version

Manifest der App-Registrierung: `"requestedAccessTokenVersion": 2`
(ältere Ansicht: `"accessTokenAcceptedVersion": 2`).

## 5. API-Berechtigungen und Zustimmung

| API | Typ | Berechtigung |
|---|---|---|
| Microsoft Graph | Delegiert | `User.Read`, `openid`, `profile`, `offline_access` |
| Meine APIs → SecWay Outlook-Add-ins | Delegiert | `access_as_user` |

Danach **„Administratorzustimmung erteilen"** klicken. Ohne diese Zustimmung
scheitert der Abruf im ereignisbasierten Modus still, weil dort kein Dialog
angezeigt werden kann.

## 6. Zuweisung

Unternehmensanwendungen → SecWay Outlook-Add-ins → Eigenschaften →
„Zuweisung erforderlich" = **Nein**, solange alle Mitarbeitenden die Add-ins haben.

## 7. Diese Werte brauche ich

- Tenant-ID
- Client-ID der neuen App

---

## Danach (Claude)

- Add-ins: MSAL.js (Nested App Authentication) selbst gehostet,
  `OfficeRuntime.auth.getAccessToken` als Rückfall, `<WebApplicationInfo>` in
  beiden Manifesten, Versionsnummer erhöhen.
- Server: JWT-Prüfung gegen die Tenant-Schlüssel (aud, iss, tid, exp, scp).
  Übergangsweise werden SSO-Token **und** die alten Tokens angenommen.
- Signatur: Jeder angemeldete Mitarbeitende bekommt Signaturen auch für
  Funktionspostfächer. SecWay kennt die Postfachrechte nicht; die Daten sind
  intern ohnehin im Adressbuch sichtbar.

## Danach (du)

1. Beide neuen Manifeste im Microsoft 365 Admin Center → Integrierte Apps
   aktualisieren.
2. 24–48 h warten. Outlook im Web hält alte Manifeste teils länger (bei Buchhof
   Fehler 13000 bis zum Umstieg auf NAA).
3. Testen in Outlook Classic, New Outlook und OWA, je mit eigenem Postfach und
   einem Funktionspostfach.
4. Danach `MGW_CLASSIFY_TOKEN` und `MGW_SIGNATURE_TOKEN` in `.env` leeren, dann
   `php artisan config:cache`. Damit sind die alten Tokens wertlos.
