#!/bin/bash
# SecWay - naechtliches Backup (Cron, root)
# Sichert DB, Konfiguration, Schluessel (APP_KEY!) und verschluesselte Nachrichten.
# WICHTIG: Das Backup-Verzeichnis sollte zusaetzlich extern gesichert werden.
# Konfiguration: /etc/secway.conf (Vorlage: ops/secway.conf.example)

set -u
. /etc/secway.conf || exit 1
DATE=$(date +%F)
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

FAIL=""

# Datenbank
if ! mariadb-dump --single-transaction "$DB_NAME" | gzip > "$BACKUP_DIR/db-$DATE.sql.gz"; then
    FAIL+="Datenbank-Dump fehlgeschlagen"$'\n'
fi

# Konfiguration + Schluessel + verschluesselte Nachrichten
BACKUP_PATHS=(
    "$APP_DIR/.env"
    /etc/secway.conf
    /etc/postfix
    /etc/nginx/sites-available
    /etc/letsencrypt
    /etc/cron.d
    /etc/fail2ban
    /usr/local/sbin/mgw-backup.sh
    /usr/local/sbin/mgw-health.sh
    /usr/local/sbin/mgw-queue-helper.sh
    "$APP_DIR/storage/app/messages"
)
# Signaturblock-Bilder liegen auf der Laravel-"local"-Disk (storage/app/private).
# Optional: Das Verzeichnis entsteht erst mit dem ersten Bild-Upload.
[ -d "$APP_DIR/storage/app/private/signatures" ] \
    && BACKUP_PATHS+=("$APP_DIR/storage/app/private/signatures")
# Quarantäne (zurückgehaltene eingehende S/MIME-Mails), optional
[ -d "$APP_DIR/storage/app/held" ] \
    && BACKUP_PATHS+=("$APP_DIR/storage/app/held")

if ! tar --warning=none -czf "$BACKUP_DIR/files-$DATE.tar.gz" "${BACKUP_PATHS[@]}" 2>/dev/null; then
    FAIL+="Datei-Backup fehlgeschlagen"$'\n'
fi

# Aufbewahrung
find "$BACKUP_DIR" -type f -mtime +"$BACKUP_KEEP_DAYS" -delete

if [ -n "$FAIL" ]; then
    printf 'Subject: [SecWay] BACKUP-FEHLER\nFrom: %s\nTo: %s\n\n%s\n' "$ALERT_FROM" "$ALERT_TO" "$FAIL" \
        | /usr/sbin/sendmail "$ALERT_TO"
fi
