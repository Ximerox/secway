#!/usr/bin/env bash
#
# SecWay — application setup helper for Debian 12/13.
#
# Automates the deterministic, server-side parts of the install: packages,
# database, .env + APP_KEY, TinyMCE, migrations, first admin user and the ops
# scripts. It does NOT touch your MTA/Web config or external systems — the
# site-specific wiring (nginx vhost + certbot, Postfix content filter, Exchange
# Online connectors/rule and the optional Entra app) is printed at the end and
# documented in docs/INSTALL.md.
#
# Usage:  sudo bash deploy/install.sh
#
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Bitte als root ausfuehren (sudo)."; exit 1; }
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"
command -v php >/dev/null || { echo "PHP fehlt — bitte zuerst PHP 8.3+ installieren."; exit 1; }

TINYMCE_VERSION="6.8.6"

ask() { local p="$1" d="${2:-}" a; read -r -p "$p${d:+ [$d]}: " a; echo "${a:-$d}"; }
rnd()  { openssl rand -hex 32; }
set_env() {  # set_env KEY VALUE  (updates or appends in .env, value written verbatim)
  local k="$1" v="$2"
  if grep -qE "^${k}=" .env; then
    php -r '$k=$argv[1];$v=$argv[2];$f=".env";$l=file($f);foreach($l as &$x){if(preg_match("/^".preg_quote($k,"/")."=/",$x)){$x=$k."=".$v."\n";}}file_put_contents($f,implode("",$l));' "$k" "$v"
  else
    printf '%s=%s\n' "$k" "$v" >> .env
  fi
}

echo "== SecWay-Installation in $APP_DIR =="

# --- 1. Angaben einsammeln --------------------------------------------------
DOMAIN=$(ask "Portal-Domain (FQDN)" "secway.example.org")
OPERATOR=$(ask "Betreibername (fuer Empfaenger sichtbar)" "Example GmbH")
INTERNAL=$(ask "Interne Domain(s), kommagetrennt" "example.org")
DB_NAME=$(ask "Datenbankname" "secway")
DB_USER=$(ask "Datenbankbenutzer" "secway")
DB_PASS=$(ask "Datenbank-Passwort (leer = generieren)" "")
[ -n "$DB_PASS" ] || { DB_PASS=$(openssl rand -base64 18); echo "  -> generiertes DB-Passwort: $DB_PASS"; }
ADMIN_USER=$(ask "Admin-Benutzername" "Admin")

# --- 2. Pakete --------------------------------------------------------------
echo "== Pakete installieren =="
apt-get update -qq
apt-get install -y nginx php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-intl php8.4-gd php8.4-bcmath \
  mariadb-server composer certbot python3-certbot-nginx postfix git fail2ban \
  unattended-upgrades swaks curl

# --- 3. Datenbank -----------------------------------------------------------
echo "== Datenbank anlegen (falls nicht vorhanden) =="
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# --- 4. Anwendung -----------------------------------------------------------
echo "== Composer-Abhaengigkeiten =="
composer install --no-dev --optimize-autoloader --no-interaction

[ -f .env ] || cp .env.example .env
set_env APP_URL "https://${DOMAIN}"
set_env DB_DATABASE "${DB_NAME}"
set_env DB_USERNAME "${DB_USER}"
set_env DB_PASSWORD "\"${DB_PASS}\""
set_env MGW_OPERATOR_NAME "\"${OPERATOR}\""
set_env MGW_INTERNAL_DOMAINS "${INTERNAL}"
set_env MGW_PHP_BINARY "$(command -v php)"
grep -qE '^APP_KEY=.+' .env || php artisan key:generate --force
INGEST_SECRET=$(grep -E '^MGW_INGEST_SECRET=.+' .env | cut -d= -f2- || true)
[ -n "$INGEST_SECRET" ] || { INGEST_SECRET=$(rnd); set_env MGW_INGEST_SECRET "${INGEST_SECRET}"; }

# --- 5. TinyMCE (Signaturblock-Editor) --------------------------------------
echo "== TinyMCE ${TINYMCE_VERSION} laden =="
curl -sL "https://registry.npmjs.org/tinymce/-/tinymce-${TINYMCE_VERSION}.tgz" | tar -xz -C /tmp
mkdir -p public/vendor && rm -rf public/vendor/tinymce && mv /tmp/package public/vendor/tinymce

# --- 6. Migration, Caches, Rechte -------------------------------------------
echo "== Migration & Caches =="
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data "$APP_DIR"

# --- 7. Erster Admin --------------------------------------------------------
ADMIN_PASS=$(openssl rand -base64 12)
php artisan tinker --execute="\App\Models\User::firstOrCreate(['username' => '${ADMIN_USER}'], ['name' => 'Administrator', 'email' => 'admin@${DOMAIN}', 'password' => '${ADMIN_PASS}']);"

# --- 8. Betriebsskripte -----------------------------------------------------
echo "== Ops-Skripte installieren =="
install -m 750 ops/mgw-health.sh ops/mgw-backup.sh ops/mgw-queue-helper.sh /usr/local/sbin/
[ -f /etc/secway.conf ] || { install -m 600 ops/secway.conf.example /etc/secway.conf; echo "  -> /etc/secway.conf anlegt — bitte Werte pruefen!"; }
if [ ! -f /etc/cron.d/secway ]; then
  sed "s#/var/www/secway#${APP_DIR}#g" ops/cron.example > /etc/cron.d/secway
  chmod 644 /etc/cron.d/secway
fi

# --- Fertig -----------------------------------------------------------------
cat <<DONE

============================================================================
 SecWay-Grundinstallation abgeschlossen.

   Admin-Login:   Benutzer  ${ADMIN_USER}
                  Kennwort  ${ADMIN_PASS}   (danach unter Admin -> Konto aendern)
   X-MGW-Auth:    ${INGEST_SECRET}
                  (identischer Wert in der Exchange-Transportregel als Header!)

 NOCH MANUELL zu erledigen (siehe docs/INSTALL.md):
   * nginx-vhost auf ${APP_DIR}/public + TLS via certbot
     (Vorlage: deploy/nginx-secway.conf.example)
   * Postfix: main.cf/master.cf-Content-Filter (Abschnitt 5),
     /etc/secway.conf-Werte pruefen
   * Exchange Online: In-/Outbound-Connector + Transportregel (Abschnitt 6),
     Header X-MGW-Auth = obiger Wert
   * Optional Signaturblock-Modul: Entra-App + GRAPH_* in .env (Abschnitt 7)
   * fail2ban-Jails aktivieren (Abschnitt 9)
============================================================================
DONE
