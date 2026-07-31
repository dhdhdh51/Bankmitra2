# LRMS Deployment Guide

Loan Recovery Management System — admin panel, REST API and Android app.

Everything here assumes ordinary cPanel/LiteSpeed shared hosting. There is no
Composer step, no build step and no Node toolchain on the PHP side: the code runs
directly from disk.

---

## 1. Requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.2 or newer (8.3+ recommended) |
| MySQL | 5.7+ / 8.0+, or MariaDB 10.3+ |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `gd` |
| Web server | Apache or LiteSpeed with `mod_rewrite` |
| HTTPS | Required in production (the Android app refuses cleartext) |
| Disk | ~200 MB plus uploads and backups |

Check your extensions from cPanel → **Select PHP Version** → Extensions, or run:

```bash
php -m | grep -E 'pdo_mysql|mbstring|openssl|fileinfo|zip|gd'
```

`zip` and `gd` are optional but recommended:

- Without `zip`, Excel exports fall back to CSV (which Excel opens natively) and
  `.xlsx` **uploads** cannot be read — only `.csv`.
- Without `gd`, uploaded image dimensions are not validated.

---

## 2. Database

### 2.1 Create the database

cPanel → **MySQL Databases**:

1. Create a database, e.g. `myaccount_lrms`
2. Create a user with a strong password
3. Add the user to the database with **All Privileges**

### 2.2 Import the schema

> ### `schema.sql` destroys data. Run it once, on an empty database.
>
> Every table in it begins with `DROP TABLE IF EXISTS`, so importing it into a
> database that already holds records **deletes all of them** — every customer,
> visit, promise and user account. It is an install script, not a migration.
>
> Never import it to "refresh" or "repair" a live site. To upgrade, apply the
> migration named in the release notes, and take a backup first:
> `php ~/public_html/cron/backup.php`

cPanel → **phpMyAdmin** → select the database → **Import** → upload `schema.sql`
→ **Go**.

Or from the command line:

```bash
mysql -u USER -p DATABASE < schema.sql
```

This creates 21 tables and seeds the roles, the 36 permissions, the settings
rows, one head-office branch and the bootstrap administrator.

Verify:

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'YOUR_DB';
-- expect 21
```

### 2.3 Bootstrap login

| Field | Value |
| --- | --- |
| Employee code | `ADMIN001` |
| Password | `Admin@123` |

The account is flagged `must_change_password`, so the panel forces a new password
at first sign-in. **Change it immediately.**

To set a different initial password, generate a hash and update the row:

```bash
php -r "echo password_hash('YourStrongPassword', PASSWORD_BCRYPT), PHP_EOL;"
```

```sql
UPDATE users SET password_hash = '<paste hash>' WHERE employee_code = 'ADMIN001';
```

---

## 3. Admin panel

### 3.1 Upload

The **contents of `admin/`** go into your web root — not the `admin` folder
itself.

**Option A — the `hosting` branch (easiest)**

That branch *is* the web root: it holds the contents of `admin/` at its top
level, plus `schema.sql` and the docs, and nothing else. No Android project, no
test harnesses, no CI files.

```bash
cd ~
git clone -b hosting https://github.com/dhdhdh51/Bankmitra2.git lrms
cp -r lrms/. public_html/
rm -rf public_html/.git
```

Or download the branch ZIP from GitHub (*Code → Download ZIP* with the `hosting`
branch selected) and upload the extracted contents.

To rebuild that branch from source after a change:

```bash
sh tools/make-hosting-package.sh
LRMS_DOCROOT=.verify/hosting sh tools/smoke-panel.sh   # verify before publishing
```

**Option B — Git from source**

```bash
cd ~
git clone https://github.com/dhdhdh51/Bankmitra2.git lrms-src
cp -r lrms-src/admin/. public_html/
```

**Option C — ZIP of the full repository**

Download the repository ZIP, extract locally, and upload everything inside
`admin/` to `public_html/` with File Manager or FTP. Make sure hidden files
(`.htaccess`) are included.

Afterwards `public_html/` should contain:

```
public_html/
├── index.php          ← front controller
├── .htaccess
├── app/               ← application code (blocked from the web)
├── config/            ← credentials and keys (blocked from the web)
├── views/             ← templates (blocked from the web)
├── assets/            ← CSS and JS (public)
├── cron/              ← scheduled scripts
├── storage/           ← logs, backups, import files (blocked)
└── uploads/           ← photos and signatures (served only via PHP)
```

### 3.2 Configuration

```bash
cd public_html/config
cp config.sample.php config.php
```

Generate three independent keys:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # run three times
```

Edit `config.php`:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'myaccount_lrms',
    'user' => 'myaccount_lrmsuser',
    'pass' => 'your-db-password',
],

'app_key'     => '<key 1>',   // signs JWTs
'data_key'    => '<key 2>',   // encrypts mobile / Aadhaar
'hash_pepper' => '<key 3>',   // derives the search hashes

'app' => [
    'url'       => 'https://lrms.yourbank.com',
    'base_path' => '',          // e.g. 'lrms' if installed in a sub-directory
    'debug'     => false,       // MUST stay false in production
],
```

> **Back these three keys up somewhere safe.**
>
> Changing `data_key` makes every stored mobile number and Aadhaar number
> permanently unreadable. Changing `hash_pepper` breaks search on those fields.
> There is no recovery path — this is the point of encrypting them.

### 3.3 Permissions

```bash
cd public_html
chmod 755 uploads storage storage/logs storage/backups storage/imports
chmod 644 config/config.php
```

If your host runs PHP as a different user than the file owner, `775` may be
needed on `uploads` and `storage`. Never use `777`.

### 3.4 Sub-directory installs

To serve from `https://yourbank.com/lrms/`:

1. Set `'base_path' => 'lrms'` in `config.php`
2. Uncomment and set `RewriteBase /lrms` in `.htaccess`

### 3.5 First run

Visit `https://your-domain.com/` and sign in. Then:

1. **Settings** → fill in the required fields (the dashboard shows a
   *Missing Configuration* banner until they are set)
2. **Branches** → create your branches — the branch **code** is what the Excel
   importer matches against
3. **Managers & Agents** → create branch managers and BC/DC agent accounts
4. **Excel Import** → upload your first lead file

---

## 4. Settings reference

All of these live in the database and take effect on the next request. No file
edits, no re-upload.

| Group | Purpose |
| --- | --- |
| General | App name, bank name, published app version, page size, timezone |
| Security | OTP expiry, login attempt limit, lockout, JWT TTL, refresh TTL, minimum password length |
| SMS Gateway | Provider, URL template, API key, sender ID, OTP message |
| Email (SMTP) | Host, port, credentials, encryption, from address |
| Integrations | Google Maps key, Firebase server key and project ID |
| Notifications | Promise reminder lead time, follow-up threshold |
| Backup | Retention days, `mysqldump` path |

### SMS gateway

The gateway is a URL template, which covers most Indian aggregators without a
provider-specific SDK. Supported placeholders: `{mobile}`, `{message}`, `{key}`,
`{sender}`.

```
https://api.msg91.com/api/sendhttp.php?authkey={key}&mobiles={mobile}&message={message}&sender={sender}&route=4&country=91
```

Until SMS is configured, OTP password reset is unavailable and users must be
reset by an administrator from **Managers & Agents → Reset password**.

---

## 5. Scheduled jobs (cron)

cPanel → **Cron Jobs**. Use the absolute path to your PHP binary
(`which php`, often `/usr/local/bin/php`).

**Nightly database backup, 2:00 AM**

```
0 2 * * * /usr/local/bin/php /home/USER/public_html/cron/backup.php >> /home/USER/lrms-backup.log 2>&1
```

**Daily reminders, 7:00 AM**

```
0 7 * * * /usr/local/bin/php /home/USER/public_html/cron/reminders.php >> /home/USER/lrms-reminders.log 2>&1
```

`reminders.php` sends promise reminders, flags overdue promises and nudges agents
about leads with no recent visit. It is idempotent — running it twice in a day
will not send the same reminder twice — so a duplicated cron entry is harmless.

Both scripts refuse to run over HTTP; they are CLI-only.

---

## 6. Android app

### 6.1 CI build (recommended)

`.github/workflows/build-android.yml` builds on every push to `main`, `feat/**`
and `fix/**`, on pull requests, and on manual dispatch:

1. GitHub → **Actions** → *Build Android APK* → **Run workflow**
2. Download the artefact from the run summary and install it with
   `adb install -r <file>.apk`, or copy it to the phone and open it

What you get depends on whether release signing secrets are configured:

| Secrets | Artefact | Installable |
|---|---|---|
| configured | `lrms-release-*` → `app-release.apk`, signed and R8-minified | yes |
| not configured | `lrms-debug-*` → `app-debug.apk`, debug-signed | yes |

CI never uploads an unsigned release APK, because **Android cannot install one**.
When no keystore is available the debug APK is built instead, so the artefact is
always something you can put on a phone. The run summary states which one it is.

> **Actions must be enabled for the repository.** If the *Actions* tab shows
> nothing and `/actions/workflows` reports no workflows, turn it on under
> Settings → Actions → General → *Allow all actions*. Workflows are also only
> triggered on branches matching the list above, and `workflow_dispatch` requires
> the workflow file to exist on the repository's **default branch**.

### 6.2 Local build

Requires JDK 17 and the Android SDK.

```bash
cd android
./gradlew testDebugUnitTest      # 69 unit tests
./gradlew assembleDebug          # app/build/outputs/apk/debug/
./gradlew assembleRelease        # app/build/outputs/apk/release/
```

Or use the helper, which installs the SDK on first run:

```bash
sh tools/verify-android.sh
```

### 6.3 Pointing the app at your server

The API base URL is configurable at the login screen — tap **Change server** and
enter:

```
https://your-domain.com/api/v1/
```

The trailing slash is required. On first launch the field is shown automatically.

To bake in a default instead, edit `android/app/build.gradle.kts`:

```kotlin
buildConfigField("String", "DEFAULT_API_BASE_URL", "\"https://lrms.yourbank.com/api/v1/\"")
```

> HTTPS is mandatory in release builds. Cleartext is permitted only for
> `localhost`, `127.0.0.1` and `10.0.2.2` (the emulator's host alias), so a
> developer can test against a local PHP server.

### 6.4 Signing a release build

Create a keystore once and keep it safe — losing it means you can never update
an app published under that key.

```bash
keytool -genkeypair -v -keystore lrms-release.jks \
  -keyalg RSA -keysize 2048 -validity 10000 -alias lrms
```

Create `android/keystore.properties` (git-ignored):

```properties
storeFile=lrms-release.jks
storePassword=...
keyAlias=lrms
keyPassword=...
```

> **Use the same value for `storePassword` and `keyPassword`.**
>
> Since Java 9 `keytool` creates **PKCS12** keystores, which encrypt the private
> key with the *store* password and cannot hold a separate key password. If you
> pass `-keypass`, keytool prints `Different store and key passwords not
> supported for PKCS12 KeyStores. Ignoring user-specified -keypass value` and
> uses the store password anyway. Put the ignored value in `keystore.properties`
> and the build dies with:
>
> ```
> com.android.ide.common.signing.KeytoolException: Failed to read key lrms from
> store "...": Get Key failed: Given final block not properly padded.
> ```
>
> which never mentions passwords. If you genuinely need different passwords, add
> `-storetype JKS` when creating the keystore.

`assembleRelease` picks the signing config up automatically when this file is
present, and produces an unsigned APK when it is not — so CI never fails on a
missing keystore.

#### Signing in GitHub Actions

Add four repository secrets (Settings → Secrets and variables → Actions) and
`build-android.yml` recreates `keystore.properties` on the runner, signs the
release APK, verifies it with `apksigner`, and deletes the keystore afterwards:

| Secret | Value |
|---|---|
| `KEYSTORE_BASE64` | `base64 -w0 lrms-release.jks` |
| `KEYSTORE_PASSWORD` | the `-storepass` you chose |
| `KEY_ALIAS` | `lrms` |
| `KEY_PASSWORD` | same as `KEYSTORE_PASSWORD` for a PKCS12 keystore |

```bash
base64 -w0 lrms-release.jks > keystore.b64   # paste the contents as KEYSTORE_BASE64
```

**Without the secrets the workflow builds the debug APK**, not a release APK.
That is deliberate: `assembleRelease` with no signing config produces
`app-release-unsigned.apk`, and Android will not install an unsigned APK, so
publishing one as "the build artefact" would hand you a file you cannot use. The
debug APK is signed with the auto-generated debug key and installs immediately.
The run summary says which of the two you got.

The workflow also fails early, with an explanation, rather than after a long
build if `KEYSTORE_BASE64` is set but any other secret is missing, if the decoded
keystore cannot be opened, if the alias is absent, or if the key password cannot
work for the keystore format.

Differences between the debug and release builds, so the debug APK is not a
surprise: application ID `com.lrms.recovery.debug` (it installs alongside a
release build rather than replacing it), version name suffixed `-debug`, no R8
minification, and cleartext HTTP allowed so it can talk to a local dev server.

### 6.5 Firebase push (optional)

In-app notifications work without Firebase. Push only makes them arrive sooner.

1. Create a Firebase project, add an Android app with the applicationId
   `com.lrms.recovery`
2. Put the **server key** into Settings → Integrations → *Firebase server key*
3. Add `google-services.json` to `android/app/`, then add the Google Services
   plugin and `firebase-messaging` dependency

The app registers its device token at sign-in, so once the server key is set,
push starts working without an app change.

---

## 7. Verification

Everything in the repository is covered by runnable checks.

| Command | What it proves |
| --- | --- |
| `php tools/selftest-core.php` | 91 checks — crypto, JWT, XLSX, PDF, validator, paginator |
| `sh tools/verify-schema.sh` | 24 checks — 21 tables, 39 FKs, InnoDB, utf8mb4, seeds, the seeded bcrypt login |
| `sh tools/integration-test.sh` | 264 checks — import, visits, promises, reports, backup |
| `sh tools/verify-cron.sh` | 20 checks — the nightly backup restores, reminders are idempotent |
| `sh tools/verify-apache.sh` | 27 checks — `.htaccess` under a real Apache: deny rules, HTTPS, Bearer auth |
| `sh tools/smoke-panel.sh` | 114 panel + 158 API checks over real HTTP |
| `sh tools/verify-android.sh` | 69 unit tests, debug + release APK |
| `sh tools/verify-signing.sh` | 19 checks — release signing works, and the unsigned fallback really is uninstallable |
| `php tools/crossvalidate.php .verify && python3 tools/crossvalidate.py .verify` | Generated XLSX opens in openpyxl, PDF opens in pypdf |

The Docker-based scripts need Docker; the rest need only PHP.

### Local development server

```bash
sh tools/smoke-panel.sh    # boots MySQL + PHP, seeds demo data, runs everything
```

For a browsable instance with demo data (3 branches, 6 users, 60 leads,
40 visits):

```bash
php tools/seed-demo.php
php -S 127.0.0.1:8099 -t admin tools/router-dev.php
```

Demo sign-ins: `ADMIN001` / `Admin@123`, `MGR001` / `Manager@123`,
`AGT001`–`AGT004` / `Agent@123`.

---

## 8. Security checklist

Before going live:

- [ ] `'debug' => false` in `config.php`
- [ ] The three keys are unique, random, and backed up
- [ ] `ADMIN001`'s password has been changed
- [ ] HTTPS is enforced (the `.htaccess` redirect is active)
- [ ] `config/config.php` is `644`, owned by your user
- [ ] `https://your-domain.com/config/config.php` returns 404
- [ ] `https://your-domain.com/app/Core/Database.php` returns 404
- [ ] `https://your-domain.com/uploads/` returns 403 or 404
- [ ] A nightly backup cron is scheduled and has produced a file
- [ ] Each branch manager is assigned to exactly one branch

### What the app does *not* do

By design, and worth confirming against your compliance requirements:

- No payment collection and no payment gateway. Agents record verification and
  follow-up only.
- No GPS, no live location, no attendance, no map tracking. The Android app
  requests **no location permission at all**.
- Visit history is append-only. There is no code path that edits or deletes a
  submitted visit report.

---

## 9. Troubleshooting

**Start here:** upload nothing extra — the hosting package already contains a
self-check page. Open `https://your-domain.com/diag.php?i-understand=1`. It
verifies the PHP version and extensions, the upload layout, file permissions,
`mod_rewrite`, the config keys, and the database connection and table count, then
prints the specific fix for whatever is wrong. It never prints credentials or
keys. **Delete `diag.php` once the site works.**

**403 Forbidden on every page**
Permissions, in almost every case. The web server must be able to read files and
*enter* directories:

```bash
cd ~/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 755 storage uploads
```

Measured behaviour, reproduced against a real Apache: a document root at `700`
returns exactly this bare 403. And do **not** reach for `chmod 777` — hosts
running suPHP or suexec refuse to serve a group- or world-writable directory and
answer 403, so the usual reflex for a permissions problem causes this one.

Other causes, in order of likelihood: ModSecurity blocking the request (ask the
host to check its audit log), a `Require`/`Deny` rule in a parent `.htaccess`
such as `~/.htaccess`, or hotlink protection enabled in cPanel.

**404 on every page**
You uploaded the folder instead of its contents. `index.php` must sit directly in
`public_html`, not in `public_html/admin/` or `public_html/lrms/`. Reproduced:
that layout answers 404 for every URL, whereas a permissions fault answers 403 —
so the status code tells you which of the two you have.

**"Configuration missing" page**
`config/config.php` does not exist. Copy it from `config.sample.php`.

**500 error on every page**
Set `'debug' => true` temporarily and reload — the error page then shows the
exception. Also check `storage/logs/php-error.log`. Set it back to `false`.

**Login page loads but signing in does nothing**
Almost always the session cookie. If the site is not yet on HTTPS, set
`'secure' => false` under `session` in `config.php`.

**Android app: "Cannot reach the server"**
Confirm the base URL ends with `/api/v1/`, that HTTPS works in a browser, and
that `https://your-domain.com/api/v1/ping` returns JSON.

**Android app: 401 on everything, while the admin panel works fine**
The `Authorization` header is not reaching PHP. Apache reserves that header for
its own authentication and does **not** forward it to a FastCGI/CGI/LSAPI
backend, which is how PHP runs on practically every cPanel host, so this is the
default behaviour rather than an unusual fault.

`.htaccess` handles it two ways — a `mod_setenvif` rule and a `mod_rewrite`
rule placed *before* the front-controller rule (it must come first: that rule
ends in `[L]`, which stops processing). Confirm with:

```bash
curl -s -H 'Authorization: Bearer test' \
     'https://your-domain.com/diag.php?i-understand=1' | grep Authorization
```

Expect `Authorization reaches PHP  yes`. If it says otherwise, both modules are
disabled on your host; add this as the first line of `.htaccess`:

```apache
CGIPassAuth On
```

That needs Apache 2.4.13 or newer. It is not the default here because an unknown
directive in `.htaccess` is an immediate 500 on older Apache.

**`.xlsx` upload rejected**
`ZipArchive` is missing. Enable the `zip` extension, or upload `.csv`.

**Excel import maps the wrong columns**
Header names are matched loosely, but a merged title row above the headers is
only skipped when the header row has two or more populated cells. Download the
template from **Excel Import → Download template** to compare.

**Import says "Branch does not exist"**
The row's Branch value matches no branch code or name. Create the branch, or pick
a fallback branch on the upload form.

**Backup produces a 0-byte file**
`exec()` is disabled, so `mysqldump` is unavailable. The pure-PHP fallback should
handle this automatically; if it still fails, check that `storage/backups` is
writable.

**Reports show no data for "today"**
The database server's timezone differs from the app's. The app pins the MySQL
session timezone to `app.timezone` on connect, so confirm that setting matches
the timezone your agents work in.

---

## 10. Upgrading

```bash
cd ~/lrms-src
git pull
cp -r admin/. ~/public_html/
```

`config.php`, `uploads/` and `storage/` are never overwritten — the first is
git-ignored and the others are not tracked.

If a release adds schema changes, apply the migration noted in its release notes
before copying the files. Take a backup first:

```bash
php ~/public_html/cron/backup.php
```

> **Do not re-import `schema.sql` as part of an upgrade.** It begins each table
> with `DROP TABLE IF EXISTS` and would delete every record you have. Only ever
> run it on a fresh, empty database.

### AGP 9.x

The Android build is pinned to AGP 8.13 (the last of the 8.x line, supporting up
to API 36.1). AGP 9.x is available but is a major release with API removals and
built-in Kotlin handling. Migrating means, at minimum: removing the explicit
Kotlin Android plugin, moving `kotlinOptions` fully to `compilerOptions`, and
re-checking every Gradle plugin for 9.x compatibility. Pinning to 8.13 keeps CI
reproducible; upgrade deliberately, not incidentally.
