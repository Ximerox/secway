# SecWay „Sicher versenden?" — Outlook-Add-in

Ereignisbasiertes Outlook-Add-in (OnMessageSend): fragt den Absender beim Senden,
ob eine potenziell sensible Mail „sicher" (mit Betreff-Tag) versendet werden soll.
Läuft in Outlook Desktop (Windows/Mac), OWA und der Outlook-App (Android/iOS).

## Funktionsweise

1. Beim „Senden" sammelt das Add-in **Betreff, Klartext-Text, Anhang-Dateinamen und
   Empfängeradressen** — **keine** Inline-Bilder, **keine** Anhangsinhalte.
2. Es ruft `POST https://<host>/api/classify` auf (Bearer-Token). SecWay bewertet die
   Mail nach den in *Admin → Verschlüsselung → Sicher versenden* gepflegten Regeln.
3. Liegt der Score über dem Schwellwert, erscheint eine neutrale Ja/Nein-Frage. Bei
   „Sicher versenden" setzt das Add-in den aktuellen Tag (z. B. `####`) in den Betreff.
4. Sonderfälle ohne Frage: Betreff enthält den Tag bereits · alle Empfänger haben ein
   S/MIME-Zertifikat · SecWay nicht erreichbar (dann wird normal gesendet — fail-open).

## Dateien

| Datei | Zweck |
|---|---|
| `manifest.xml` | Add-in-Manifest (im M365 Admin Center hochladen) |
| `src/commands.html` | lädt office.js + classify.js (Runtime) |
| `src/classify.js` | OnMessageSend-Handler |
| `src/dialog.html` | die Ja/Nein-Frage |

## Einrichtung

1. **Dateien hosten** unter `https://<host>/addin/` (bei SecWay: `public/addin/`, per
   nginx ausgeliefert). `manifest.xml`-URLs und `SECWAY_URL` in `classify.js` auf den
   eigenen Host anpassen.
2. In `classify.js` **`SECWAY_TOKEN`** auf den Wert von `MGW_CLASSIFY_TOKEN` (SecWay-`.env`)
   setzen. Hinweis: Das Token liegt clientseitig im Add-in; für den Pilot vertretbar, da die
   API nur ein Ja/Nein liefert und keine Inhalte protokolliert. Für den breiten Rollout
   sollte auf SSO/Identity-Token-Austausch umgestellt werden.
3. In `manifest.xml` eine eigene **GUID** als `<Id>` eintragen.
4. **M365 Admin Center → Einstellungen → Integrierte Apps → Benutzerdefinierte App
   hochladen → Manifest** hochladen und zunächst nur der Pilot-Person/Gruppe zuweisen.
5. In SecWay unter *Sicher versenden* das **Modul aktivieren** und Regeln prüfen.

## Grenzen

Das Add-in ist eine Erinnerung, kein Torwächter: Es greift nur in Outlook/OWA/Outlook-App,
nicht bei Fremd-Clients oder serverseitigen Weiterleitungsregeln. Die endgültige
Verantwortung bleibt beim Absender.
