# LRMS — hosting package

**Upload-ready build.** Everything in this branch goes directly into your web
root. There is no build step, no Composer, and nothing to compile.

This branch is generated from the source branch by
`sh tools/make-hosting-package.sh` — do not edit files here and expect the
changes to survive. Fix things in the source branch and rebuild.

## What is in here

```
index.php          front controller — every request enters here
.htaccess          pretty URLs, security headers, PHP hardening
app/               application code            (blocked from the web)
config/            credentials and keys        (blocked from the web)
views/             page templates              (blocked from the web)
assets/            CSS and JS                  (public)
cron/              scheduled scripts
storage/           logs, backups, import files (blocked from the web)
uploads/           photos and signatures       (served only through PHP)
schema.sql         database schema and seed data
DEPLOYMENT.md      the full deployment guide
```

Development files — the Android app, the test harnesses, CI workflows — are
deliberately absent. Nothing here needs them.

## Install in five steps

**1. Upload.** Put the *contents* of this branch into `public_html/`, including
the hidden `.htaccess` files. Via SSH:

```bash
git clone -b hosting https://github.com/dhdhdh51/Bankmitra2.git lrms
cp -r lrms/. public_html/
rm -rf public_html/.git
```

Or download the branch ZIP from GitHub and upload the extracted contents with
File Manager or FTP.

**2. Create the database** in cPanel → *MySQL Databases*, then import
`schema.sql` in phpMyAdmin (select the database first, then the **Import** tab).

> **Only once, on an empty database.** `schema.sql` starts every table with
> `DROP TABLE IF EXISTS`, so importing it again later **deletes everything** —
> all customers, visits and promises. It installs; it does not upgrade.

**3. Configure.** Copy the sample and edit it:

```bash
cp config/config.sample.php config/config.php
```

Fill in the database name, user and password, set `app.url` to your domain, and
generate the three secrets:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # app_key
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # data_key
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # hash_pepper
```

> **`data_key` and `hash_pepper` can never be changed** once real customer data
> exists. `data_key` decrypts stored mobile numbers, Aadhaar numbers and
> addresses; `hash_pepper` derives the search hashes. Losing or altering either
> makes existing PII unreadable. Back them up somewhere safe and separate from
> the database backups.

**4. Permissions.** Directories `755`, files `644`, and these must be writable
by PHP:

```bash
chmod -R 755 storage uploads
```

**5. Log in** at `https://your-domain.com/` with `ADMIN001` / `Admin@123`. You
are forced to set a new password immediately. Do that before anything else.

## Something wrong? Run the self-check first

```
https://your-domain.com/diag.php?i-understand=1
```

It checks the PHP version and extensions, the upload layout, file permissions,
`mod_rewrite`, whether `config.php` is filled in, and whether it can connect to
the database and see all the tables — then prints the specific fix for anything
that is wrong. It never prints your credentials or keys.

**Delete `diag.php` once the site works.**

### If you get 403 Forbidden

Almost always permissions. The web server must be able to read and enter every
directory:

```bash
cd ~/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 755 storage uploads
```

**Never use 777.** cPanel hosts running suPHP or suexec refuse to serve a
group- or world-writable directory and answer 403 — so `chmod 777`, the usual
reflex for a permissions problem, actually causes this one.

If you get **404** on every page instead, you uploaded the folder rather than its
contents: `index.php` must sit directly in `public_html`, not in
`public_html/admin/` or `public_html/lrms/`.

## Cron jobs

cPanel → *Cron Jobs*. Adjust the PHP path and home directory to match your host:

```
0 2 * * *   /usr/local/bin/php /home/USER/public_html/cron/backup.php
*/30 * * * * /usr/local/bin/php /home/USER/public_html/cron/reminders.php
```

`backup.php` writes a `.sql` into `storage/backups/` and prunes files older than
the retention window. `reminders.php` raises notifications for promises that are
due or overdue. Both refuse to run over HTTP.

## Requirements

PHP 8.1 or newer with `pdo_mysql`, `mbstring`, `openssl`, `zip`, `gd` and
`fileinfo`; MySQL 8 or MariaDB 10.4+. Apache or LiteSpeed with `mod_rewrite` and
`.htaccess` overrides allowed. Tested against PHP 8.2 and 8.4.

If `mod_rewrite` is unavailable, see *Sub-directory installs* and
*Troubleshooting* in `DEPLOYMENT.md`.

## Updating

Pull the branch again and re-upload, keeping your `config/config.php`,
`storage/` and `uploads/`:

```bash
cd lrms && git pull
rsync -a --exclude config/config.php --exclude storage --exclude uploads \
      ./ ~/public_html/
```

Take a database backup first — the panel has a button for it under *Backup*.

## Security notes

- `config/config.php` is the only file holding secrets. It is blocked from the
  web by `config/.htaccess`, but never make it world-readable either.
- `uploads/` is **not** web-served. Photos, signatures and documents stream
  through PHP after an authentication and branch-scope check, so a guessed
  filename returns 403 rather than someone's Aadhaar photo. Do not "fix" this by
  removing `uploads/.htaccess`.
- Delete the demo accounts (`MGR001`, `AGT001`–`AGT004`) if you seeded them, and
  rotate `ADMIN001`'s password.
- Serve over HTTPS. The Android app refuses cleartext in release builds.

The full checklist is in `DEPLOYMENT.md`, section 8.
