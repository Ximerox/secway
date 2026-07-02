# Secure Mail Gateway

E-Mail-Sicherheits-Gateway für Microsoft 365 / Exchange Online — Ersatz für Ciphermail CE.
Ausgehende Mails werden je nach Empfänger automatisch **S/MIME-verschlüsselt**, über ein
**Abrufportal mit Kennwort** zugestellt oder unverändert durchgeleitet.

**Stack:** Debian 12/13 · Postfix · PHP 8.4 · Laravel 12 · Livewire 3 · MariaDB · Nginx · Let's Encrypt

> **Status: In aktiver Entwicklung / Pilotbetrieb.** Ausgehende Richtung produktiv,
> eingehende S/MIME-Entschlüsselung + Zertifikats-Ernten in Planung. Siehe [Roadmap](#roadmap).

---

## Funktionsweise

```
Outlook/Client ──► Exchange Online ──Transportregel──► Gateway (dieser Server)
                                                          │
                       Entscheidung pro Empfänger:        │
                       1. S/MIME-Zertifikat hinterlegt ──► verschlüsseln (+ signieren) ──► via EXO zustellen
                       2. Tag im Betreff (z.B. [sicher]) ► Portal: Mail verschlüsselt ablegen,
                          Empfänger erhält Link-Mail + Kennwort-Mail, Abruf per HTTPS
                       3. sonst ────────────────────────► unverändert durchleiten ──► via EXO zustellen
```

- **S/MIME:** sign-then-encrypt (CMS, AES-256). Verschlüsselt wird mit dem Empfänger-Zertifikat
  (Adress-Zertifikat vor Domain-Zertifikat). Signiert wird nur, wenn der *Absender* ein eigenes
  Adress-Zertifikat mit privatem Schlüssel besitzt — Domain-Zertifikate signieren nie
  (die Absenderadresse würde nicht zum Zertifikat passen).
- **Portal:** Nachricht + Anhänge werden AES-256-GCM-verschlüsselt gespeichert (eigener
  Datenschlüssel pro Nachricht, eingepackt mit dem Laravel `APP_KEY`). Pro Empfänger eigener
  Abruf-Token (64 Hex) und eigenes Kennwort (bcrypt-Hash). Sperre nach Fehlversuchen,
  automatische Löschung nach Ablauf, Inline-Bilder werden im Portal korrekt dargestellt.
- **Fail-safe:** Scheitert die Verschlüsselung (z.B. abgelaufenes Zertifikat), geht die Mail
  **ins Portal statt im Klartext raus**. Fehlt der Auth-Header, wird die Zustellung verzögert
  (TEMPFAIL) statt Mail zu verwerfen.

## Komponenten

| Pfad | Zweck |
|---|---|
| `app/Console/Commands/MailIngest.php` | Postfix-Content-Filter (`mail:ingest`): Auth-Prüfung, Empfänger-Routing |
| `app/Services/SmimeMailService.php` | S/MIME-Engine (signieren, verschlüsseln, durchleiten, Wiedereinspeisung) |
| `app/Services/SmimeCertificateService.php` | Zertifikats-Import (PEM/DER/PKCS#12, Kettenerkennung, Validierung) |
| `app/Console/Commands/MailPurge.php` | Löscht abgelaufene Portalnachrichten (stündlich via Scheduler) |
| `app/Console/Commands/SmimeImport.php` | CLI-Massenimport von Zertifikaten (`php artisan smime:import`) |
| `app/Http/Controllers/PortalController.php` | Empfänger-Portal (Kennwort, Anzeige, Download) |
| `app/Livewire/Admin/*` | Admin-Bereich: Dashboard, Zertifikate, Einstellungen |
| `app/Models/Setting.php` | Datenbankbasierte Einstellungen (Fallback: `config/mailgateway.php`) |
| `app/Support/Crypto.php` | AES-256-GCM für Nachrichteninhalte at rest |

## Admin-Bereich

`https://<gateway-host>/admin` — Login über die `users`-Tabelle.

- **Übersicht:** Statistiken, Audit-Log (jeder Vorgang wird protokolliert: Ingest, Abruf,
  Download, Zertifikatsänderungen, Logins, …)
- **Zertifikate:** Upload PEM/DER/P12 (mit Passwort). Typ *Partner* (nur öffentlicher Schlüssel)
  oder *Eigenes* (mit privatem Schlüssel, verschlüsselt abgelegt). Ziel: Domain oder einzelne
  Adresse. Duplikaterkennung per SHA-256-Fingerprint.
- **Einstellungen:** Auto-Verschlüsselung an/aus, Signieren an/aus, Auslöse-Tag, Aufbewahrungsdauer.

## Installation (Kurzfassung)

> Ausführliche Schritt-für-Schritt-Anleitung folgt vor der Veröffentlichung.

1. **Server:** Debian, statische IP, öffentlicher DNS-Eintrag, Portweiterleitungen 25/80/443.
2. **Pakete:** `nginx php8.4-fpm php8.4-{mysql,mbstring,xml,curl,zip,intl,gd,bcmath} mariadb-server composer certbot python3-certbot-nginx postfix git`
3. **App:** Projekt nach `/var/www/mailgateway`, `.env` konfigurieren (DB, `APP_URL`,
   `MAIL_MAILER=sendmail`, `MGW_INGEST_SECRET=<64 Hex-Zeichen>`), `composer install`,
   `php artisan migrate`, Admin-Benutzer anlegen, Nginx-vHost auf `public/`, Let's-Encrypt-Zertifikat.
4. **Scheduler:** Cron `* * * * * www-data cd /var/www/mailgateway && php artisan schedule:run`
5. **Postfix** (Auszug `main.cf` / `master.cf`):
   ```
   mynetworks = 127.0.0.0/8 [::1]/128 40.92.0.0/15 40.107.0.0/16 52.100.0.0/14 104.47.0.0/17
   smtpd_sender_restrictions = check_sender_access hash:/etc/postfix/sender_access, reject
   relayhost = [<tenant>.mail.protection.outlook.com]:25
   smtpd_tls_cert_file = /etc/letsencrypt/live/<host>/fullchain.pem   (+ key)
   smtp_tls_cert_file  = dito  ← Pflicht! EXO identifiziert den Connector am Client-Zertifikat
   mgwfilter_destination_recipient_limit = 1000

   # master.cf:
   smtp inet n - y - - smtpd -o content_filter=mgwfilter:dummy
   mgwfilter unix - n n - 10 pipe flags=Rq user=www-data
     argv=/usr/bin/php -d pcre.jit=0 /var/www/mailgateway/artisan mail:ingest ${queue_id} ${sender} ${recipient}
   ```
   `pcre.jit=0` ist Pflicht: die systemd-Härtung von Postfix (`MemoryDenyWriteExecute`)
   verbietet PHP sonst den JIT-Speicher.
6. **Exchange Online:**
   - *Ausgehender Connector* → Smarthost = Gateway-Host, nur über Transportregel, TLS erzwingen
   - *Eingehender Connector* (OnPremises) → Identifikation über TLS-Zertifikatsnamen des Gateways
   - *Transportregel:* Bedingung „Absender intern" (+ optionale Pilot-Eingrenzung),
     **keine Betreff-Bedingung** — die Tag-Logik liegt im Gateway!
     Aktionen: Header `X-MGW-Auth` = Secret setzen + über Connector routen.
     Ausnahme: Header `X-MGW-Notification` enthält `yes` (Schleifenschutz — das Gateway
     markiert alles, was es versendet, mit diesem Header).

## Betrieb

- **Monitoring:** `/usr/local/sbin/mgw-health.sh` (Cron alle 5 Min): Dienste, Mailqueue,
  Plattenplatz, Zertifikatslaufzeit, Reboot-Hinweis — Alarm/Entwarnung per E-Mail.
  **Schleifen-Notbremse:** >100 neue Portalnachrichten in 10 Minuten → Postfix wird gestoppt.
- **Backup:** `/usr/local/sbin/mgw-backup.sh` (nächtlich): DB-Dump + `.env` (**enthält den
  `APP_KEY` — ohne ihn sind alle gespeicherten Nachrichten und Schlüssel verloren!**) +
  Postfix/Nginx/Certbot-Konfiguration nach `/var/backups/mailgateway` (14 Tage; extern sichern!).
- **Zertifikats-Renewal:** Certbot-Deploy-Hook lädt Nginx **und Postfix** neu.
- **Härtung:** fail2ban (sshd, postfix; journald-Backend), unattended-upgrades,
  Absender-Whitelist, EXO-IP-Filter + Secret-Header als zweite Schicht.

### Audit-Ereignisse (Auszug)

`ingest_stored`, `recipient_notified`, `unlocked`, `unlock_failed`, `downloaded`, `purged`,
`smime_sent`, `smime_fallback`, `passed_through`, `ingest_rejected` (Auth-Fehler),
`ingest_loop_dropped` (Schleifenschutz), `cert_imported/…`, `settings_changed`, `admin_login/…`

## Roadmap

- [ ] **S/MIME eingehend:** Entschlüsselung mit eigenen Domain-Zertifikaten, Signaturprüfung,
      automatisches **Zertifikats-Ernten** aus gültig signierten Mails
      (EXO-Regel: „Nachrichtentyp ist signiert/verschlüsselt" → Connector)
- [ ] Automatische Verschlüsselung an geerntete Zertifikate → Ciphermail vollständig ablösen
- [ ] Admin: Nachrichten-Übersicht, Audit-Browser, Kennwort-Neuversand, Benutzerverwaltung
- [ ] Web-Formular „sicher senden" (mobiler Fallback / große Anhänge)
- [ ] **Veröffentlichung:** `.env.example`, Installations-Skript/Anleitung, Seeder für
      Admin-Benutzer, Secrets-Prüfung, englische Doku, Lizenz — Ziel: GitHub-tauglich,
      von Dritten nachinstallierbar

## Sicherheitsmodell (Kurzfassung)

1. Nur EXO-Netze dürfen Port 25 erreichen (`mynetworks`), nur Absender der eigenen Domain.
2. Jede zu verarbeitende Mail muss den geheimen Header `X-MGW-Auth` tragen (setzt die
   EXO-Transportregel; verhindert Fremdnutzung durch andere M365-Tenants).
3. Alles, was das Gateway versendet, trägt `X-MGW-Notification: yes`; solche Mails werden
   eingehend sofort verworfen und von der EXO-Regel ausgenommen (doppelter Schleifenschutz).
4. Portalinhalte und private Schlüssel liegen nur verschlüsselt auf der Platte.
5. Kennwort-Fehlversuche sperren temporär; alle Zugriffe werden auditiert.
