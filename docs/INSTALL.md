# SecWay — Installation

Step-by-step guide for Debian 12/13. Adjust paths and package names for other distributions.
Throughout this guide the app lives in `/var/www/secway` and the portal is reachable at
`https://secway.example.org`.

> **Shortcut:** `deploy/install.sh` automates the server-side application setup (packages,
> database, `.env`, `APP_KEY`, TinyMCE, migrations, admin user, ops scripts). Run it first,
> then complete the parts it cannot do for you — nginx/TLS, Postfix, the Exchange Online
> connectors and (optionally) the Entra app — using the sections below. Everything the script
> does is also documented here so you can do it by hand or understand what happened.

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
    unattended-upgrades swaks curl
```

Required PHP extensions: `openssl`, `mbstring`, `xml`/`dom`, `curl`, `mysql` (PDO),
`intl`, `bcmath`, `zip`, and **`gd`** (the last is needed for QR codes in the signature-block
module). `openssl` powers all S/MIME operations and is part of PHP's core build on Debian.

For the optional **portal-reply** feature additionally install `clamav-daemon` +
`clamav-freshclam` (see the "Portal replies" subsection below).

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

### TinyMCE (rich-text editor for signature blocks)

The admin signature-block editor uses TinyMCE, which is **not** bundled in the repository
(it is excluded via `.gitignore`). Download the last MIT-licensed release once into
`public/vendor/tinymce`:

```bash
curl -sL https://registry.npmjs.org/tinymce/-/tinymce-6.8.6.tgz | tar -xz -C /tmp
mkdir -p public/vendor && rm -rf public/vendor/tinymce
mv /tmp/package public/vendor/tinymce
chown -R www-data:www-data public/vendor
```

> TinyMCE 7+ is GPL/commercial; 6.8.x is the last MIT version and is self-hosted here so no
> external CDN or API key is involved. If you don't use the signature-block module you can skip
> this step.

### First admin user

Login is by **username** (not e-mail). Create the first admin — pass the password as
**plaintext**; the model's `hashed` cast hashes it automatically (do **not** wrap it in
`bcrypt()`, that would double-hash and lock you out):

```bash
php artisan tinker --execute="
\App\Models\User::create([
    'username' => 'Admin',
    'name'     => 'Administrator',
    'email'    => 'admin@example.org',
    'password' => '<initial password, min. 10 chars>',
]);"
```

Afterwards change the password anytime under **Admin → Konto**.

### Portal replies (optional)

External recipients can reply to a portal message (text + attachments); the reply is mailed
to the internal sender. Attachments are **always** scanned with ClamAV before delivery —
if the scanner is unreachable, the reply is rejected (fail-closed), so the daemon is a hard
requirement once the feature is on:

```bash
apt install clamav-daemon clamav-freshclam
systemctl enable --now clamav-freshclam clamav-daemon
# first start waits for freshclam to download signatures (~100 MB) — check with:
runuser -u www-data -- clamdscan --fdpass --no-summary /etc/hostname   # expects: OK
```

Raise the upload limits to match the reply size limit you configure in the admin UI
(`upload_max_filesize` / `post_max_size` in `/etc/php/8.4/fpm/php.ini`, then reload
`php8.4-fpm`; `client_max_body_size` in the nginx vhost). Keep in mind the upstream mail
system must also *accept* mails of that size on delivery to the internal sender.

Enable and tune under **Admin → Einstellungen → Portal-Antworten** (off by default):
max total attachment size per reply, max replies per message. Replies are rate-limited
per IP and only possible while the message is unlocked and not expired. The delivered
mail deliberately has **no Reply-To** pointing at the external address — a careless
Outlook reply would bypass the gateway; instead the mail contains a mailto link with
the subject tag preset.

## 4. nginx + TLS

Standard Laravel vhost pointing at `/var/www/secway/public` — a ready-made template is in
[`deploy/nginx-secway.conf.example`](../deploy/nginx-secway.conf.example):

```bash
cp deploy/nginx-secway.conf.example /etc/nginx/sites-available/secway   # edit server_name
ln -s ../sites-available/secway /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
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
4. For **inbound decryption/harvesting**, add a rule for external S/MIME mail routing through
   the same connector with the same header. Match S/MIME reliably via the Content-Type header
   (the built-in "message type" condition only accepts one type per condition):
   - **Conditions:** sender is outside the organization, **and** header `Content-Type` contains
     any of `application/pkcs7-mime`, `application/x-pkcs7-mime`, `multipart/signed`
   - No loop is possible: after decryption/verification the gateway re-injects the mail with a
     normal Content-Type, so the rule no longer matches on the second pass.

## 7. Signature blocks (optional) — Entra app + Microsoft Graph

Skip this section entirely if you don't want server-side e-mail signatures. The module adds a
footer ("signature block") to outbound mail, filled with the sender's attributes from Entra ID
(Microsoft 365). It is disabled by default (Admin → Signaturblöcke → toggle).

**1. Register an app** in [entra.microsoft.com](https://entra.microsoft.com) → *App registrations*
→ *New registration*: single tenant, no redirect URI.

**2. API permissions** → *Add a permission* → *Microsoft Graph* → **Application permissions**,
then **Grant admin consent**:

| Permission | Needed for |
|---|---|
| `User.Read.All` | reading user attributes for placeholders (**required** for the module) |
| `GroupMember.Read.All` | only if you filter the sync or rules by Entra groups |
| `Mail.ReadWrite` | only for the "update Sent Items" feature (replaces the sent copy with the signed version) |

**3. Client secret** → *Certificates & secrets* → *New client secret* (note the expiry date).

**4. Put the three values in `.env`** and re-cache:

```
GRAPH_TENANT_ID=...
GRAPH_CLIENT_ID=...
GRAPH_CLIENT_SECRET=...
```

```bash
php artisan config:cache
php artisan entra:sync        # first user import; afterwards hourly via the scheduler
```

**5. In the admin UI** (Admin → Benutzer) choose which accounts to sync (all, or specific Entra
groups, e.g. a dynamic group scoped to your user OU), then create signature blocks under
Admin → Signaturblöcke and switch the module on.

For **outbound external** mail no extra Exchange rule is required: signature blocks are applied
to mail that already flows through the gateway via the "route through SecWay" rule from
section 6.

### Signature blocks on internal mail (optional)

To also sign **internal** mail (sender and recipient both inside the organization), two extra
steps are required:

**1. Additional transport rule** "internal mail through SecWay":

- **Condition:** *Is received from 'Inside the organization'*
- **Actions:** set header `X-MGW-Auth` = value of `MGW_INGEST_SECRET`, route via the outbound
  connector (optionally *Stop processing more rules*)
- **Exceptions** (all three matter):
  - *Is message type 'Calendaring'* — **essential**: meeting requests and room bookings rely on
    Exchange's native calendar processing; routing them through the gateway breaks room booking.
  - Header `Return-Path` contains `<>` — keeps bounces/system mail out.
  - Header `X-MGW-Notification` contains `yes` — loop protection.

The gateway detects internal non-S/MIME mail and passes it through unchanged apart from the
signature block (it does **not** go through the S/MIME inbound path).

**2. Disable TNEF for outbound SMTP** (Exchange Online PowerShell) — **mandatory**:

```powershell
Set-RemoteDomain -Identity Default -TNEFEnabled $false
```

Without this, Outlook/Exchange encodes mail to internal mailbox recipients as TNEF
(`winmail.dat`) when routing it out through the connector. The HTML body is then locked inside
the TNEF blob: the gateway cannot insert the signature block, recipients get the footer-less
original, and the Sent-Items copy degrades to plain text + `winmail.dat`. Disabling TNEF forces
clean MIME/HTML on the connector (and as a bonus stops `winmail.dat` ever reaching external
recipients).

## 8. Cron & operations scripts

```bash
cp ops/mgw-health.sh ops/mgw-backup.sh ops/mgw-queue-helper.sh /usr/local/sbin/
chmod 750 /usr/local/sbin/mgw-*.sh
cp ops/secway.conf.example /etc/secway.conf   # edit values!
cp ops/cron.example /etc/cron.d/secway        # edit APP_DIR!
```

This gives you: the Laravel scheduler (delayed password mails, reminders, expiry purge, hourly
Entra sync and the per-minute Sent-Items updater when enabled), a 5-minute health check with
mail alerting and a mail-loop emergency brake, nightly backups and the privileged helper that
executes queue deletions requested from the admin UI.

## 9. fail2ban

Enable the shipped `sshd` and `postfix` jails with the systemd/journald backend in
`/etc/fail2ban/jail.local`.

## 10. Certificates

- **Own certificates** (your domains/addresses, with private key): Admin → Zertifikate →
  upload PFX/PEM, type *Eigenes*. Used for inbound decryption and outbound signatures.
- **Partner certificates** (recipients, public key only): upload manually — or simply let the
  gateway **harvest** them from signed inbound mail.
- Bulk import: `php artisan smime:import <dir>`.

## 11. Smoke test

```bash
# Portal flow: tagged mail to a recipient without certificate
swaks --server 127.0.0.1:25 --from user@example.org --to someone@external.example \
  --header "X-MGW-Auth: <secret>" --header "Subject: [sicher] Test" --body "Hello"
```

Expected: link mail arrives immediately, password mail ~2 minutes later, message readable in
the portal, events visible under Admin → Protokoll. A mail **without** the auth header must be
deferred with `450` and logged as `ingest_rejected`.
