#!/bin/sh
# SecWay - arbeitet Loesch-Anforderungen aus dem Admin-UI ab (minutlich, root).
# Der Webserver-Nutzer darf postsuper nicht selbst ausfuehren; er schreibt nur
# validierte Queue-IDs in die Request-Datei, die hier erneut geprueft werden.
# Konfiguration: /etc/secway.conf (Vorlage: ops/secway.conf.example)

. /etc/secway.conf || exit 1
REQ="$APP_DIR/storage/app/queue-delete.req"
[ -s "$REQ" ] || exit 0
TMP=$(mktemp)
mv "$REQ" "$TMP"
while IFS= read -r id; do
  case "$id" in
    "") ;;
    *[!A-F0-9]*) logger -t mgw-queue "verworfen (ungueltige ID): $id" ;;
    *) postsuper -d "$id" 2>&1 | logger -t mgw-queue ;;
  esac
done < "$TMP"
rm -f "$TMP"
