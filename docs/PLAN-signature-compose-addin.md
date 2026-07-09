# Plan: Signaturblock-Compose-Add-in („Signatur beim Schreiben sichtbar")

Stand: 08.07.2026 · Status: **Schritte 1–3 umgesetzt** (API + Gateway-Skip live, Add-in gebaut+validiert+gehostet; M365-Upload wartet auf stabilen Betrieb von Add-in Nr. 1) · Vorlage für die Umsetzung
(auch durch eine andere Claude-Instanz — dieses Dokument ist die maßgebliche Spezifikation).

## Ziel

Der Signaturblock (Admin → Signaturblöcke) soll bereits **beim Verfassen in Outlook
sichtbar** sein — nicht erst beim Empfänger. Vorbild: CodeTwo „Combo-Modus":
clientseitige Signatur im Add-in **plus** serverseitiges Auffangnetz im Gateway
für alle Clients ohne Add-in (Mobile, Fremd-Clients).

## Grundsatzentscheidungen (mit dem Betreiber am 08.07.2026 getroffen)

1. **Eigenes, zweites Add-in** — getrennt vom bestehenden „Sicher versenden?"-Add-in.
   Grund: Die Compose-Ereignisse müssen im Manifest deklariert werden; jede
   Manifest-Änderung am bestehenden Add-in würde ein M365-Redeployment mit langer
   Propagation erzwingen und das laufende Add-in destabilisieren.
   Eine spätere **Zusammenführung zu einem Add-in** (ein Manifest mit allen
   LaunchEvents) ist möglich und als eigener, späterer Schritt zu planen.
2. **Start erst, wenn das „Sicher versenden?"-Add-in stabil läuft** — keine zwei
   Outlook-Deployment-Baustellen parallel.

## Architektur

```
Outlook (Desktop/OWA)                      SecWay
┌─────────────────────────┐               ┌──────────────────────────────┐
│ OnNewMessageCompose      │──POST───────►│ /api/signature                │
│ OnMessageRecipientsChanged│  sender,     │  → SignatureTemplate::        │
│                          │  recipients  │    applicable(user,recipients)│
│ body.setSignatureAsync() │◄──HTML───────│  → Renderer (forPreview-artig)│
│ internetHeaders:         │               └──────────────────────────────┘
│   X-MGW-Signed: yes      │
└─────────────────────────┘
   Mail geht normal raus ──► Gateway: Header X-MGW-Signed vorhanden?
                              ja  → Signatur-Schritt ÜBERSPRINGEN
                                    (+ Sent-Items-Update überspringen)
                              nein → Block wie bisher serverseitig anfügen
                                     (Mobile/Fremd-Clients = Auffangnetz)
```

### Bausteine im Einzelnen

**1. Neuer API-Endpunkt `POST /api/signature`** (SecWay)
- Analog zu `/api/classify`: Bearer-Token, CSRF-frei (bootstrap/app.php `except`),
  throttle. Eigenes Token ODER Wiederverwendung von `MGW_CLASSIFY_TOKEN` —
  Empfehlung: **eigenes** `MGW_SIGNATURE_TOKEN` (Trennung der Add-ins).
- Input: `{ sender, recipients[] }`. Output: `{ html, none }`.
- Logik: `EntraUser::forSender(sender)` → `SignatureTemplate::applicable(user,
  recipients)` (Engine existiert vollständig!) → Rendering pro Vorlage,
  verkettet in Regel-Reihenfolge.
- **Bild-Strategie (offene Designentscheidung, vor Umsetzung klären):**
  a) Bilder als **base64-data-URIs** einbetten (wie `SignatureRenderer::forPreview()`,
     existiert schon) — Outlook wandelt eingebettete Bilder beim Senden i. d. R.
     selbst in CID-Anhänge um. Kein Nachladen. **Empfohlen als Start.**
  b) Gehostete URLs — widerspricht der Kein-Nachladen-Anforderung des Betreibers.
- Der eingefügte Block MUSS den bekannten Marker enthalten
  (`<!--SECWAY-SIG t{id}-->…<!--/SECWAY-SIG-->`), zusätzlich zum Header
  (Marker kann von Outlook gestrippt werden → Header ist die primäre Kennung).

**2. Add-in Nr. 2** (neues Verzeichnis `outlook-addin-signature/`, gehostet unter
`public/addin/sig/` — WICHTIG: unterhalb von `/addin/`, damit die vorhandene
nginx-Location greift: kein `X-Frame-Options`, `Cache-Control: no-cache`;
siehe Lehren unten)
- **Eigene GUID** (nicht die des ersten Add-ins), eigener Name („SecWay Signatur"),
  eigene Icons (make-icons.php wiederverwendbar).
- Manifest: `VersionOverrides 1.1`, `Requirements Mailbox ≥ 1.10`
  (für `setSignatureAsync`), LaunchEvents:
  - `OnNewMessageCompose` (feuert auch bei Antworten/Weiterleiten)
  - `OnMessageRecipientsChanged`
  - KEIN OnMessageSend, KEIN SendMode — dieses Add-in kann den Versand
    **konstruktionsbedingt nie blockieren**.
- Handler-Ablauf:
  1. Absender (`Office.context.mailbox.userProfile.emailAddress`) + Empfänger
     (to/cc/bcc, getAsync **objektgebunden** aufrufen!) einsammeln.
  2. `POST /api/signature`; Timeout 4 s; bei Fehler/Timeout: NICHTS tun
     (Gateway hängt dann serverseitig an — fail-safe).
  3. `item.body.setSignatureAsync(html, {coercionType: Html})` — verwaltet den
     Signaturbereich, ersetzt bei erneutem Aufruf (Empfängerwechsel) sauber.
  4. `item.internetHeaders.setAsync({ "X-MGW-Signed": "yes" })` — NUR setzen,
     wenn Schritt 3 erfolgreich war. Bei `none` (keine Regel passt):
     `setSignatureAsync("")` + Header entfernen (`removeAsync`).
- `OnMessageRecipientsChanged`: identischer Ablauf (neu bewerten, Signatur
  tauschen, Header aktualisieren).

**3. Gateway-Anpassung** (SignatureMailService / MailIngest)
- Vor dem Signatur-Schritt: Header `X-MGW-Signed: yes` vorhanden?
  → Signatur-Schritt überspringen, Header ENTFERNEN (interner Marker, soll
  nicht zum Empfänger), Protokoll `signature_skipped` mit Grund
  „im Client angefügt (Add-in)". Ggf. stattdessen eigenes Event
  `signature_client` für saubere Statistik.
- **Sent-Items-Update überspringen**, wenn Header gesetzt war — die
  Gesendet-Kopie enthält den Block ja bereits (großer Nebengewinn: kein
  Graph-Roundtrip für Add-in-Nutzer).
- Sicherheitsüberlegung: Der Header ist von außen fälschbar (jemand könnte ihn
  setzen, um die Signatur zu unterdrücken) — Risiko akzeptabel (bewirkt nur
  fehlenden Fuß), zumal nur interne Absender überhaupt in den Signatur-Schritt
  kommen. Im Plan erwähnen, im Code kommentieren.

**4. Betreiber-Schritte (nicht automatisierbar)**
- Lokale Outlook-Signaturen der Mitarbeiter deaktivieren (sonst doppelter Fuß):
  OWA: `Set-OwaMailboxPolicy -SignaturesEnabled $false`; Desktop klassisch:
  GPO/Registry `DisableSignatures`; New Outlook folgt der Roaming-Signatur —
  vor Rollout testen und dokumentieren.
- M365 Admin Center: Manifest hochladen, zuerst NUR Pilotnutzer d.moeller.
- `MGW_SIGNATURE_TOKEN` in `.env` erzeugen; Token in die gehostete JS-Datei
  injizieren (sed-Muster wie beim ersten Add-in; Repo = Platzhalter,
  public/addin/sig/ = echt, gitignored).

## Lehren aus dem ersten Add-in (VERBINDLICH einhalten!)

1. **Niemals blockierende SendModes** — dieses Add-in hat keinen Send-Handler.
2. **Hosting:** unter `/addin/…` lassen (nginx-Location liefert ohne
   X-Frame-Options und mit `Cache-Control: no-cache` — XFO hatte das erste
   Add-in unsichtbar gekillt; Office lädt alles in iframes!).
3. **Office.js-Async-Methoden nie als losgelöste Funktionszeiger** aufrufen
   (this-Bindung; hat im ersten Add-in den Handler zum Absturz gebracht).
4. **Caching/Propagation:** Manifest-`<Version>` bei JEDER Änderung hochzählen;
   Versions-Query (`?v=…`) an JS-Dateien in HTML-Wrappern; Änderungen an der
   Laufzeit brauchen Manifest-Update im Admin Center + bis zu 72 h Propagation.
   Deshalb: Erst LOKAL vollständig durchdenken, selten deployen.
5. Manifest vor Upload validieren: `npx office-addin-manifest validate <datei>`
   (Pflichtfelder u. a. Icons, SupportUrl, Rule; Reihenfolge
   Permissions→Rule→DisableEntityHighlighting→VersionOverrides).

## Bekannte Grenzen (bewusst akzeptiert)

- **Mobile/Fremd-Clients:** kein Compose-Add-in → Block kommt wie bisher erst am
  Gateway dran; via Postausgang-Tausch nachträglich in „Gesendet" sichtbar.
- **Gemischte Empfänger:** Client und Gateway nutzen DIESELBE Engine
  (`applicable(user, recipients)`) → identisches Ergebnis, ein Block pro Mail
  (heutige Semantik: „extern, sobald ein Externer dabei", Include/Exclude-Regeln).
- Nutzer können den eingefügten Block im Entwurf manuell verändern/löschen —
  dann geht die Mail ggf. mit veraltetem/ohne Block raus, Header sagt aber
  „signiert". Akzeptiert (wie bei CodeTwo); wer den Fuß löscht, will das so.

## Reihenfolge der Umsetzung (wenn gestartet wird)

1. `/api/signature` + Gateway-Skip (Header) + Tests — rein serverseitig,
   gefahrlos deploybar, Modul-unabhängig testbar (curl).
2. Add-in Nr. 2 bauen (Manifest, Handler), lokal validieren.
3. Hosting `public/addin/sig/` + Token, M365-Upload, Pilot nur d.moeller.
4. Realtest Desktop/OWA → Empfängerwechsel-Test → gemischte Empfänger.
5. Lokale Signaturen für Pilotnutzer deaktivieren, Feinschliff.
6. Breiter Rollout; später optional: Zusammenführung beider Add-ins in ein
   Manifest (neue Version des ERSTEN Add-ins mit zusätzlichen LaunchEvents,
   dann Add-in Nr. 2 entfernen — Achtung, wieder Propagation).

## Offene Entscheidungen vor Start

- Bild-Strategie bestätigen (base64-data-URIs, s. o.).
- Eigenes Token vs. gemeinsames (Empfehlung: eigenes).
- Statistik: eigenes Protokoll-Event `signature_client` oder skipped-Grund.
