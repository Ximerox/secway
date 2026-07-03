# SecWay — Installation

Step-by-step guide for Debian 12/13. Adjust paths and package names for other distributions.
Throughout this guide the app lives in `/var/www/secway` and the portal is reachable at
`https://secway.example.org`.

## 0. Prerequisites

- A server with a static IP, a public DNS record and open ports **25, 80, 443**
- A mail system in front (this guide assumes **Exchange Online**; any SMTP system that can
  route outbound mail through a smarthost and add a header works)
- Reverse DNS + SPF for the gateway host if it delivers directly; when relaying back through
  Exchange Online (recommended, default), EXO handles outbound reputation

## 1. Packages

```bash
apt install nginx php8.4-fpm php8.4-{mysql,mbstring,xml,curl,zip,intl,gd,bcmath} \
    mariadb-server composer certbot python3-certbot-nginx postfix git fail2ban \
    unattended-upgrades swaks
```

## 2. Database

```bash
mariadb -e "CREATE DATABASE secway CHARACTER SET utf8mb4;
            CREATE USER 'secway'@'localhost' IDENTIFIED BY '<strong password>';
            GRANT ALL ON secway.* TO 'secway'@'localhost';"
```

## 3. Application

```bash
git clone <repo-url> /var/www/secway
cd /var/www/secway
composer install --no-dev --optimize-autoloader
cp .env.example .env            # fill in DB credentials, APP_URL, MGW_* values
php artisan key:generate        # NEVER change APP_KEY once data exists!
openssl rand -hex 32            # -> MGW_INGEST_SECRET in .env
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data /var/www/secway
```

Create the first admin user:

```bash
php artisan tinker --execute="
\App\Models\User::create(['name' => 'Admin', 'email' => 'admin@example.org',
    'password' => bcrypt('<initial password>')]);"
```

## 4. nginx + TLS

Standard Laravel vhost pointing at `/var/www/secway/public`, then:

```bash
certbot --nginx -d secway.example.org
```

**Important:** Postfix uses the same certificate (next section). Add a deploy hook so renewals
reload both services:

```bash
printf '#!/bin/sh\nsystemctl reload nginx postfix\n' \
  > /etc/letsencrypt/renewal-hooks/deploy/reload-services.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-services.sh
```

## 5. Postfix

`main.cf` essentials (`postconf -e ...`):

```
# Only the upstream mail system may talk to us (Exchange Online ranges shown)
mynetworks = 127.0.0.0/8 [::1]/128 40.92.0.0/15 40.107.0.0/16 52.100.0.0/14 104.47.0.0/17
smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination

# Only senders of your own domains (defense in depth, see sender_access)
smtpd_sender_restrictions = check_sender_access hash:/etc/postfix/sender_access, reject

# Deliver processed mail back through the upstream system
relayhost = [<tenant>.mail.protection.outlook.com]:25

# TLS both ways - EXO identifies the inbound connector by this client certificate!
smtpd_tls_cert_file = /etc/letsencrypt/live/secway.example.org/fullchain.pem
smtpd_tls_key_file  = /etc/letsencrypt/live/secway.example.org/privkey.pem
smtp_tls_cert_file  = /etc/letsencrypt/live/secway.example.org/fullchain.pem
smtp_tls_key_file   = /etc/letsencrypt/live/secway.example.org/privkey.pem
smtp_tls_security_level = may

message_size_limit = 52428800
mgwfilter_destination_recipient_limit = 1000
```

`/etc/postfix/sender_access` (then `postmap /etc/postfix/sender_access`):

```
example.org   OK
```

`master.cf` — attach the content filter to the smtpd listener and define the filter service:

```
smtp       inet  n       -       y       -       -       smtpd
    -o content_filter=mgwfilter:dummy

mgwfilter  unix  -       n       n       -       10      pipe flags=Rq
    user=www-data argv=/usr/bin/php -d pcre.jit=0 /var/www/secway/artisan
    mail:ingest ${queue_id} ${sender} ${recipient}
```

> **`pcre.jit=0` is mandatory.** Postfix's systemd hardening (`MemoryDenyWriteExecute`)
> forbids PHP's PCRE JIT memory; without this flag the filter crashes.

Mail the gateway sends itself (notifications, re-injected mail via `sendmail`) enters through
`pickup`, which has no content filter — no loop.

## 6. Exchange Online

1. **Outbound connector** (M365 → gateway): smarthost = `secway.example.org`, used only via
   transport rule, TLS required.
2. **Inbound connector** (gateway → M365): type *OnPremises*, identified by the TLS certificate
   name `secway.example.org` (this is why Postfix presents the Let's Encrypt certificate as
   client certificate).
3. **Transport rule** "route through SecWay":
   - **Condition:** sender is internal. Optionally restrict to a pilot group first.
     **No subject condition** — the tag logic lives in the gateway!
   - **Actions:** set header `X-MGW-Auth` = value of `MGW_INGEST_SECRET`, route via the
     outbound connector.
   - **Exception:** header `X-MGW-Notification` contains `yes` (loop protection — the gateway
     marks everything it sends with this header).
4. For **inbound decryption/harvesting**, add a rule for external S/MIME mail (condition:
   message type is signed or encrypted) routing through the same connector with the same header.

## 7. Cron & operations scripts

```bash
cp ops/mgw-health.sh ops/mgw-backup.sh ops/mgw-queue-helper.sh /usr/local/sbin/
chmod 750 /usr/local/sbin/mgw-*.sh
cp ops/secway.conf.example /etc/secway.conf   # edit values!
cp ops/cron.example /etc/cron.d/secway        # edit APP_DIR!
```

This gives you: the Laravel scheduler (delayed password mails, reminders, expiry purge),
a 5-minute health check with mail alerting and a mail-loop emergency brake, nightly backups
and the privileged helper that executes queue deletions requested from the admin UI.

## 8. fail2ban

Enable the shipped `sshd` and `postfix` jails with the systemd/journald backend in
`/etc/fail2ban/jail.local`.

## 9. Certificates

- **Own certificates** (your domains/addresses, with private key): Admin → Zertifikate →
  upload PFX/PEM, type *Eigenes*. Used for inbound decryption and outbound signatures.
- **Partner certificates** (recipients, public key only): upload manually — or simply let the
  gateway **harvest** them from signed inbound mail.
- Bulk import: `php artisan smime:import <dir>`.

## 10. Smoke test

```bash
# Portal flow: tagged mail to a recipient without certificate
swaks --server 127.0.0.1:25 --from user@example.org --to someone@external.example \
  --header "X-MGW-Auth: <secret>" --header "Subject: [sicher] Test" --body "Hello"
```

Expected: link mail arrives immediately, password mail ~2 minutes later, message readable in
the portal, events visible under Admin → Protokoll. A mail **without** the auth header must be
deferred with `450` and logged as `ingest_rejected`.
