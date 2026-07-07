#!/bin/bash
# SecWay - Health-Check (alle 5 Minuten per Cron als root)
# Alarmiert per Mail bei Problemen; alarmiert nur erneut, wenn sich die Lage aendert.
# Konfiguration: /etc/secway.conf (Vorlage: ops/secway.conf.example)

. /etc/secway.conf || exit 1
STATE=/var/lib/mgw-health.state
HOST=$(hostname -f)
ISSUES=""

# 1) Dienste
for s in postfix nginx "$PHP_FPM_SERVICE" mariadb fail2ban; do
    systemctl is-active --quiet "$s" || ISSUES+="Dienst '$s' ist nicht aktiv!"$'\n'
done

# 2) Mailqueue
Q=$(postqueue -p 2>/dev/null | tail -1 | grep -oE '[0-9]+ Request' | grep -oE '[0-9]+')
[ "${Q:-0}" -gt "$QUEUE_ALERT_THRESHOLD" ] && ISSUES+="Mailqueue enthaelt $Q Eintraege (Schwelle: $QUEUE_ALERT_THRESHOLD)"$'\n'

# 3) Plattenplatz
DISK=$(df --output=pcent / | tail -1 | tr -dc '0-9')
[ "${DISK:-0}" -gt "$DISK_ALERT_PCT" ] && ISSUES+="Festplatte / ist zu ${DISK}% voll"$'\n'

# 4) TLS-Zertifikat (Renewal macht certbot; hier nur die Notfall-Warnung)
if ! openssl x509 -checkend $((14*86400)) -noout -in "$TLS_CERT" >/dev/null 2>&1; then
    ISSUES+="TLS-Zertifikat laeuft in <14 Tagen ab - certbot-Renewal pruefen!"$'\n'
fi

# 5) Schleifen-Notbremse: ungewoehnlich viele neue Nachrichten -> Postfix stoppen
N=$(mariadb -N -e "SELECT COUNT(*) FROM $DB_NAME.secure_messages WHERE created_at > NOW() - INTERVAL 10 MINUTE" 2>/dev/null)
if [ "${N:-0}" -gt "$LOOP_BRAKE_THRESHOLD" ]; then
    systemctl stop postfix
    ISSUES+="NOTBREMSE AUSGELOEST: $N neue Nachrichten in 10 Minuten - Mailschleife vermutet, Postfix wurde GESTOPPT. Transportregeln pruefen, dann 'systemctl start postfix'."$'\n'
fi

# 6) Neustart nach Updates noetig?
[ -f /var/run/reboot-required ] && ISSUES+="Hinweis: Neustart nach Sicherheitsupdates erforderlich."$'\n'

# 7) Systempfade muessen root gehoeren - sonst verweigert systemd-tmpfiles beim
#    naechsten Boot die Arbeit (/run/mysqld, /run/php fehlen -> DB/PHP starten
#    nicht). Ist am 07.07.2026 passiert (Kopieraktion hatte Windows-UIDs gesetzt).
for p in / /etc /usr /usr/local /var; do
    OWNER=$(stat -c '%u' "$p" 2>/dev/null)
    [ "${OWNER:-0}" != "0" ] && ISSUES+="Systempfad $p gehoert UID $OWNER statt root - vor dem naechsten Reboot beheben: chown root:root $p"$'\n'
done

if [ -n "$ISSUES" ]; then
    HASH=$(printf '%s' "$ISSUES" | md5sum | cut -d' ' -f1)
    LAST=$(cat "$STATE" 2>/dev/null)
    if [ "$HASH" != "$LAST" ]; then
        printf '%s' "$HASH" > "$STATE"
        printf 'Subject: [SecWay] Warnung auf %s\nFrom: %s\nTo: %s\n\n%s\nZeitpunkt: %s\n' \
            "$HOST" "$ALERT_FROM" "$ALERT_TO" "$ISSUES" "$(date '+%d.%m.%Y %H:%M:%S')" \
            | /usr/sbin/sendmail "$ALERT_TO"
    fi
else
    # Lage wieder gut: Status zuruecksetzen, einmalig Entwarnung senden
    if [ -f "$STATE" ]; then
        rm -f "$STATE"
        printf 'Subject: [SecWay] Entwarnung auf %s\nFrom: %s\nTo: %s\n\nAlle Pruefungen wieder OK.\nZeitpunkt: %s\n' \
            "$HOST" "$ALERT_FROM" "$ALERT_TO" "$(date '+%d.%m.%Y %H:%M:%S')" \
            | /usr/sbin/sendmail "$ALERT_TO"
    fi
fi
