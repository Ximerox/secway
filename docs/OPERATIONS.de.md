# SecWay — Betriebshandbuch

Für den laufenden Betrieb und die Vertretung. Stand: Juli 2026.

## Der Normalfall: nichts tun

SecWay überwacht sich selbst. Alle 5 Minuten prüft ein Health-Check Dienste, Mailqueue,
Plattenplatz und TLS-Zertifikat — **bei Problemen kommt eine Warn-Mail**, bei Behebung eine
Entwarnung. Solange keine Warnung kommt, ist kein Eingriff nötig. Backups laufen nächtlich
um 2:30 Uhr automatisch.

## Admin-Bereich (`https://<gateway>/admin`)

| Seite | Zweck |
|---|---|
| **Statistik** | Startseite: Mailvolumen nach Versandweg, Abrufquote, Top-Domains, ablaufende Zertifikate |
| **Nachrichten** | Im Portal wartende Mails — pro Nachricht *Erinnern* und *Löschen* |
| **Warteschlange** | Postfix-Queue live mit Fehlergrund — *Erneut zustellen* / *Löschen*; darunter wartende Kennwort-Mails mit *Jetzt senden* |
| **Protokoll** | Alle Ereignisse, nach Vorgang gruppiert, mit Richtung/Von/An, filter- und durchsuchbar |
| **Zertifikate** | Eigene und Partner-Zertifikate, Upload, geerntete Zertifikate |
| **Signaturblöcke** | Modul-Schalter, Vorlagen (eigene Editor-Seite mit Tabs), Bilder & QR — serverseitige E-Mail-Fußzeilen aus Entra-Daten |
| **Benutzer** | Aus Entra ID synchronisierte Benutzer (Cache), Sync-Filter, „Jetzt synchronisieren" |
| **Einstellungen** | Alles Konfigurierbare inkl. Betreibername und Impressum/Datenschutz |
| **Konto** | Eigenen Benutzernamen/Anzeigenamen und das Kennwort ändern |

## Warnmeldungen und was zu tun ist

| Meldung | Bedeutung / Maßnahme |
|---|---|
| `Dienst 'x' ist nicht aktiv` | `systemctl start <dienst>`, danach `journalctl -u <dienst>` nach der Ursache |
| `Mailqueue enthält N Einträge` | Admin → Warteschlange: Fehlergrund lesen. Meist ist das vorgelagerte Mailsystem nicht erreichbar — Queue leert sich nach Behebung selbst, sonst *Erneut zustellen* |
| `Festplatte zu X% voll` | Alte Backups/Logs prüfen (`/var/backups`, `storage/logs`), ggf. Aufbewahrung verkürzen |
| `TLS-Zertifikat läuft ab` | `certbot renew --dry-run` prüfen; certbot-Timer sollte automatisch verlängern |
| `NOTBREMSE AUSGELÖST` | Mailschleife vermutet, **Postfix wurde gestoppt**. Transportregeln im Mailsystem prüfen (Ausnahme `X-MGW-Notification` vorhanden?), Ursache beheben, dann `systemctl start postfix` |
| `BACKUP-FEHLER` | `/usr/local/sbin/mgw-backup.sh` manuell laufen lassen und Fehlermeldung lesen |

## Notfall: Gateway fällt aus

Das vorgelagerte Mailsystem (EXO) puffert ausgehende Mails, solange das Gateway nicht
erreichbar ist — es geht nichts verloren, Zustellung verzögert sich nur. Wenn der Ausfall
länger dauert und Mails sofort raus müssen:

1. Im Exchange Admin Center die Transportregel „route through SecWay" **deaktivieren**.
2. Mails laufen dann direkt (unverschlüsselt!) — bewusste Abwägung.
3. Nach Wiederherstellung die Regel wieder aktivieren.

## Backup & Wiederherstellung

**Was gesichert wird** (nächtlich nach `BACKUP_DIR`, Standard 14 Tage): Datenbank-Dump
(inkl. aller Zertifikate und Schlüssel), `.env` (**enthält den `APP_KEY` — ohne ihn sind alle
verschlüsselten Daten unwiederbringlich verloren!**), Postfix-/nginx-/Let's-Encrypt-/fail2ban-
Konfiguration, Cron-Dateien, Betriebsskripte, verschlüsselte Portal-Nachrichten.

> Das Backup-Verzeichnis zusätzlich **extern** sichern (NAS, zweiter Server) — sonst stirbt
> das Backup mit dem Server.

**Wiederherstellung auf frischem Server** (nach Grundinstallation laut INSTALL.md Schritte 1–2):

```bash
# 1. Dateien zurückspielen (enthält .env mit APP_KEY!)
tar -xzf files-<datum>.tar.gz -C /
# 2. Datenbank einspielen
zcat db-<datum>.sql.gz | mariadb secway
# 3. Anwendung (Code) aus dem Git-Repo klonen, composer install, Caches bauen
# 4. Dienste starten, Funktionstest laut INSTALL.md Schritt 10
```

Die Wiederherstellbarkeit wurde am 03.07.2026 vollständig geprobt (inkl. Entschlüsselungs-
nachweis aus dem Backup heraus).

## Updates

```bash
cd /var/www/secway
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data .
```

Betriebssystem-Sicherheitsupdates installiert `unattended-upgrades` automatisch; bei
`Neustart erforderlich`-Hinweis in der Health-Mail zeitnah rebooten.

## Häufige Aufgaben

- **Partner-Zertifikat hinterlegen:** Admin → Zertifikate → Upload (PEM/DER/P12), Typ *Partner*,
  Ziel Adresse oder Domain. Ab sofort wird an diesen Empfänger automatisch verschlüsselt.
- **Empfänger erinnern:** Admin → Nachrichten → *Erinnern* (oder automatisch via Einstellung
  „Erinnerung nach (Stunden)").
- **Kennwort-Mail sofort auslösen:** Admin → Warteschlange → *Jetzt senden*.
- **Impressum/Datenschutz ändern:** Admin → Einstellungen → HTML-Felder unten.
- **Eigenes Kennwort / Benutzername ändern:** Admin → Konto.
- **Weiteren Admin-Benutzer anlegen:** per Tinker (siehe INSTALL.md, „First admin user").
- **Signaturblock anlegen/ändern:** Admin → Signaturblöcke → *Neuer Signaturblock* (Tabs für
  Inhalt, Absender, Empfänger, Zeitraum & Logik, Bilder & QR, Vorschau). Wirkt erst, wenn die
  Vorlage *aktiv* und das Modul oben *eingeschaltet* ist.
- **Entra-Benutzer aktualisieren:** läuft stündlich automatisch; manuell über Admin → Benutzer
  → *Jetzt synchronisieren*.
- **Graph-Client-Secret erneuern:** Das Secret der Entra-App-Registrierung läuft ab (Ablauf im
  Kalender notieren!). Neues Secret in Entra erzeugen, in `.env` (`GRAPH_CLIENT_SECRET`)
  eintragen, dann `php artisan config:cache`. Symptom bei abgelaufenem Secret: Sync/QR schlagen
  fehl, Protokoll zeigt Graph-Fehler.
- **Signaturblock fehlt bei internen Mails / `winmail.dat` im Gesendet-Ordner:** Klassisches
  TNEF-Symptom — Outlook verpackt Mails an interne Postfächer als Rich-Text, der HTML-Body
  steckt dann unzugänglich in der `winmail.dat`. Prüfen/Fix (Exchange Online PowerShell):
  `Set-RemoteDomain -Identity Default -TNEFEnabled $false` (siehe INSTALL, Abschnitt
  Signaturblöcke intern).
- **Raumbuchung/Termine funktionieren nicht:** Die interne Transportregel muss die Ausnahme
  *„Is message type 'Calendaring'"* haben — Kalendernachrichten dürfen nie über das Gateway
  geroutet werden.
