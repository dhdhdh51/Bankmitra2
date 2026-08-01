# D2 Recovery Deployment Guide

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

Fill in the database credentials (below), then set the three keys. If you have no
shell, the packaged helper does it for you in a browser:

```
https://your-domain.com/setup-keys.php?generate=1
```

It writes only keys that are currently blank, backs up the previous
`config/config.php`, validates the rewritten file before swapping it in, and
never prints a key. Delete it afterwards. A blank key is provably unused —
`Crypto::deriveKey()` throws on one, so nothing could have been encrypted with it
— which is why filling a blank in is safe while overwriting a set key is not.

By hand, generate three independent keys:

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

### Email (SMTP) — needed for password-reset codes

Settings → Integrations. With `smtp_host` and `smtp_from_email` filled in, reset
codes go out by email; without them the system falls back to SMS, and with neither
a reset has to be done by an administrator.

| Setting | Example |
|---|---|
| `smtp_host` | `smtp.gmail.com` |
| `smtp_port` | `587` |
| `smtp_username` | the mailbox login |
| `smtp_password` | an **app password**, not the account password |
| `smtp_encryption` | `tls` (or `ssl` on port 465) |
| `smtp_from_email` | `no-reply@yourbank.example` |
| `smtp_from_name` | `D2 Recovery` |

Most shared hosts block outbound port 25 but allow 587. If cPanel provides a mail
account on your own domain, use that — mail from your own domain is far less likely
to be marked as spam than mail claiming to be from Gmail.

Users can sign in with their employee code **or** their email address, so an
account intended for office staff should have `email` set.

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

## 4a. Importing leads from any bank export

**You do not have to reformat the file.** Upload the bank's own `.xlsx` or `.csv`
and the columns are detected. The template under *Import → Download template* is a
convenience, not a requirement.

What is handled without intervention:

| In the file | What happens |
|---|---|
| A title block, a `Branch: … / As on: …` line, a blank spacer above the headings | Candidate rows are scored on how many cells are recognisable headings; the real header row wins |
| A cover sheet or summary pivot before the data | Every worksheet is scored; the one that looks like a lead list is used. Hidden sheets are ignored |
| Columns in any order, with extra columns you do not need | Mapping is by name, not position. Unrecognised columns are listed as ignored |
| `ACCT_NO`, `Loan A/C No.`, `LOAN_ACCOUNT_NUMBER`, `खाता संख्या` | All map to Loan Account Number. Matching is case-, punctuation- and language-insensitive, and tolerates a typo |
| A column with a useless heading like `Column3` | Identity columns are recognised by shape: 12 digits is an Aadhaar, 10 digits starting 6-9 is a mobile, mostly-unique alphanumerics are an account number |
| A branch the database has never heard of | The branch is created from the sheet and reported by name |

**Only two columns are required:** Loan Account Number and Customer Name.
Duplicates are matched on Loan Account Number, so re-uploading a refreshed
statement updates rather than duplicates.

**Money and dates are never guessed from their contents** — only from their
headings. A bank export routinely carries outstanding, overdue, interest,
sanction limit and drawing power side by side: five columns of indistinguishable
decimals. A wrong balance in front of an agent is worse than a missing one, so if
a heading is ambiguous the field is left unmapped for you to choose.

**Always use "Validate only (dry run)" first.** It writes nothing and shows the
detected mapping, the example values behind each column, a confidence figure, the
branches it will create, and any problem rows. Anything detected from shape rather
than from a heading is flagged for confirmation. Correct any column from the
dropdowns and press *Import with this mapping* — the mapping applies to that same
upload, and is recorded on the import so "where did this figure come from?" is
answerable months later.

Branch resolution, in order: the branch named in the row (matched on code or
name), else a branch created from that name, else the default branch chosen on the
form. Creating branches is restricted to uploaders who are not tied to a single
branch — a branch manager cannot conjure branches outside their own scope through
a spreadsheet.

## 4b. The customer data sheet

An agent can download a one-page PDF for any lead assigned to them, from the
toolbar of the customer screen in the app. It carries the borrower's details, the
loan position, the branch's settlement position (OTS/KRM eligibility and figures),
promises to pay, and the append-only visit history.

```
GET /api/v1/customers/{id}/sheet      ->  application/pdf
```

**Scoped harder than the rest of the lead API on purpose.** Everything else an
agent reads stays inside the app; this leaves the device as a file that can be
printed, mailed or forwarded. So an agent may only take the sheet for a lead
**assigned to them** — not for any lead that merely sits in their branch, which is
all `GET /customers/{id}` requires. Managers and admins get it for any lead inside
their branch scope.

Every download is written to the audit log as an `export` against the loan account,
because the sheet contains contact details and settlement figures.

On the device the file is written to the app's cache, not to Downloads: it should
not outlive the agent's use of it, and the cache is what `FileProvider` is allowed
to share. The Aadhaar number is masked on the sheet; the mobile number is not,
because the agent has to be able to ring the borrower from the printed page.

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

### 6.3 The server address

**The API host is compiled into the APK. Agents never type a URL and cannot
change one.** It is set in `android/app/build.gradle.kts`:

```kotlin
buildConfigField("String", "DEFAULT_API_BASE_URL", "\"https://my.controversy.blog/api/v1/\"")
buildConfigField("boolean", "ALLOW_CUSTOM_SERVER", "false")
```

Change the first line and rebuild if the domain ever moves. The trailing slash is
required: without it Retrofit drops the last path segment and every call resolves
against `/api/` instead of `/api/v1/`.

Why it is not editable in the app: an application holding borrower PII that will
talk to whatever host someone types into it is a phishing target. Anyone who can
persuade an agent to paste a link collects their password on the first login and
their assigned leads immediately after. A **debug** build sets
`ALLOW_CUSTOM_SERVER = true` so a developer can still aim it at a laptop; the
release build has no code path that reads the field at all.

Upgrading an existing install needs nothing extra. Earlier builds let the agent
type a host and saved it, so phones in the field may still hold the old
placeholder domain. A release build ignores any stored address rather than
deleting it, so the update simply starts working — no reinstall, nothing for the
agent to fix.

> HTTPS is mandatory in release builds. Cleartext is permitted only for
> `localhost`, `127.0.0.1` and `10.0.2.2` (the emulator's host alias), so a
> developer can test against a local PHP server.

Check a deployment from anywhere with:

```bash
curl -s https://my.controversy.blog/api/v1/ping
# {"success":true,"data":{"status":"ok","api_version":"v1",...}}
```

### 6.3.1 App icon

The launcher icon is an **adaptive icon** built from vectors:

| File | Layer |
|---|---|
| `res/values/ic_launcher_background.xml` | flat navy background |
| `res/drawable/ic_launcher_foreground.xml` | D2 mark, bar chart, rising arrow |
| `res/drawable/ic_launcher_monochrome.xml` | silhouette for Android 13+ themed icons |
| `res/mipmap-anydpi-v26/ic_launcher.xml` | ties the three together, Android 8+ |
| `res/mipmap-anydpi/ic_launcher.xml` | flat fallback for Android 7.0 / 7.1 |

It is drawn as vector rather than shipped as a PNG because a launcher masks the
icon into its own shape — circle, squircle, rounded square or teardrop — and
shifts the layers for parallax. A square raster gets its corners cropped by those
masks, and it needs five files, one per density, regenerated on every change.

> **The `-v26` qualifier excludes Android 7.** With only the adaptive icon
> declared, `@mipmap/ic_launcher` resolved to nothing on API 24 and 25 and those
> phones showed no icon at all. The `mipmap-anydpi/` files are the fallback; they
> reuse the same two layers rather than duplicating the artwork. Delete them only
> if you raise `minSdk` to 26.

**The safe zone is a circle, not a square.** Only a 66dp circle in the middle of
the 108dp canvas — radius 33 from the centre — is guaranteed to survive every
mask. A bounding box that looks comfortable can still have corners far outside it:
the original mark reached r=42 and lost the top of its "2" on any circular
launcher. After editing the artwork, run:

```bash
python3 tools/check-icon-safezone.py     # fails if any point leaves the circle
python3 tools/render-brand-preview.py    # PNGs of the icon and launch screen
```

The second one writes `docs/previews/`, which is the only way to review the
artwork without installing the app — worth doing, since a mask problem is
invisible in the XML.

**To use an exact raster instead**, put your PNG at these sizes and delete
`mipmap-anydpi-v26/ic_launcher.xml` and `ic_launcher_round.xml`:

```
res/mipmap-mdpi/ic_launcher.png      48x48
res/mipmap-hdpi/ic_launcher.png      72x72
res/mipmap-xhdpi/ic_launcher.png     96x96
res/mipmap-xxhdpi/ic_launcher.png    144x144
res/mipmap-xxxhdpi/ic_launcher.png   192x192
```

Keep the important part within the middle ~66% or the launcher mask will clip it,
and add a 512x512 copy for a Play Store listing. You lose themed-icon support
this way, since a raster cannot be tinted meaningfully.

### 6.3.1a Brand artwork

There is one master: `docs/brand/d2-recovery-lockup.jpg`, the logo as supplied.
Everything shipped is derived from it, so the app and the panel cannot drift apart.

```bash
python3 tools/prepare-brand-assets.py
```

| Output | Used by | Where |
|---|---|---|
| `android/…/res/drawable-nodpi/brand_lockup.webp` | app | launch screen, login screen |
| `admin/assets/img/d2-mark.webp` | panel | sidebar header, 404 header |

**The panel's sign-in page carries no artwork at all**, by choice: the name is set
as a letterspaced wordmark with a gold rule under it. A sign-in page is a door, not
a billboard, and the lockup was competing with the one thing the page exists for.
It also means the page has nothing to load. A smoke check asserts the sign-in page
requests no image, so an artwork cannot creep back in.

**To change the logo,** replace the master with another 1024×1024 image and re-run
the script. If the new artwork has a different layout, re-measure `MARK_BOX` in the
script first — it is the crop that produces the monogram, and the script refuses to
run on a master that is not 1024×1024 rather than cropping the wrong region
silently.

Use the lockup **large only**. It carries the wordmark, the tagline and five badge
captions; below roughly 180dp those stop being legible and it reads as noise. That
is what the monogram crop is for.

The launcher icon is *not* generated from the master — see 6.3.1. A launcher masks
its icon into a circle, and a square raster with artwork in the corners loses them.

### 6.3.2 Launch screen

The splash is the `androidx.core:core-splashscreen` compat screen, so one
declaration covers API 24 to 36 — the library draws it below API 31 and hands the
same values to the platform above it.

| File | Role |
|---|---|
| `res/values/themes.xml` → `Theme.LRMS.Splash` | parent `Theme.SplashScreen`; background, icon, `postSplashScreenTheme` |
| `res/drawable/ic_splash_logo.xml` | the D2 monogram, for the system splash's icon slot |
| `res/layout/activity_splash.xml` | the full brand lockup on the same navy, with a spinner |
| `ui/splash/SplashActivity.kt` | `installSplashScreen()`, then routes the user |

The system splash slot can only hold a small centred icon, so it shows the
monogram; the full lockup lives in the activity layout immediately behind it. Both
sit on the same navy, so the hand-over is invisible.

Three things matter if you change it:

- **`installSplashScreen()` must run before `super.onCreate()`.** That call is
  what swaps the launch theme for `postSplashScreenTheme`.
- **A splash theme whose parent is not `Theme.SplashScreen` silently does
  nothing** beyond setting a background colour. That is how this app shipped with
  no visible splash at all.
- **`Theme.LRMS.Loading` must keep the same background** as the splash. The
  session is confirmed against the server at launch, and on a weak connection that
  outlasts the splash; a different background makes the brand flash to white.

The system splash is released as soon as the lockup is drawn, and **routing** waits
out `MIN_BRAND_MS` (1.3 s) instead. Holding the system splash until the session
check finished was worse: on a warm start with a cached session the check returns
in under a frame, so the activity carrying the lockup was created and left before
it was ever seen. `SplashBrandingTest` pins all of the above.

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
| `php tools/selftest-core.php` | 97 checks — crypto, JWT, XLSX, PDF, validator, paginator, key validation |
| `sh tools/verify-schema.sh` | 24 checks — 21 tables, 39 FKs, InnoDB, utf8mb4, seeds, the seeded bcrypt login |
| `sh tools/integration-test.sh` | 264 checks — import, visits, promises, reports, backup |
| `sh tools/verify-cron.sh` | 20 checks — the nightly backup restores, reminders are idempotent |
| `sh tools/verify-apache.sh` | 27 checks — `.htaccess` under a real Apache: deny rules, HTTPS, Bearer auth |
| `sh tools/smoke-panel.sh` | 130 panel + 162 API checks over real HTTP |
| `sh tools/verify-android.sh` | 118 unit tests (incl. 20 app/API contract checks + 6 server-URL checks), debug + release APK |
| `sh tools/capture-api-fixtures.sh` | Re-captures the API fixtures the contract test reads |
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
- **Location IS recorded** (this reversed in the release that added
  `bc_location_logs`). Before you deploy, satisfy yourself about all of the
  following, because each one is a control this system relies on:
  - the location notice text in `TrackingService::notice()` matches what you
    actually tell your agents, in writing, outside the app as well as inside it;
  - agents have acknowledged it — until they do, nothing is recorded for them;
  - `location_retention_days` is set to a period you can justify (default 90);
  - `cron/purge-location-logs.php` is actually scheduled, otherwise the retention
    promise in the notice is false;
  - your supervisors understand that opening an agent's trail is audited.
- No attendance or working-hours monitoring is derived from location. Points carry
  an `on_duty` flag; they are not turned into a timesheet.
- Visit history is append-only. There is no code path that edits or deletes a
  submitted visit report.

---

## 9. Troubleshooting

**Start here:** upload nothing extra — the hosting package already contains a
self-check page. Open `https://your-domain.com/diag.php?i-understand=1`. It
verifies the PHP version and extensions, the upload layout, file permissions,
`mod_rewrite`, the config keys, and the database connection and table count, then
prints the specific fix for whatever is wrong. It never prints credentials or
keys. **Delete `diag.php` and `setup-keys.php` once the site works.**

**"Configuration incomplete" / login returns HTTP 500 / a user cannot be created**
The three keys in `config/config.php` are blank. Open
`https://your-domain.com/setup-keys.php?generate=1` and reload the panel. Blank
keys are the single most common broken install: the panel appears to work because
pages render and sign-in succeeds, while anything that encrypts — user creation
with a mobile number, lead import, an app login falling through to a mobile
lookup — throws. Startup validation now blocks the panel instead of letting it
half-work.

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

### Adding location recording to an existing install

```sql
ALTER TABLE `visit_reports`
  ADD COLUMN `gps_latitude`    DECIMAL(10,7) DEFAULT NULL AFTER `family_member_relationship`,
  ADD COLUMN `gps_longitude`   DECIMAL(10,7) DEFAULT NULL AFTER `gps_latitude`,
  ADD COLUMN `gps_accuracy_m`  SMALLINT UNSIGNED DEFAULT NULL AFTER `gps_longitude`,
  ADD COLUMN `gps_captured_at` DATETIME      DEFAULT NULL AFTER `gps_accuracy_m`,
  ADD COLUMN `gps_address`     VARCHAR(400)  DEFAULT NULL AFTER `gps_captured_at`,
  ADD COLUMN `gps_source`      ENUM('device','unavailable','denied') DEFAULT NULL AFTER `gps_address`;

ALTER TABLE `photos`
  ADD COLUMN `gps_latitude`   DECIMAL(10,7) DEFAULT NULL AFTER `uploaded_by`,
  ADD COLUMN `gps_longitude`  DECIMAL(10,7) DEFAULT NULL AFTER `gps_latitude`,
  ADD COLUMN `gps_accuracy_m` SMALLINT UNSIGNED DEFAULT NULL AFTER `gps_longitude`,
  ADD COLUMN `captured_at`    DATETIME DEFAULT NULL AFTER `gps_accuracy_m`,
  ADD COLUMN `capture_source` ENUM('camera','gallery','unknown') NOT NULL DEFAULT 'unknown' AFTER `captured_at`;

-- Logger::audit() swallows its own failures, so an action name missing from this
-- ENUM never raises anything - it just silently never records. That is how the
-- customer-sheet export was "audited" without a row ever appearing.
ALTER TABLE `audit_logs`
  MODIFY COLUMN `action` ENUM('create','update','delete','import','assign','reassign',
    'transfer','restore','backup','login_reset','export','consent','view_location','purge') NOT NULL;

-- then the two new tables, from schema.sql section 14:
--   tracking_consents
--   bc_location_logs
```

**Before you turn this on**, work through the checklist in §8 — the notice text,
the acknowledgements, the retention period, and the purge cron. Nothing is recorded
for an agent until they have acknowledged, so the safe order is: deploy, set
`location_retention_days`, schedule the purge, then roll out the consent screen.

```
15 3 * * * /usr/local/bin/php /home/USER/public_html/cron/purge-location-logs.php
```

Run it once with `--dry-run` first; it reports how many points are past the window
without deleting anything.

### SSS enrolment reminders

By email and in-app. There is no SMS gateway in this deployment, so a reminder
written for SMS would never arrive.

```
0 11,14,16 * * * /usr/local/bin/php /home/USER/public_html/cron/sss-reminder.php
0 17       * * * /usr/local/bin/php /home/USER/public_html/cron/sss-reminder.php --final
```

Only the `--final` slot copies the branch supervisor. An agent with no SSS target
for the month is never reminded, and reminders stop the moment they record an entry.

### Adding the BC performance module to an existing install

Six new tables and three new `users` columns. On a database created before this
release:

```sql
-- see schema.sql for the full definitions, including comments
SOURCE /path/to/schema-bc-performance.sql;   -- or paste the six CREATE TABLE blocks

ALTER TABLE `users`
  ADD COLUMN `dashboard_status`  ENUM('normal','warning_1','warning_2','final_warning')
      NOT NULL DEFAULT 'normal' AFTER `locked_until`,
  ADD COLUMN `escalation_flag`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `dashboard_status`,
  ADD COLUMN `status_changed_at` DATETIME DEFAULT NULL AFTER `escalation_flag`;

-- the warning cron and the SSS reminder both raise in-app notifications
ALTER TABLE `notifications`
  MODIFY COLUMN `type` ENUM('new_lead_assigned','followup_reminder','promise_reminder',
                            'broadcast','target_warning','sss_pending') NOT NULL;
```

Then set targets under **Admin → BC Targets** before relying on the cron: an agent
with no target row is deliberately never assessed, so until targets exist the
nightly run reports "no targets set" and warns nobody.

Add the cron:

```
55 23 * * * /usr/local/bin/php /home/USER/public_html/cron/bc-warning-check.php
```

It takes `--date=YYYY-MM-DD` for a backfill and `--dry-run` to see what it would
do without writing or mailing anything. It is idempotent — a unique key on
(agent, target, date) means a re-run after a failure cannot warn or mail twice.

### Adding the settlement-position columns to an existing install

The importer can now carry the branch's own settlement decision with each lead.
Four columns were added to `loan_accounts`; on a database created before this
release, add them once:

```sql
ALTER TABLE `loan_accounts`
  ADD COLUMN `ots_eligible`   TINYINT(1)    DEFAULT NULL COMMENT 'bank flag from the import; NULL = not stated' AFTER `ckcc_renewal_due_date`,
  ADD COLUMN `krm_eligible`   TINYINT(1)    DEFAULT NULL COMMENT 'eligible under the KRM scheme specifically'   AFTER `ots_eligible`,
  ADD COLUMN `ots_amount`     DECIMAL(15,2) DEFAULT NULL COMMENT 'settlement figure proposed by the branch'     AFTER `krm_eligible`,
  ADD COLUMN `deposit_amount` DECIMAL(15,2) DEFAULT NULL COMMENT 'initial deposit expected alongside the OTS'   AFTER `ots_amount`,
  ADD KEY `idx_loan_ots_eligible` (`ots_eligible`);
```

They are nullable on purpose: `NULL` means the file did not say, which is a
different answer from an explicit No, and only one of those should stop an agent
offering a settlement. A later import that omits the columns leaves whatever was
already recorded untouched.

### Renaming to D2 Recovery on an existing install

The product name is stored in the `settings` table, not in the code — that is the
point of the setting, so an operator can change it without a deploy. Copying new
files therefore does **not** rename an install that was seeded earlier: the panel
header, PDF and Excel exports and the OTP text all keep showing the old name.

Easiest fix, in the panel: **Settings → General → Application name**. Or run this
once in phpMyAdmin; it is idempotent and touches nothing an operator has already
customised:

```sql
UPDATE `settings` SET `setting_value` = 'D2 Recovery'
 WHERE `setting_key` IN ('app_name', 'smtp_from_name')
   AND `setting_value` = 'LRMS';

UPDATE `settings` SET `setting_value` = REPLACE(`setting_value`, 'LRMS', 'D2 Recovery')
 WHERE `setting_key` = 'sms_otp_template'
   AND `setting_value` LIKE '%LRMS%';
```

The Android app needs no equivalent step: its name is a string resource, so
installing the new APK is enough.

### AGP 9.x

The Android build is pinned to AGP 8.13 (the last of the 8.x line, supporting up
to API 36.1). AGP 9.x is available but is a major release with API removals and
built-in Kotlin handling. Migrating means, at minimum: removing the explicit
Kotlin Android plugin, moving `kotlinOptions` fully to `compilerOptions`, and
re-checking every Gradle plugin for 9.x compatibility. Pinning to 8.13 keeps CI
reproducible; upgrade deliberately, not incidentally.
