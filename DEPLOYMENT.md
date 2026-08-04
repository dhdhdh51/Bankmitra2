# D2 Recovery Solutions & Services Deployment Guide

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
└── uploads/           ← photos and documents (served only via PHP)
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
3. **Managers & Agents** → create branch managers and BC agent accounts
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
| `smtp_from_name` | `D2 Recovery Solutions & Services` |

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

### 6.5 Firebase push — you do not need it

Read this before setting anything up, because the honest answer is **nothing in this
system requires Firebase**, and none of the three things people usually add it for
needs it here:

| What you might want it for | What actually happens today |
|---|---|
| The daily report alarm | A **local alarm on the phone**, set by `AlarmManager`. It fires with no network at all — which in these villages is the normal case — and a push could not be relied on to arrive. Firebase would make it *worse*. |
| Alerts (promise due, renewal due, warnings) | Written by cron into `notifications` and shown in the app's Alerts tab, with an unread badge. They arrive when the agent opens the app or the app refreshes. |
| Location tracking | Nothing to do with Firebase. It is the phone's own `LocationManager` posting to this server's API, and the map is OpenStreetMap. **No Google account, no billing, no key.** |

The **only** thing Firebase buys you is that an alert arrives on a locked phone within
seconds instead of when the agent next opens the app. It is optional, it costs an
account and a `google-services.json` in the build, and the project deliberately ships
without it so the APK builds in CI out of the box.

If you decide you want it anyway:

1. Create a Firebase project and add an Android app with the applicationId
   `com.lrms.recovery`
2. Put the **server key** into Settings → Integrations → *Firebase server key*
3. Add `google-services.json` to `android/app/`, then add the Google Services plugin
   and the `firebase-messaging` dependency, and build a new APK

The app registers its device token at sign-in, so once the server key is set, push
starts working without an app change.

### 6.6 Where the daily alarm is set

All of it is in the panel, under **Settings → Notifications**. Nothing is set on the
phone and an agent cannot change any of it — that is the point of it being here.

| Setting | Default | What it does |
|---|---|---|
| **Daily report due by** | 17:00 | When the first reminder fires. A dropdown of half-hour steps from 15:00 to 20:00. |
| **Remind agents to submit** | On | The master switch. A toggle now, not a dropdown offering "1" and "0". |
| **Repeat every (minutes)** | 15 | How often it comes back until the report is in. `0` means one reminder and no repeating. |
| **Stop repeating after (hour)** | 22 | When repeats stop for the night. They resume at the deadline on the next working day. |

Every agent picks up a change **the next time they open the app** — the values ride
along on `/meta` and are cached, so the alarm still fires correctly with no network.
The reminder stops the moment a visit report or the day's SSS figures are filed.

If the "Daily report due by" dropdown looks empty on your install, its `options` column
is blank — see §10, *Fixing the two on/off settings that were dropdowns*, which also
carries the one-line fix for that.

---

## 7. Verification

Everything in the repository is covered by runnable checks.

| Command | What it proves |
| --- | --- |
| `php tools/selftest-core.php` | 280 checks — crypto (including PAN masking, which cannot use the mobile helpers), JWT, XLSX, PDF (image embedding, the blank signature boxes, multi-line captions, and the printed form's masthead, section bands, tick grids, ruled fields and page-of-page total), geo wording, validator, paginator, key validation |
| `sh tools/verify-schema.sh` | 28 checks — 34 tables, 54 FKs, InnoDB, utf8mb4, seeds, the seeded bcrypt login, dropdown settings that have choices |
| `sh tools/integration-test.sh` | 841 checks — import, visits, promises, reports, backup, report corrections, hand-corrected figures, custom fields, geo-tagged agent photo, a lead typed in by hand and what the next import does to it, and every section of the printed Field Visit Verification Report |
| `sh tools/verify-upgrade-sql.sh` | 18 checks — **runs every migration in section 10 of this document as a chain** on a populated pre-release database and compares the result against `schema.sql` |
| `sh tools/verify-cron.sh` | 52 checks — the nightly backup restores, reminders are idempotent |
| `sh tools/verify-apache.sh` | 27 checks — `.htaccess` under a real Apache: deny rules, HTTPS, Bearer auth |
| `sh tools/smoke-panel.sh` | 496 panel + 228 API checks over real HTTP, including every dropdown on every page (markup *and* the CSS that paints its caret), a borrower created by hand as both an admin and an agent, and the printed visit report checked band by band against the paper form |
| `sh tools/verify-android.sh` | 256 unit tests (incl. 20 app/API contract checks + 6 server-URL checks), debug + release APK |
| `python3 tools/verify-android-strings.py` | 7 checks — English and Hindi are paired key for key and every format specifier matches, so a translation cannot crash a screen |
| `sh tools/capture-api-fixtures.sh` | Re-captures the API fixtures the contract test reads |
| `sh tools/verify-signing.sh` | 21 checks — release signing works, the unsigned fallback really is uninstallable, and the debug APK comes from the committed keystore so a new build installs over the old one |
| `php tools/crossvalidate.php .verify && python3 tools/crossvalidate.py .verify` | Generated XLSX opens in openpyxl, PDF opens in pypdf |
| `php tools/verify-cdn-integrity.php` | 7 checks — every SRI hash in every view matches the bytes the browser fetches |

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
    actually tell your agents, **in writing, outside the app** — the app no longer shows
    that text to them, so this is now the only place they read it. The notice is still
    versioned and still what the consent record points at;
  - the app is not asking a second time: consent is the operating system's permission
    dialog, posted server-side once the permission is held, and recording starts on its
    own from there — there is no switch an agent leaves off;
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
the retention period, and the purge cron. Nothing is recorded for an agent until consent is
on file, and the app posts that the first time it runs with the location permission held, so
the safe order is: deploy, set `location_retention_days`, schedule the purge, *then* roll
out the APK. Turn that order around and the first day's points are recorded with no purge
scheduled to age them out.

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

### The daily report reminder (5 PM by default)

Nothing to schedule on the server — the reminder is an alarm on the agent's phone, so
it works with no connectivity at the time it fires. What you control is the deadline:

**Settings → Notifications → "Daily report due by"**

Pick the time from the dropdown (15:00 to 20:00 in half-hour steps). Every agent's
alarm moves to the new time the next time they open the app. The panel is responsive,
so this can be changed from a phone.

Two things to keep in step:

1. **The `sss-reminder.php --final` crontab entry should be on the same hour.** That
   job is what emails the supervisor; the alarm is what taps the agent on the shoulder.
   If they disagree, the `--final` run prints a warning to STDERR naming both times, so
   check your cron mail after changing the deadline.
2. **"Remind agents to submit"** is the master switch. Turning it off silences the
   alarm for everybody, and agents can no longer switch their own back on.

An agent can bring their own reminder forward (15 min, 30 min, 1 hour, 2 hours before)
or switch it off, from **Account → Daily report reminder** in the app. They cannot move
it later than the deadline.

The alarm never fires on a Sunday, and stays quiet once the agent has filed a visit or
saved their enrolment figures for the day.

### CKCC / OD-2 renewal alerts

```
0 7 * * * /usr/local/bin/php /home/USER/public_html/cron/ckcc-renewal-check.php
```

Alerts the assigned agent as an account crosses 30, 15 and 7 days to its renewal
deadline, and again once it is overdue. Each threshold is announced once per
account: the job matches on the band and the due date carried in the notification,
so a re-run at noon, or a catch-up after a failed night, does not send it twice.

There is deliberately **no stored "urgency" column**. The band is a function of
`ckcc_renewal_due_date` and today's date, so it is derived in SQL where it cannot go
stale — a stored value would be wrong every day between midnight and whenever the
cron ran, and silently so. The cron exists only for the part that is not derivable:
telling somebody.

Widen or narrow the alert window with the `ckcc_renewal_alert_days` setting
(default 30).

### Address lookup from coordinates (optional, free)

```
20 * * * * /usr/local/bin/php /home/USER/public_html/cron/geocode-backfill.php
```

Turns the coordinates stored on visits and location trails into readable addresses
using **OpenStreetMap's Nominatim**, which is free and needs no API key. Three
things to know before switching it on:

1. **Set `geocode_contact_email` in Settings.** Nominatim asks callers to identify
   themselves. Without it the lookups stay off rather than being sent anonymously —
   anonymous traffic from a shared host is how an entire IP range gets blocked.
2. **It is slow on purpose.** The service asks for at most one request per second
   and no bulk reverse-geocoding, so the job resolves ~120 coordinates per run and
   throttles itself internally. Raising `--limit` into the thousands is the fastest
   way to lose the feature for every branch at once.
3. **Nothing depends on it.** Panel pages read addresses out of `geocode_cache` and
   show raw coordinates for anything not yet resolved. No page render ever waits on
   a third party. Setting `geocode_enabled` to `0` leaves the system fully working
   and showing coordinates — that is the designed fallback, not a degraded mode.

Addresses are never written into the visit report itself. The coordinate is the
record; the address is a derived label, because a free service naming the wrong
village must stay distinguishable from something the agent asserted.

### Adding the daily report reminder to an existing install

Two settings rows. No table or column changes.

```sql
INSERT INTO `settings`
  (`setting_key`, `setting_value`, `group_name`, `label`, `input_type`, `is_secret`, `is_required`, `hint`, `sort_order`)
VALUES
  ('daily_report_due_time','17:00','notifications','Daily report due by','select',0,0,
   'The app reminds agents at this time. Keep the sss-reminder --final cron entry on the same hour.',3),
  ('daily_report_reminder_enabled','1','notifications','Remind agents to submit','select',0,0,
   'Turns the in-app daily alarm off for everyone. Agents can also switch off their own.',4);

UPDATE `settings` SET `options` = '15:00,15:30,16:00,16:30,17:00,17:30,18:00,18:30,19:00,19:30,20:00'
 WHERE `setting_key` = 'daily_report_due_time';
UPDATE `settings` SET `options` = '1,0' WHERE `setting_key` = 'daily_report_reminder_enabled';
```

A missing or malformed value falls back to 17:00 in code rather than to "no deadline",
so the reminder keeps working even if these rows are never added — but the operator
then has no way to change the time, which is the point of adding them.

Agents need the **new APK** for the reminder: the alarm lives in the app, not on the
server.

### Adding the BC screens, renewal alerts and address lookup

On a database created before this release:

```sql
-- 1. The reverse-geocoding cache (schema.sql section 14a).
--    Copy the CREATE TABLE for `geocode_cache` from schema.sql.

-- 2. Two more notification types. A type missing from this ENUM throws on insert,
--    and the nightly jobs insert in a loop - one absent value once took out an
--    entire warning run before the first agent was notified.
ALTER TABLE `notifications`
  MODIFY COLUMN `type` ENUM('new_lead_assigned','followup_reminder','promise_reminder',
    'broadcast','target_warning','sss_pending','ckcc_renewal_due') NOT NULL;

-- 3. The five permissions the new screens are gated on. Without these the pages
--    exist but nobody, including the super admin, can open them.
INSERT INTO `permissions` (`code`, `module`, `display_name`) VALUES
  ('bc_targets.view',   'BC performance', 'View monthly BC targets'),
  ('bc_targets.manage', 'BC performance', 'Set and update monthly BC targets'),
  ('sss.view',          'BC performance', 'View SSS enrolment entries'),
  ('sss.manage',        'BC performance', 'Record and correct SSS enrolment'),
  ('scorecard.view',    'BC performance', 'View the BC summary scorecard');

-- Super admin gets everything; re-running this is safe because of the NOT EXISTS.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 1, p.`id` FROM `permissions` p
   WHERE p.`code` LIKE 'bc_targets.%' OR p.`code` IN ('sss.view','sss.manage','scorecard.view')
     AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp
                      WHERE rp.`role_id` = 1 AND rp.`permission_id` = p.`id`);

-- Branch managers manage their own agents (scoped in code, not by this grant).
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions`
   WHERE `code` IN ('bc_targets.view','bc_targets.manage','sss.view','sss.manage','scorecard.view');

-- Auditors read, never manage: checking whether a warning was fair means seeing
-- the target it was measured against.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 4, `id` FROM `permissions`
   WHERE `code` IN ('bc_targets.view','sss.view','scorecard.view');

-- 4. Four settings. location_retention_days was previously read from code with a
--    hard-coded fallback, which meant the operator accountable for the retention
--    window could not actually change it.
INSERT INTO `settings`
  (`setting_key`, `setting_value`, `group_name`, `label`, `input_type`, `is_secret`, `is_required`, `hint`, `sort_order`)
VALUES
  ('location_retention_days','90','location','Location retention (days)','number',0,0,'Location points older than this are deleted by the purge cron',1),
  ('geocode_enabled','1','location','Resolve addresses from coordinates','select',0,0,'Uses the free OpenStreetMap service. Turn off to store coordinates only.',2),
  ('geocode_contact_email','','location','Contact email for the map service','text',0,0,'OpenStreetMap asks who is calling. Without it, lookups are skipped rather than sent anonymously.',3),
  ('ckcc_renewal_alert_days','30','location','CKCC renewal alert window (days)','number',0,0,'Agents are alerted as a renewal crosses this many days, then 15, 7 and overdue',4);

UPDATE `settings` SET `options` = '1,0' WHERE `setting_key` = 'geocode_enabled';
```

Then sign in, open **Roles & Permissions**, and confirm the new *BC performance*
group appears. The sidebar entries are permission-gated, so if the group is missing
the grants above did not apply.

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

### Adding staff photographs, report approval and custom fields to an existing install

This release adds two `users` columns, two `loan_accounts` columns, the approval and
revision block on `visit_reports`, three `visit_history` event types, three new tables
and three new permissions. Back up first (`php ~/public_html/cron/backup.php`), then run
the whole block once. Every statement is safe to run on a populated database — nothing
here drops or rewrites existing rows.

```sql
-- 1. The agent's photograph and signature. Both print on every report they file.
ALTER TABLE `users`
  ADD COLUMN `photo_path`     VARCHAR(500) DEFAULT NULL COMMENT 'uploads-relative; printed on visit reports' AFTER `status_changed_at`,
  ADD COLUMN `signature_path` VARCHAR(500) DEFAULT NULL COMMENT 'uploads-relative; printed beside the photo'  AFTER `photo_path`;

-- 2. The closure figure, and the record of which columns a human corrected.
--    closure_amount is the full amount to close the account. It is NOT ots_amount:
--    an OTS is a settlement the branch agrees to accept for less than the closure figure.
ALTER TABLE `loan_accounts`
  ADD COLUMN `closure_amount`   DECIMAL(15,2) DEFAULT NULL COMMENT 'full amount to close the account' AFTER `overdue_amount`,
  ADD COLUMN `manual_overrides` JSON          DEFAULT NULL COMMENT 'hand-edited columns the import must not clobber';

-- 3. Approval, and the correction counter.
ALTER TABLE `visit_reports`
  ADD COLUMN `approval_status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ADD COLUMN `approved_by`            INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `approver_name`          VARCHAR(150) DEFAULT NULL COMMENT 'snapshot, survives a renamed or deleted user',
  ADD COLUMN `approved_at`            DATETIME     DEFAULT NULL,
  ADD COLUMN `approval_remarks`       VARCHAR(1000) DEFAULT NULL,
  ADD COLUMN `approval_photo_path`    VARCHAR(500) DEFAULT NULL COMMENT 'the approver, at the moment of approval',
  ADD COLUMN `approval_signature_path` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN `approval_gps_latitude`  DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN `approval_gps_longitude` DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN `approval_gps_accuracy_m` SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN `approval_gps_source`    ENUM('device','unavailable','denied') NOT NULL DEFAULT 'unavailable',
  ADD COLUMN `revision_count`         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'entries in visit_report_revisions',
  ADD COLUMN `updated_at`             DATETIME     DEFAULT NULL COMMENT 'set only by an approval or a revision',
  ADD KEY `idx_visit_approval` (`approval_status`, `visit_date`),
  ADD CONSTRAINT `fk_visit_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 4. Three new timeline event types. MODIFY, not ADD: a value missing from this list
--    throws on insert, and the approval writes its timeline row in the same
--    transaction as the approval itself - so a stale ENUM fails the whole approval.
ALTER TABLE `visit_history`
  MODIFY COLUMN `event_type` ENUM('lead_imported','lead_updated','assigned','reassigned','transferred',
                                  'visit','promise_created','promise_kept','promise_broken',
                                  'status_changed','closed','reopened','note',
                                  'visit_approved','visit_rejected','visit_revised') NOT NULL;

-- 5. Corrections to a filed report. Nothing ever updates or deletes a row in here;
--    replaying the `changes` backwards reconstructs the report as it was submitted.
CREATE TABLE `visit_report_revisions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_report_id` BIGINT UNSIGNED NOT NULL,
  `revision_no`     SMALLINT UNSIGNED NOT NULL COMMENT '1 for the first correction',
  `changed_by`      INT UNSIGNED DEFAULT NULL,
  `changed_by_name` VARCHAR(150) DEFAULT NULL COMMENT 'snapshot',
  `changed_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- {"column": {"from": <old>, "to": <new>}} - only the columns that actually moved.
  `changes`         JSON         NOT NULL,
  `reason`          VARCHAR(500) DEFAULT NULL COMMENT 'why, in the editor''s words',
  `ip`              VARCHAR(45)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_revision_report_no` (`visit_report_id`, `revision_no`),
  KEY `idx_revision_report` (`visit_report_id`, `changed_at`),
  KEY `idx_revision_actor` (`changed_by`),
  CONSTRAINT `fk_revision_report` FOREIGN KEY (`visit_report_id`) REFERENCES `visit_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_revision_actor`  FOREIGN KEY (`changed_by`)      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Fields an operator adds without a code change.
CREATE TABLE `custom_field_definitions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity`         ENUM('customer','loan_account','visit_report') NOT NULL,
  -- Immutable once created: it is what stored values point at through definition_id.
  `field_key`      VARCHAR(60)  NOT NULL,
  `label`          VARCHAR(120) NOT NULL,
  `field_type`     ENUM('text','textarea','number','money','date','select','toggle') NOT NULL DEFAULT 'text',
  `options`        VARCHAR(500) DEFAULT NULL COMMENT 'comma separated, for select',
  `hint`           VARCHAR(255) DEFAULT NULL,
  `is_required`    TINYINT(1)   NOT NULL DEFAULT 0,
  `show_in_report` TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_field_key` (`entity`, `field_key`),
  KEY `idx_custom_field_entity` (`entity`, `status`, `sort_order`),
  CONSTRAINT `fk_custom_field_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `custom_field_values` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `definition_id` INT UNSIGNED NOT NULL,
  `entity`        ENUM('customer','loan_account','visit_report') NOT NULL,
  `entity_id`     BIGINT UNSIGNED NOT NULL,
  -- One text column for every type; the definition says how to read it.
  -- Dates are ISO, money is a decimal string, toggles are '1' or '0'.
  `value`         TEXT         DEFAULT NULL,
  `updated_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_value` (`definition_id`, `entity_id`),
  KEY `idx_custom_value_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_custom_value_definition` FOREIGN KEY (`definition_id`) REFERENCES `custom_field_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Permissions. Approving and correcting go to branch managers and above, never to
--    agents: an agent approving their own report, or fixing the name on it after
--    filing, makes both the approval and the revision log worthless.
INSERT INTO `permissions` (`code`, `module`, `display_name`) VALUES
  ('visits.approve',       'Visits',   'Approve or reject a field visit report'),
  ('visits.revise',        'Visits',   'Correct a submitted visit report (recorded as a revision)'),
  ('custom_fields.manage', 'Settings', 'Add and edit custom fields');

-- Super Admin gets all three; Branch Manager gets approve + revise only.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 1, `id` FROM `permissions`
   WHERE `code` IN ('visits.approve','visits.revise','custom_fields.manage');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions`
   WHERE `code` IN ('visits.approve','visits.revise');
```

After running it, confirm the counts:

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();  -- 35
SELECT COUNT(*) FROM `permissions`;                                             -- 44
```

Two things to know about the files:

- **Two new upload kinds appear**, `uploads/staff/` and `uploads/approvals/`, created
  automatically on the first upload under the same date-sharded layout as the rest. They
  inherit the `uploads/.htaccess` deny rule, so images are served only through the
  authorised media route — nothing under `uploads/` is ever fetched directly.
- **A new APK is required.** The app now sends each photograph's capture source
  explicitly (camera or gallery). An older APK against the new server still submits
  visits, but every photo it sends records the source as unknown, so the geo-tag caption
  on the printed report cannot say where the picture came from.

Nothing needs re-importing or re-seeding. Existing reports read `approval_status =
'pending'` and `revision_count = 0`, which is exactly true of them: nobody has approved
or corrected them.

### Adding geo-tagged agent photographs and signatures to an existing install

Small migration: four columns on `signatures` and one new value in the `photos.photo_type`
list. Run it after the previous section if you are coming from an older install.

```sql
-- 1. Where each signature was signed.
--    A signature is the borrower agreeing to what the report says and the agent
--    asserting they were there to collect it, so "signed at these coordinates" is what
--    makes it more than a squiggle. It is a different fact from where the report was
--    submitted - an agent can walk back to the road before pressing send.
ALTER TABLE `signatures`
  ADD COLUMN `gps_latitude`   DECIMAL(10,7) DEFAULT NULL AFTER `captured_at`,
  ADD COLUMN `gps_longitude`  DECIMAL(10,7) DEFAULT NULL AFTER `gps_latitude`,
  ADD COLUMN `gps_accuracy_m` SMALLINT UNSIGNED DEFAULT NULL AFTER `gps_longitude`,
  -- Three-way, not a nullable flag: "the agent declined location recording" and
  -- "there was no signal in the courtyard" are different answers to a supervisor
  -- asking why a signed report carries no position.
  ADD COLUMN `gps_source`     ENUM('device','unavailable','denied') NOT NULL DEFAULT 'unavailable' AFTER `gps_accuracy_m`;

-- 2. The agent's own photograph, taken at the door, is a normal photo row.
--    It lives in `photos` rather than in a column on `visit_reports` so it inherits
--    everything a photograph already has: its own fix, its own capture_source,
--    branch-scoped media authorisation and a place in the galleries.
ALTER TABLE `photos`
  MODIFY COLUMN `photo_type` ENUM('customer','house','land','aadhaar','passbook',
                                  'renewal_form','agent','other') NOT NULL DEFAULT 'other';
```

Existing rows read `gps_source = 'unavailable'`, which is exactly true of them: nobody
recorded a position when they were signed.

Two notes:

- **A new APK is required for this to do anything.** The columns are filled by the app:
  the signature pad now reads a fix when Save is pressed, and there is a new camera-only
  slot for the agent's own photograph. An older APK against the new server keeps working
  and simply records no position for signatures.
- **`photos.captured_at` starts being written.** It has existed since the first release
  with the comment "device clock at capture" and nothing ever wrote it, so every
  photograph already in your database has `NULL` there and will keep it. New photographs
  filed by the new APK carry the time the shutter was pressed. Nothing backfills the old
  rows, because the only timestamp available for them is when the upload arrived, and
  writing that into a column labelled "captured at" would turn a missing fact into a
  wrong one.

### Adding the full banking columns, panel signatures and agent access to an existing install

Four unrelated things in one release: the importer now carries a complete recovery
statement, a signature can be uploaded from the panel, staff portraits are gone, and BC
agents get a narrow panel surface.

> **One statement here is destructive.** Dropping `users.photo_path` and
> `users.signature_path` deletes the only reference to any staff portrait or signature
> image you uploaded. The files themselves stay on disk under `uploads/staff/`, but
> nothing will serve them any more. If you want to keep them, copy that directory
> somewhere before you run this — or run everything except step 3, which is optional and
> changes no behaviour.

```sql
-- 1. How a signature got here. 'device_pad' was drawn on the phone at the visit and can
--    therefore carry a position; 'panel_upload' is an image attached from a desk
--    afterwards, usually a photographed paper signature. Both are legitimate, and the
--    printed report labels the second as an upload rather than presenting it as the
--    first. Existing rows are all pad signatures, which is what the default says.
ALTER TABLE `signatures`
  ADD COLUMN `capture_method` ENUM('device_pad','panel_upload') NOT NULL DEFAULT 'device_pad' AFTER `gps_source`,
  ADD COLUMN `uploaded_note`  VARCHAR(255) DEFAULT NULL COMMENT 'why a panel upload was needed' AFTER `capture_method`;

-- 2. The rest of a core banking NPA / recovery statement. All nullable: NULL means the
--    file did not carry the column, which is a different fact from a zero.
ALTER TABLE `loan_accounts`
  ADD COLUMN `asset_classification` VARCHAR(40)   DEFAULT NULL COMMENT 'Standard / SMA-1 / Doubtful 2 / Loss ...',
  ADD COLUMN `interest_rate`        DECIMAL(6,3)  DEFAULT NULL COMMENT 'percent per annum',
  ADD COLUMN `installment_amount`   DECIMAL(15,2) DEFAULT NULL COMMENT 'EMI / instalment due per period',
  ADD COLUMN `last_payment_date`    DATE          DEFAULT NULL,
  ADD COLUMN `last_payment_amount`  DECIMAL(15,2) DEFAULT NULL,
  ADD COLUMN `days_past_due`        SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN `security_value`       DECIMAL(15,2) DEFAULT NULL COMMENT 'value of collateral held',
  ADD COLUMN `guarantor_name`       VARCHAR(150)  DEFAULT NULL,
  ADD COLUMN `maturity_date`        DATE          DEFAULT NULL,
  ADD COLUMN `purpose`              VARCHAR(150)  DEFAULT NULL COMMENT 'crop / activity the loan was for',
  -- Recovery work is prioritised by classification within a branch.
  ADD KEY `idx_loan_classification` (`branch_id`, `asset_classification`);

-- 3. OPTIONAL AND DESTRUCTIVE - read the warning above.
--    Staff portraits and signatures are no longer held against a person: an image now
--    belongs to the thing it evidences, so an agent's photograph belongs to the visit it
--    was taken at and a signature to the report it signs. Skipping this leaves two
--    unused columns and changes nothing.
ALTER TABLE `users`
  DROP COLUMN `photo_path`,
  DROP COLUMN `signature_path`;

-- 4. BC agents can now correct their own borrowers in the panel and add fields to
--    them. The panel restricts them to leads assigned to them - branch scope is not
--    enough, because a branch holds several agents - and every figure they change is
--    stamped into loan_accounts.manual_overrides, so the correction is attributed and
--    the next import leaves it alone.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 3, `id` FROM `permissions` WHERE `code` IN ('customers.update', 'custom_fields.manage')
   AND `id` NOT IN (SELECT `permission_id` FROM `role_permissions` WHERE `role_id` = 3);
```

Confirm it landed:

```sql
-- 9 at this release. A later one ("Letting the panel add a borrower by hand") grants
-- customers.create to agents as well, after which this reads 10.
SELECT COUNT(*) FROM `role_permissions` WHERE `role_id` = 3;   -- 9
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'loan_accounts';   -- 45
-- The signatures table existed at this release and is dropped by a later one, so
-- this count only holds until you apply "Removing captured signatures" below.
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'signatures';       -- 16

-- And, only if you ran the optional step 3:
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users'
   AND column_name IN ('photo_path', 'signature_path');                -- 0
```

Afterwards:

- **The Excel import needs no configuration to use the new columns.** Column detection
  reads the headings, so a file carrying "Asset Classification", "DPD", "ROI",
  "Last Paid Date", "Security Value" or "Guarantor" now lands whole. The mapping screen
  still lets you override any of it.
- **Choose "Distribute equally among the branch's agents"** on the import screen if the
  branch has more than one BC. Naming a single agent hands them the entire file, and the
  reassignment that would fix it afterwards is work nobody does. Distribution balances
  what each agent is *already* carrying, so a second import continues the spread instead
  of restarting at the same person. The same thing is available on the borrower list as a
  bulk action, so an existing pile-up can be evened out.
- **Agents sign in to the panel with their existing credentials.** They land on their own
  borrower list and can reach nothing else — no dashboard, no visits, no reports, no other
  agent's borrowers.
- No new APK is needed for any of this.

### Splitting KCC from OD-2, and making the alarm persist, on an existing install

One column, one index, two settings — and a backfill that matters: without it both renewal
worklists are empty on an existing database, because nothing has told it which accounts are
which.

```sql
-- 1. Which renewable facility an account is, so KCC and OD-2 become the two separate
--    queues they already were in practice. They both renew against
--    ckcc_renewal_due_date and used to be reviewed as one list, which buried forty OD-2
--    renewals inside three hundred KCC ones.
ALTER TABLE `loan_accounts`
  ADD COLUMN `facility_type` ENUM('kcc','od2','other') DEFAULT NULL
      COMMENT 'NULL = the file did not say' AFTER `loan_type`,
  ADD KEY `idx_loan_facility_renewal` (`branch_id`, `facility_type`, `ckcc_renewal_due_date`);

-- 2. Backfill from the loan type already on the row. New imports derive this
--    automatically; existing rows need telling once, or both worklists open empty.
--
--    OD-2 is matched FIRST: an account whose type reads "KCC OD-2" has been converted, and
--    belongs in the OD-2 queue. A bare "OD" or "Overdraft" is deliberately left NULL - a
--    plain overdraft is not the OD-2 facility, and a wrong guess puts an account into a
--    list somebody works through by hand.
UPDATE `loan_accounts`
   SET `facility_type` = 'od2'
 WHERE `facility_type` IS NULL
   AND (
     REPLACE(REPLACE(LOWER(`loan_type`), '-', ''), ' ', '') LIKE '%od2%'
     OR REPLACE(REPLACE(LOWER(`loan_type`), '-', ''), ' ', '') LIKE '%odii%'
     OR REPLACE(REPLACE(LOWER(`loan_type`), '-', ''), ' ', '') LIKE '%overdraft2%'
     OR REPLACE(REPLACE(LOWER(`loan_type`), '-', ''), ' ', '') LIKE '%overdraftii%'
   );

UPDATE `loan_accounts`
   SET `facility_type` = 'kcc'
 WHERE `facility_type` IS NULL
   AND (
     REPLACE(REPLACE(LOWER(`loan_type`), '-', ''), ' ', '') LIKE '%kcc%'
     OR REPLACE(REPLACE(LOWER(`loan_type`), '-', ''), ' ', '') LIKE '%kisancreditcard%'
     OR REPLACE(REPLACE(LOWER(`loan_type`), '-', ''), ' ', '') LIKE '%kisancard%'
   );

-- 3. How the daily alarm behaves once it fires. Both are the bank's numbers, not the
--    agent's - the app no longer has any control over the reminder at all.
INSERT INTO `settings`
  (`setting_key`, `setting_value`, `group_name`, `label`, `input_type`, `is_secret`, `is_required`, `hint`, `sort_order`)
VALUES
  ('daily_report_reminder_repeat_minutes','15','notifications','Repeat the alarm every (minutes)','number', 0, 0, 'The alarm re-fires this often until the agent submits. 0 turns repeating off and leaves one reminder at the deadline.', 5),
  ('daily_report_reminder_until_hour','22','notifications','Stop repeating at (hour, 0-23)','number', 0, 0, 'Repeats stop at this hour and resume at the deadline on the next working day. An alarm through the night gets the app silenced.', 6)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
```

Check the backfill did something sensible before relying on the worklists:

```sql
SELECT `facility_type`, COUNT(*) FROM `loan_accounts` GROUP BY `facility_type`;
```

Anything that came out `NULL` and should not have can be set from the borrower's own page —
the facility is a normal editable field, and correcting it is recorded like any other
hand-edit so the next import leaves it alone.

**A new APK is required for the reminder changes.** The app side of this release removes the
agent's control over the daily alarm and makes it repeat until the report is filed:

- The reminder switch and the "nudge me 30 minutes early" picker are **gone** from the app.
  The deadline is the bank's and the agent is measured against it, so a reminder the
  measured person can move or silence was not doing its job. Set the time, the repeat
  interval and the cutoff hour under **Settings → Notifications**; every agent picks the
  change up the next time they open the app.
- The alarm now **re-fires every 15 minutes until the report is in**, and the notification
  no longer disappears on a swipe. It stops the instant a visit or the day's SSS figures are
  filed. It also stops at 22:00 and resumes at the deadline the next working day — "until it
  is submitted" cannot literally mean all night, because an alarm at 2 am gets the app's
  notifications switched off entirely and takes the working reminders with it.
- The in-app **location consent screen is gone**. Granting the operating system's location
  permission is now the whole of it, recorded once server-side for the audit trail. There is
  no longer an in-app toggle that can disagree with the OS setting — which was a real trap:
  an agent could grant the permission and still have every coordinate refused, with nothing
  anywhere explaining why their visits carried no location.
- **Recording starts by itself**, as soon as a signed-in agent opens the app with the
  permission held. The Start-duty button went with the consent screen: there is no switch
  for an agent to leave off. Android still requires the ongoing notification, and its
  **Stop** still ends the running session — but it is not a setting, so recording resumes
  the next time the app is opened. Signing out stops it.

An older APK against the new server keeps working; it simply still shows its own reminder
controls and still asks about location separately.

### Removing captured signatures on an existing install

Signatures are no longer captured anywhere — not on the phone, not from a desk. The
printed visit report now carries **empty ruled boxes** under the agent's photograph, and
the borrower and the agent sign the paper after it is printed. The approver signs the
same way.

The reason is not technical. A signature drawn with a fingertip on a 5-inch screen is
not something anybody accepts across a counter, and a bank that has to produce a signed
acknowledgement needs the signed acknowledgement, not a photograph of a squiggle.

**This deletes data.** `signatures` holds every mark ever captured, and the files sit
under `uploads/signatures/`. Take a backup first — `cron/backup.php` or a phpMyAdmin
export — and keep it, because nothing here can be undone.

```sql
-- 1. The table, and the approver's stored signature with it.
DROP TABLE IF EXISTS `signatures`;

ALTER TABLE `visit_reports`
  DROP COLUMN `approval_signature_path`;
```

The image files are not removed by that. Once you are satisfied nothing is missing,
delete `uploads/signatures/` from the file manager; until you do, they simply sit there
unreferenced and unservable — `/media` refuses any file it cannot trace back to a
photo, a document or an approval, so an orphan is never served.

Confirm it landed:

```sql
SELECT COUNT(*) FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'signatures';        -- 0
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'visit_reports'
   AND column_name LIKE 'approval%';                                   -- 9
```

Afterwards:

- **Print a visit report and look at it.** Under "Signatures" you should see the agent's
  photograph from the visit, then two bordered boxes — "BC Agent / DRA Signature" and
  "Supervisor Signature" — each with a rule to sign above and a name and date line
  beneath.
- **A new APK is required.** The signature pad is gone from the app, and an older APK
  will keep offering it: the agent can still draw one, the upload will be accepted as a
  field the server now ignores, and nothing will appear on the report. Roll the new APK
  out before you run this, or the agents' work goes nowhere for as long as the two are
  out of step.
- The visit report screen in the panel loses its Signatures card, and the approval form
  no longer asks the approver for an image. Everything else — photographs, documents,
  positions, the approval photograph — is untouched.

### Fixing the two on/off settings that were dropdowns

Two settings are yes/no questions but were defined as dropdowns whose choices were
literally `1` and `0`. **Settings → Notifications → Remind agents to submit** and
**Location & Maps → Resolve addresses from coordinates** both offered a menu containing
"1" and "0" and left the operator to work out which one meant on.

They are switches now. Nothing else about them changes — the stored value is still `1`
or `0`, and every reader of them is unaffected.

```sql
UPDATE `settings`
   SET `input_type` = 'toggle',
       `options`    = NULL
 WHERE `setting_key` IN ('daily_report_reminder_enabled', 'geocode_enabled');
```

Confirm it landed:

```sql
SELECT `setting_key`, `input_type`, `options` FROM `settings`
 WHERE `setting_key` IN ('daily_report_reminder_enabled', 'geocode_enabled');
-- both rows: input_type = toggle, options = NULL
```

Afterwards:

- **The daily report deadline is unchanged** and stays a dropdown, because it has eleven
  real choices (15:00 to 20:00 in half-hour steps). If your install shows that one as an
  empty dropdown, its `options` column is blank — the panel now falls back to a text box
  with an explanation rather than an unusable menu, and this fixes it properly:

  ```sql
  UPDATE `settings`
     SET `options` = '15:00,15:30,16:00,16:30,17:00,17:30,18:00,18:30,19:00,19:30,20:00'
   WHERE `setting_key` = 'daily_report_due_time';
  ```

- If a dropdown setting is storing a value that is not one of its choices — say the
  deadline was set to `17:15` directly in the database — the panel now keeps it, marks it
  "current, not in the list", and warns rather than silently rewriting it to the first
  choice on the next Save.
- No new APK. These are panel-side controls only.

### Letting the panel add a borrower by hand

Until this release a lead could only enter the system as a row in an Excel import, which
assumes head office has the account before the field does. It is frequently the other way
round: a branch hands an agent a new NPA, a takeover, or an account opened elsewhere, on
paper, and the agent is at the borrower's door weeks before that account appears in
anybody's export. The only way in was for somebody to build a one-row spreadsheet.

There is now **Add borrower** on the borrower list and **Add another account** on a
borrower's own page — a borrower can owe on more than one account, and a KCC and an OD-2
are two accounts and one person.

```sql
-- 1. Its own permission. Adding a borrower is not correcting one: an auditor holds
--    customers.view and must never be able to invent an account.
INSERT INTO `permissions` (`code`, `module`, `display_name`) VALUES
  ('customers.create', 'Customers', 'Add a borrower and loan account by hand');

-- 2. Branch managers and BC agents. The agent grant is the point of the release:
--    they are the ones standing in front of the borrower.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions` WHERE `code` = 'customers.create';
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 3, `id` FROM `permissions` WHERE `code` = 'customers.create';

-- 3. A typed lead is not an imported one. Logger::audit() and Timeline::record() both
--    throw on a value missing from an ENUM, so this has to land before anybody uses the
--    new form.
ALTER TABLE `visit_history`
  MODIFY COLUMN `event_type` ENUM(
    'lead_imported','lead_updated','assigned','reassigned','transferred',
    'visit','promise_created','promise_kept','promise_broken',
    'status_changed','closed','reopened','note',
    'visit_approved','visit_rejected','visit_revised',
    'lead_created'
  ) NOT NULL;
```

Confirm it landed:

```sql
SELECT COUNT(*) FROM `role_permissions` WHERE `role_id` = 3;   -- 10
SELECT COUNT(*) FROM `permissions`;                            -- 45
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'visit_history'
   AND column_name = 'event_type' AND column_type LIKE '%lead_created%';   -- 1
```

Afterwards:

- **What is typed is a placeholder, and the form says so.** A hand-created account carries
  no `manual_overrides`, so the first import that carries the same account number replaces
  the typed figures with the bank's — which is right, because the core banking system is
  the source of truth for a balance. A figure *corrected later* from the borrower's page is
  a different claim: that one is stamped as hand-edited and imports leave it alone.
- **An account added by an agent is assigned to that agent**, in that agent's branch,
  whatever the form posts. The panel shows an agent only the leads assigned to them, so an
  unassigned new lead would vanish the moment they saved it.
- **A duplicate account number is refused**, and the refusal names the borrower who already
  holds it and which branch they are in, so whoever typed it knows where to look instead.
- The timeline shows **"Lead created by hand"** with its own icon, so a typed account is
  never mistaken for one the core banking system produced.
- No new APK. This is a panel screen; the app does not create leads.

### Recording what the agent finds out at the door

The edit form existed so a mistake could be fixed. It also has to be somewhere to **add**
what nobody knew when the file was built, and it was not: the fields an agent actually
comes back with had nowhere to go.

Two things this release adds.

**A second phone number, and whose it is.** The borrower's own phone is dead more often
than not, and the number that reaches them belongs to a son, a brother, or the shop at the
crossroads. Without a place to put it an agent had two bad options: overwrite the number
the bank was given at sanction, or write the working number into a note where nothing can
dial it. The label is not decoration — "who am I speaking to" is the whole of a recovery
call's first ten seconds. Encrypted and hashed like the primary, so it is **searchable**
without being readable in the database, and **no importer touches it**: the bank's export
has no such column, so what is collected at a doorstep cannot be flattened by tomorrow's
file.

```sql
ALTER TABLE `customers`
  ADD COLUMN `alt_mobile_enc`    VARBINARY(255) DEFAULT NULL AFTER `aadhaar_masked`,
  ADD COLUMN `alt_mobile_hash`   CHAR(64)     DEFAULT NULL AFTER `alt_mobile_enc`,
  ADD COLUMN `alt_mobile_masked` VARCHAR(20)  DEFAULT NULL AFTER `alt_mobile_hash`,
  ADD COLUMN `alt_mobile_label`  VARCHAR(60)  DEFAULT NULL
    COMMENT 'whose number it is - son, brother, shop' AFTER `alt_mobile_masked`,
  ADD KEY `idx_customers_alt_mobile_hash` (`alt_mobile_hash`);
```

**Five more fields on the loan account, no schema change needed.** `sanction_date`,
`sanction_limit`, `drawing_power`, `interest_overdue` and `remarks` already existed but were
import-owned and unreachable, which made the passbook a borrower holds out at the door
useless — the agent could read the sanction limit straight off it and had nowhere to put it.
They are editable now, and like every other hand-edit they are stamped into
`manual_overrides` so the next import leaves them alone. `remarks` is the one that will get
used most: what an agent learns at a doorstep is rarely a number.

Confirm it landed:

```sql
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'customers'
   AND column_name LIKE 'alt_mobile%';                                  -- 4
SELECT COUNT(*) FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'customers'
   AND index_name = 'idx_customers_alt_mobile_hash';                    -- 1
```

Afterwards:

- **Open a borrower and look at the Borrower details card.** "Second mobile" and "Whose
  number is it" sit next to the mobile, and the profile shows the number as a `tel:` link
  for anybody holding `customers.view_pii`, masked for anybody who does not, and an
  invitation to add one when it is empty — a field nobody can see is a field nobody fills
  in.
- **Search works on it.** Somebody with a missed call is searching the number that called
  them, which is exactly the number the borrower does not answer on.
- **The old "Remarks from import" row on the profile is now "Notes on this account"**,
  because it is no longer only the import's. Visit remarks are untouched and still
  append-only; this is the standing note about the account.
- **The app gets the second number through `/leads` and `/customers/{id}`** under the same
  PII gate as the first, in both the list row and the profile. The **currently published
  APK ignores the new fields** — it does not know about them — so the next APK is what makes
  the second number dialable from the app. Nothing breaks in the meantime.
- No new APK is required for the panel side, which is where the recording happens.

### Seeing the location trail on a map

**This one is worth reading even if you skip the rest.** The app has been recording a
position every four minutes during a duty session, and `cron/purge-location-logs.php` has
been deleting those points after the retention window — and in between, **nobody could look
at any of it**. `TrackingService::trailFor()` existed, complete with its audit entry, and
had no caller anywhere: no route, no page, no map. Recording somebody's movements and then
never reading them is all of the intrusion and none of the use.

There is now **Location Trail** in the sidebar: pick an agent and a date, and see the day
as a line on a map, with the visit reports they filed that day drawn on the same map in a
different colour — because the question worth asking is not "where did they go" but "was
the report filed where the visit happened".

**No paid map key, no account, nothing that expires.** The map is
[Leaflet](https://leafletjs.com) (BSD-2) against OpenStreetMap's own tiles, which is the
same choice already made for reverse geocoding. Both files are loaded from jsDelivr with an
SRI hash that `tools/verify-cdn-integrity.php` checks against the real bytes. **You do not
need Google Maps, Mapbox, or any billing account.** OpenStreetMap's attribution is printed
on the map because their licence requires it — do not remove it.

```sql
INSERT INTO `permissions` (`code`, `module`, `display_name`) VALUES
  ('tracking.view', 'Tracking', "View an agent's location trail on a map");

-- Branch managers, for their own branch's agents.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 2, `id` FROM `permissions` WHERE `code` = 'tracking.view';

-- And agents, for THEMSELVES only - the page pins an agent to their own id and ignores a
-- requested one. Somebody whose movements are recorded should be able to see what was
-- recorded, and trailFor() already skips the audit entry when the viewer is the subject.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
  SELECT 3, `id` FROM `permissions` WHERE `code` = 'tracking.view';
```

Confirm it landed:

```sql
SELECT COUNT(*) FROM `permissions` WHERE `code` = 'tracking.view';           -- 1
SELECT COUNT(*) FROM `role_permissions` rp JOIN `permissions` p ON p.id = rp.permission_id
 WHERE p.code = 'tracking.view';                                            -- 2
```

Afterwards:

- **Open Location Trail and pick yesterday.** If it says "Nothing was recorded", that means
  the app was not open with location permission granted — *not* that the agent did no work,
  and the page says so in those words. Visit reports are the record of work.
- **A branch manager sees their own branch's agents only**, and an agent sees themselves and
  nobody else. Reading somebody else's trail writes a `view_location` row to the audit log;
  reading your own does not.
- **The distance is the sum of the legs between recorded points**, so it under-reports
  whenever the phone had no signal, and any jump over 20 km is dropped as a bad fix rather
  than credited as travel — with the count of dropped jumps printed, because a total that
  silently absorbed them is a number somebody could be judged on without knowing it was
  partly invented. It is not a timesheet and the page says that too.
- The points still expire on their own: `location_retention_days` (90 by default) and the
  purge cron, both unchanged. The trail page prints the retention window at the top.
- No new APK. The recording already worked; what was missing was the reading.

### Making the field visit report match the printed form

The paper form is **D2 Recovery Solutions & Services — Field Visit Verification Report
(KRM OTS / CKCC OD-2 Renewal / Recovery Verification Report)**, thirteen numbered
sections, RBI and Code-of-Conduct compliant. What this system collected was *most* of it
in a different order under different headings, and a branch could not file our printout
against that form without transcribing it by hand.

Now the screen, the app and the PDF are all that document, section 1 to 13, and the boxes
it asks for that had nowhere to go are here:

- **Case Type gains Pre-NPA Verification, Post-NPA Verification and Other.** These were
  being filed as plain recovery calls, which made the pre-NPA worklist — the one that
  exists to stop an account going bad — unbuildable from the reports themselves.
- **Section 1** gains Branch Code, Regional Office, Zone, Linked Branch and District.
  Regional Office and Zone are held on the **branch**, once, and stamped onto every
  report: an agent retyping them at forty doorsteps produces forty spellings.
- **Section 2** gains Gender, Date of Birth, Alternate Mobile, PAN and the address broken
  up as the form asks — Village, Gram Panchayat, Tehsil, District, State, PIN Code.
- **Section 3** gains CIF Number, the Loan Type tick list, Sanction Date, Sanction Limit,
  Drawing Power, Interest Overdue and **Asset Classification** (Standard / SMA-0 / SMA-1 /
  SMA-2 / NPA).
- **Section 4** gains the Customer Response row (Agreed / Requested Time / Financial
  Difficulty / Refused / Not Eligible), an Expected Deposit Date, and a scheme of "Other".
- **Section 6** gains Residence Verification and Neighbour Verification. Both start
  unanswered and stay that way until somebody answers: *not confirmed* is a claim about a
  check that was run, and silence is not.
- **Section 7, Documents Verified, moved out of the renewal section onto the report** and
  gained Electricity Bill, Renewal Form and OTS Consent Letter. It used to be asked only
  on a CKCC renewal, so a recovery visit had nowhere to record that an Aadhaar card was
  produced — and a renewal report showed the same eleven boxes twice, free to disagree
  with itself.
- **Section 9** gains the KRM OTS recommendation row, a **Pending Documents** box on the
  renewal row, and the General Recommendation prose box.
- **Section 10, Evidence Attached**, is entirely new: nine boxes for what the agent says
  the report carries. Printed next to the count of what actually arrived, because a report
  ticking "Passbook Copy" and carrying none is the thing worth seeing.
- **Section 11, the Declaration**, is printed in full on every copy and the agent has to
  tick it before the app will submit. Stored, not assumed — a printed certification nobody
  agreed to is worth nothing.
- **Section 12** gains the agent's Mobile Number (filled in from their own staff record)
  and the supervisor's Designation and Employee / DRA ID.
- **Section 13** gains the KRM OTS half of Final Report Status, seven boxes. Distinct from
  the approval status: an offer the branch has approved can still be waiting on the
  borrower's deposit, and the follow-up list is built from the second fact.
- **Occupation says Service, not Job**, which is the form's word and the only one that is
  distinguishable from Labour at a glance. Existing rows are rewritten by the migration.
- **The printed report now shows every tick box, ticked or not.** That reverses an earlier
  decision to print only what was true, and the reason is the reason it was reversed: an
  unticked box and a question the form never asked looked identical, so "the neighbours
  were not asked" read the same as "this version had no such field".

**A PAN is encrypted, hashed and masked** like the mobile and the Aadhaar, and shown in
full only to somebody holding `customers.view_pii`. It is hashed with its letters intact —
the mobile hash normalises to digits only, so run a PAN through it and every card collapses
to its four-digit block, and two unrelated borrowers would share a hash.

```sql
-- The masthead of the printed form. NOT bank_name: the form is the recovery agency's own
-- document, filed WITH a bank, and printing the bank's name over the whole of it claimed
-- the bank had issued it. A separate key so an agency can put its own name there.
INSERT INTO `settings`
  (`setting_key`, `setting_value`, `group_name`, `label`, `input_type`, `options`,
   `is_secret`, `is_required`, `hint`, `sort_order`)
VALUES
  ('report_org_name', 'D2 Recovery Solutions & Services', 'general',
   'Organisation name on printed forms', 'text', NULL, 0, 0,
   'Masthead of the Field Visit Verification Report', 3);

-- Where a branch sits in the bank's hierarchy. Held here once so the printed header
-- does not carry four spellings of the same regional office.
ALTER TABLE `branches`
  ADD COLUMN `regional_office` VARCHAR(150) DEFAULT NULL AFTER `pincode`,
  ADD COLUMN `zone`            VARCHAR(150) DEFAULT NULL AFTER `regional_office`;

-- ---------------------------------------------------------------------------
-- The report itself. Everything nullable or defaulted, so it runs on a populated
-- table and every report filed before today still reads correctly - as a report that
-- did not ask these questions, which is what it was.
-- ---------------------------------------------------------------------------
ALTER TABLE `visit_reports`
  -- Section 1
  MODIFY COLUMN `bc_code` VARCHAR(40) DEFAULT NULL COMMENT 'BC Code / DRA ID',
  MODIFY COLUMN `village` VARCHAR(150) DEFAULT NULL COMMENT 'where the visit happened',
  ADD COLUMN `branch_code`     VARCHAR(40)  DEFAULT NULL AFTER `village`,
  ADD COLUMN `regional_office` VARCHAR(150) DEFAULT NULL AFTER `branch_code`,
  ADD COLUMN `zone`            VARCHAR(150) DEFAULT NULL AFTER `regional_office`,
  ADD COLUMN `linked_branch`   VARCHAR(150) DEFAULT NULL COMMENT 'the branch the BC/DRA is attached to' AFTER `zone`,
  ADD COLUMN `district`        VARCHAR(150) DEFAULT NULL COMMENT 'district the visit happened in' AFTER `linked_branch`,
  ADD COLUMN `report_type_other_text` VARCHAR(150) DEFAULT NULL COMMENT 'when Case Type is Other' AFTER `report_type`,

  -- Section 2
  ADD COLUMN `gender`        ENUM('male','female','other') DEFAULT NULL AFTER `father_husband_name`,
  ADD COLUMN `date_of_birth` DATE DEFAULT NULL AFTER `gender`,
  ADD COLUMN `alt_mobile_enc`    VARBINARY(255) DEFAULT NULL AFTER `mobile_masked`,
  ADD COLUMN `alt_mobile_hash`   CHAR(64)     DEFAULT NULL AFTER `alt_mobile_enc`,
  ADD COLUMN `alt_mobile_masked` VARCHAR(20)  DEFAULT NULL AFTER `alt_mobile_hash`,
  ADD COLUMN `pan_enc`    VARBINARY(255) DEFAULT NULL AFTER `aadhaar_masked`,
  ADD COLUMN `pan_hash`   CHAR(64)     DEFAULT NULL AFTER `pan_enc`,
  ADD COLUMN `pan_masked` VARCHAR(20)  DEFAULT NULL AFTER `pan_hash`,
  ADD COLUMN `addr_village`   VARCHAR(150) DEFAULT NULL AFTER `pan_masked`,
  ADD COLUMN `gram_panchayat` VARCHAR(150) DEFAULT NULL AFTER `addr_village`,
  ADD COLUMN `tehsil`         VARCHAR(150) DEFAULT NULL AFTER `gram_panchayat`,
  ADD COLUMN `addr_district`  VARCHAR(150) DEFAULT NULL AFTER `tehsil`,
  ADD COLUMN `state`          VARCHAR(100) DEFAULT NULL AFTER `addr_district`,
  ADD COLUMN `pin_code`       VARCHAR(10)  DEFAULT NULL AFTER `state`,
  MODIFY COLUMN `address` VARCHAR(500) DEFAULT NULL COMMENT 'complete residential address',

  -- Section 3
  ADD COLUMN `cif_number`           VARCHAR(40)  DEFAULT NULL AFTER `loan_account_number`,
  ADD COLUMN `loan_type_other_text` VARCHAR(150) DEFAULT NULL AFTER `loan_type`,
  ADD COLUMN `sanction_date`     DATE          DEFAULT NULL AFTER `loan_type_other_text`,
  ADD COLUMN `sanction_limit`    DECIMAL(15,2) DEFAULT NULL AFTER `sanction_date`,
  ADD COLUMN `drawing_power`     DECIMAL(15,2) DEFAULT NULL AFTER `sanction_limit`,
  ADD COLUMN `interest_overdue`  DECIMAL(15,2) DEFAULT NULL AFTER `outstanding_amount`,
  ADD COLUMN `asset_classification` ENUM('standard','sma_0','sma_1','sma_2','npa') DEFAULT NULL AFTER `npa_date`,

  -- Section 6
  ADD COLUMN `residence_verified`     ENUM('confirmed','not_confirmed') DEFAULT NULL AFTER `shifted`,
  ADD COLUMN `neighbour_verification` ENUM('conducted','not_conducted') DEFAULT NULL AFTER `residence_verified`,

  -- Section 7
  ADD COLUMN `doc_aadhaar`            TINYINT(1) NOT NULL DEFAULT 0 AFTER `occupation_other_text`,
  ADD COLUMN `doc_pan`                TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_aadhaar`,
  ADD COLUMN `doc_passbook`           TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_pan`,
  ADD COLUMN `doc_land_record`        TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_passbook`,
  ADD COLUMN `doc_khatauni`           TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_land_record`,
  ADD COLUMN `doc_electricity_bill`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_khatauni`,
  ADD COLUMN `doc_photograph`         TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_electricity_bill`,
  ADD COLUMN `doc_mobile_verified`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_photograph`,
  ADD COLUMN `doc_renewal_form`       TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_mobile_verified`,
  ADD COLUMN `doc_ots_consent_letter` TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_renewal_form`,
  ADD COLUMN `doc_others`             TINYINT(1) NOT NULL DEFAULT 0 AFTER `doc_ots_consent_letter`,
  ADD COLUMN `doc_other_text`         VARCHAR(255) DEFAULT NULL AFTER `doc_others`,

  -- Sections 9 and 10
  ADD COLUMN `general_recommendation` TEXT DEFAULT NULL AFTER `rec_other_text`,
  ADD COLUMN `ev_borrower_photo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `general_recommendation`,
  ADD COLUMN `ev_house_photo`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_borrower_photo`,
  ADD COLUMN `ev_land_photo`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_house_photo`,
  ADD COLUMN `ev_aadhaar_copy`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_land_photo`,
  ADD COLUMN `ev_passbook_copy`  TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_aadhaar_copy`,
  ADD COLUMN `ev_gps_location`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_passbook_copy`,
  ADD COLUMN `ev_renewal_form`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_gps_location`,
  ADD COLUMN `ev_ots_consent`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_renewal_form`,
  ADD COLUMN `ev_others`         TINYINT(1) NOT NULL DEFAULT 0 AFTER `ev_ots_consent`,
  ADD COLUMN `ev_other_text`     VARCHAR(255) DEFAULT NULL AFTER `ev_others`,

  -- Sections 8, 11 and 12
  MODIFY COLUMN `remarks` TEXT DEFAULT NULL COMMENT 'BC agent / DRA observations',
  ADD COLUMN `declaration_accepted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `remarks`,
  ADD COLUMN `agent_mobile`           VARCHAR(20)  DEFAULT NULL AFTER `declaration_accepted`,
  ADD COLUMN `supervisor_designation` VARCHAR(100) DEFAULT NULL AFTER `supervisor_name`,
  ADD COLUMN `supervisor_employee_id` VARCHAR(40)  DEFAULT NULL COMMENT 'Employee ID / DRA ID' AFTER `supervisor_designation`,

  -- Case Type: three more, and Occupation says Service.
  MODIFY COLUMN `report_type` ENUM('recovery','ots','ckcc_renewal','pre_npa','post_npa','other')
    NOT NULL DEFAULT 'recovery',
  MODIFY COLUMN `occupation` ENUM('agriculture','dairy','business','labour','service','others','job')
    DEFAULT NULL;

-- 'job' and 'service' are the same fact under two names, so the rows are rewritten and
-- then the old name is removed. Done in this order deliberately: dropping the value
-- first would silently blank every occupation ever recorded.
UPDATE `visit_reports` SET `occupation` = 'service' WHERE `occupation` = 'job';
ALTER TABLE `visit_reports`
  MODIFY COLUMN `occupation` ENUM('agriculture','dairy','business','labour','service','others')
    DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- The settlement section.
-- ---------------------------------------------------------------------------
ALTER TABLE `visit_ots_details`
  MODIFY COLUMN `scheme` ENUM('krm_ots','general_ots','other') DEFAULT NULL,
  ADD COLUMN `scheme_other_text` VARCHAR(150) DEFAULT NULL AFTER `scheme`,
  ADD COLUMN `customer_response` ENUM('agreed','requested_time','financial_difficulty',
                                     'refused','not_eligible') DEFAULT NULL AFTER `borrower_accepted`,
  ADD COLUMN `expected_deposit_date` DATE DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN `rec_proposal_recommended` TINYINT(1) NOT NULL DEFAULT 0 AFTER `expected_deposit_date`,
  ADD COLUMN `rec_followup_required`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `rec_proposal_recommended`,
  ADD COLUMN `rec_customer_refused`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `rec_followup_required`,
  ADD COLUMN `rec_not_eligible`         TINYINT(1) NOT NULL DEFAULT 0 AFTER `rec_customer_refused`,
  ADD COLUMN `st_customer_contacted`       TINYINT(1) NOT NULL DEFAULT 0 AFTER `rec_not_eligible`,
  ADD COLUMN `st_customer_verified`        TINYINT(1) NOT NULL DEFAULT 0 AFTER `st_customer_contacted`,
  ADD COLUMN `st_ots_accepted`             TINYINT(1) NOT NULL DEFAULT 0 AFTER `st_customer_verified`,
  ADD COLUMN `st_ots_rejected`             TINYINT(1) NOT NULL DEFAULT 0 AFTER `st_ots_accepted`,
  ADD COLUMN `st_initial_deposit_received` TINYINT(1) NOT NULL DEFAULT 0 AFTER `st_ots_rejected`,
  ADD COLUMN `st_ots_closed`               TINYINT(1) NOT NULL DEFAULT 0 AFTER `st_initial_deposit_received`,
  ADD COLUMN `st_followup_required`        TINYINT(1) NOT NULL DEFAULT 0 AFTER `st_ots_closed`;

-- ---------------------------------------------------------------------------
-- The renewal section: one box added, and the duplicated checklist removed.
-- ---------------------------------------------------------------------------
ALTER TABLE `visit_ckcc_details`
  ADD COLUMN `rec_pending_documents` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rec_documents_submitted`;

-- Every renewal report ever filed carried its document ticks here. They are copied onto
-- the report BEFORE the columns go, so nothing an agent recorded is lost - a DROP on its
-- own would quietly discard the whole checklist of every renewal in the database.
UPDATE `visit_reports` vr
  JOIN `visit_ckcc_details` c ON c.`visit_report_id` = vr.`id`
   SET vr.`doc_aadhaar`     = c.`doc_aadhaar`,
       vr.`doc_pan`         = c.`doc_pan`,
       vr.`doc_passbook`    = c.`doc_passbook`,
       vr.`doc_land_record` = c.`doc_land_record`,
       vr.`doc_khatauni`    = c.`doc_khasra_khatauni`,
       vr.`doc_photograph`  = c.`doc_photograph`,
       vr.`doc_mobile_verified` = c.`doc_mobile_available`,
       vr.`doc_others`      = c.`doc_others`,
       vr.`doc_other_text`  = c.`doc_other_text`;

ALTER TABLE `visit_ckcc_details`
  DROP COLUMN `doc_aadhaar`,
  DROP COLUMN `doc_pan`,
  DROP COLUMN `doc_passbook`,
  DROP COLUMN `doc_land_record`,
  DROP COLUMN `doc_khasra_khatauni`,
  DROP COLUMN `doc_photograph`,
  DROP COLUMN `doc_mobile_available`,
  DROP COLUMN `doc_others`,
  DROP COLUMN `doc_other_text`;
```

Confirm it landed:

```sql
-- 56 new columns on the report, 14 on the settlement, 1 on the renewal.
SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_reports'
   AND COLUMN_NAME IN ('branch_code','regional_office','zone','linked_branch','district',
       'report_type_other_text','gender','date_of_birth','alt_mobile_masked','pan_masked',
       'gram_panchayat','tehsil','state','pin_code','cif_number','sanction_limit',
       'asset_classification','residence_verified','neighbour_verification',
       'doc_electricity_bill','doc_ots_consent_letter','general_recommendation',
       'ev_borrower_photo','ev_gps_location','declaration_accepted','agent_mobile',
       'supervisor_designation','supervisor_employee_id');   -- 28

-- The Case Type row now offers six choices, and Occupation says Service.
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_reports'
   AND COLUMN_NAME = 'report_type';           -- includes 'pre_npa','post_npa','other'
SELECT COUNT(*) FROM `visit_reports` WHERE `occupation` = 'job';   -- 0

-- And the checklist is on the report, not duplicated on the renewal row.
SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_ckcc_details'
   AND COLUMN_NAME LIKE 'doc\\_%';             -- 0

-- The masthead reads the agency, not the bank.
SELECT `setting_value` FROM `settings` WHERE `setting_key` = 'report_org_name';
```

Afterwards:

- **Fill in Regional office and Zone on each branch** (Branches → edit). They are blank
  after the migration, and they print at the top of every report. Nothing breaks without
  them; the header simply has two dashes in it.
- **Check Settings → General → Organisation name on printed forms.** It seeds as
  *D2 Recovery Solutions & Services* and is what the masthead prints. It is deliberately
  separate from *Bank / institution name*: the form is the agency's document, filed with a
  bank, so the bank's name belongs against the account and not over the whole page.
- **Two signature boxes, not four.** The borrower's and the approver's are gone. A
  borrower signature on a bank's internal verification report makes a document the
  borrower had no say in look endorsed by them — where their consent genuinely matters it
  is a separate instrument (an OTS consent letter, a renewal form), and sections 7 and 10
  record that those exist. The approver signs in the panel, where their identity, time,
  position and photograph are recorded against their account; a pen mark beside that adds
  nothing and invites signing the paper while never recording the decision.
- **The NPA date now reads "Probable NPA/NPA DATE"** on the printed form and the door
  sheet. It is one column with two readings — the day an account is expected to turn
  NPA, and the day it did — and labelling it only "NPA Date" made a projection look like a
  classification that had already happened. The panel screens and spreadsheet column
  headers are unchanged: they are keys another system reads back.
- **Print one report.** It comes out as the paper form: a numbered band per section, every
  tick box shown whether or not it is ticked, the declaration in full, the closing note,
  and blank ruled boxes for the agent, the borrower, the supervisor and the approver.
- **Old reports still print**, as reports that did not ask the new questions — every new
  box unticked, every new line a dash. That is what they were, and it is why none of these
  columns is `NOT NULL` without a default.
- **A new APK is required.** The form has three new cards, two new dropdown rows, twenty
  new tick boxes and a declaration the app will not submit without. An older APK keeps
  working — it posts what it always posted and the new boxes stay unticked — but it cannot
  collect any of this.
- The document checklist an agent fills in on a renewal now appears **once**, in section 7,
  for every case type. If your staff were trained to look for it inside the renewal block,
  tell them where it went.

### Adding BC Basic Details to an existing install

New BC agent accounts now capture the bank's own reporting hierarchy and the
agent's registration numbers, not just a name and an internal employee code:
SP/CBC Name, BC Name, BCBF Code, SSA, Link Branch, District, Region (RO), IIBF
No., Mobile No. (already existed), Aadhaar and PAN. Every field is optional at
the database level - only the Add/Edit user form asks for them, and only for
the BC agent role - so this is additive and safe to run on a populated table.

```sql
ALTER TABLE `users`
  ADD COLUMN `sp_cbc_name`    VARCHAR(150)   DEFAULT NULL COMMENT 'SP / CBC the BC reports to' AFTER `designation`,
  ADD COLUMN `bc_name`        VARCHAR(150)   DEFAULT NULL COMMENT 'the BC point''s registered name; may differ from the login name' AFTER `sp_cbc_name`,
  ADD COLUMN `bcbf_code`      VARCHAR(40)    DEFAULT NULL COMMENT 'BC/BF code issued by the bank, distinct from bc_code (the internal field-report code)' AFTER `bc_name`,
  ADD COLUMN `ssa`            VARCHAR(150)   DEFAULT NULL COMMENT 'Sub Service Area covered' AFTER `bcbf_code`,
  ADD COLUMN `link_branch`    VARCHAR(150)   DEFAULT NULL COMMENT 'the branch this BC is linked to for banking transactions' AFTER `ssa`,
  ADD COLUMN `district`       VARCHAR(100)   DEFAULT NULL AFTER `link_branch`,
  ADD COLUMN `region_ro`      VARCHAR(100)   DEFAULT NULL COMMENT 'Region / Regional Office' AFTER `district`,
  ADD COLUMN `iibf_number`    VARCHAR(40)    DEFAULT NULL COMMENT 'IIBF certification number' AFTER `region_ro`,
  ADD COLUMN `dra_name_id`    VARCHAR(150)   DEFAULT NULL COMMENT 'the Direct Recovery Agent''s own name/ID, when the BC works through one' AFTER `iibf_number`,
  ADD COLUMN `aadhaar_enc`    VARBINARY(255) DEFAULT NULL AFTER `dra_name_id`,
  ADD COLUMN `aadhaar_hash`   CHAR(64)       DEFAULT NULL AFTER `aadhaar_enc`,
  ADD COLUMN `aadhaar_masked` VARCHAR(20)    DEFAULT NULL AFTER `aadhaar_hash`,
  ADD COLUMN `pan_enc`        VARBINARY(255) DEFAULT NULL AFTER `aadhaar_masked`,
  ADD COLUMN `pan_hash`       CHAR(64)       DEFAULT NULL AFTER `pan_enc`,
  ADD COLUMN `pan_masked`     VARCHAR(20)    DEFAULT NULL AFTER `pan_hash`,
  ADD KEY `idx_users_bcbf_code` (`bcbf_code`),
  ADD KEY `idx_users_aadhaar_hash` (`aadhaar_hash`);
```

Confirm it landed:

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND COLUMN_NAME IN ('sp_cbc_name','bc_name','bcbf_code','ssa','link_branch',
                        'district','region_ro','iibf_number','dra_name_id',
                        'aadhaar_enc','pan_enc');
```

Aadhaar and PAN are encrypted at rest with the same AES-256-GCM scheme already
used for a borrower's Aadhaar, so no new crypto key is needed - `data_key` in
`config.php` already covers it. Existing BC accounts simply show these fields
blank until somebody edits them in; nothing is backfilled or guessed.

### Adding Address Details to an existing install

BC agent accounts can now carry their own residential address - Address,
Village, Block, Tehsil, District, State and Pincode - separate from the
reporting-hierarchy `district` BC Basic Details already added, since a BC's
home district and their reporting district are not always the same place.
Every column is optional, so this is additive and safe on a populated table.

Run this AFTER the BC Basic Details migration above - it anchors its first
column with `AFTER dra_name_id`, a column that migration is what creates.

```sql
ALTER TABLE `users`
  ADD COLUMN `addr_line`     VARCHAR(500) DEFAULT NULL COMMENT 'Address' AFTER `dra_name_id`,
  ADD COLUMN `addr_village`  VARCHAR(150) DEFAULT NULL AFTER `addr_line`,
  ADD COLUMN `addr_block`    VARCHAR(150) DEFAULT NULL AFTER `addr_village`,
  ADD COLUMN `addr_tehsil`   VARCHAR(150) DEFAULT NULL AFTER `addr_block`,
  ADD COLUMN `addr_district` VARCHAR(100) DEFAULT NULL AFTER `addr_tehsil`,
  ADD COLUMN `addr_state`    VARCHAR(100) DEFAULT NULL AFTER `addr_district`,
  ADD COLUMN `addr_pin_code` VARCHAR(10)  DEFAULT NULL AFTER `addr_state`;
```

Confirm it landed:

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND COLUMN_NAME IN ('addr_line','addr_village','addr_block','addr_tehsil',
                        'addr_district','addr_state','addr_pin_code');
```

### Letting any Excel file's unmapped columns become custom fields

Uploading a spreadsheet whose headers ColumnDetector cannot place used to drop
those columns silently. They now become `loan_account` custom fields
automatically - the first import creates the definition, every later import
(and the app's own edit screen) reuses it. A person's own correction is
protected from being overwritten by the next import the same way a hand-edited
loan figure already is, via a new column on `custom_field_values`.

```sql
ALTER TABLE `custom_field_values`
  ADD COLUMN `is_manual_override` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'set when a person, not an import, typed this value' AFTER `value`;
```

Confirm it landed:

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_field_values'
   AND COLUMN_NAME = 'is_manual_override';
```

Nothing else is needed: every existing row in `custom_field_values` defaults to
`0` (import-owned), which is the correct answer for values that already existed
before this column did - they will simply be treated as ordinary imported data
until somebody corrects one, at which point that answer becomes protected the
same way a newly-imported one would be.

### Remembering a corrected column mapping across files

The import screen's confirm step let an operator fix a wrong column guess -
but only for that one file. A bank exporting `ADRESS` with village names in it
(not a street address at all) needed the same correction every single month.
Every mapping the confirm step submits is now taught to a new table and
recalled automatically on the next file carrying the same heading.

```sql
CREATE TABLE `column_header_aliases` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `heading_key`      VARCHAR(120) NOT NULL,
  `field`            VARCHAR(60)  NOT NULL,
  `original_heading` VARCHAR(150) NOT NULL,
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_column_alias_heading` (`heading_key`),
  CONSTRAINT `fk_column_alias_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Confirm it landed:

```sql
SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'column_header_aliases';
```

A missing table does not break an import: `ColumnDetector` catches the lookup
failing and falls back to detecting with no memory, exactly as it always has.
The **Excel Import** screen shows a **Taught column mappings** panel once at
least one exists, with a button to forget a wrong lesson.

### Editing loan and borrower details, and custom fields, from the app

The Android app can now read the full core banking statement (sanction limit,
drawing power, security value, days past due and the rest of
`LoanAccount::MANUALLY_EDITABLE`) and every custom field on an account, and an
agent can correct any of them from the profile screen - not only the fixed
fields the earlier release already exposed. No schema change: this reuses the
same columns, the same `LoanAccount::applyManualEdit()` and
`CustomField::saveValues()` the admin panel's edit form already writes through,
behind a new `PUT /api/v1/customers/{id}` route. An install only needs the code
copied across; there is nothing to migrate.

### Renaming to D2 Recovery Solutions & Services on an existing install

The product name is stored in the `settings` table, not in the code — that is the
point of the setting, so an operator can change it without a deploy. Copying new
files therefore does **not** rename an install that was seeded earlier: the panel
header, PDF and Excel exports and the OTP text all keep showing the old name.

Easiest fix, in the panel: **Settings → General → Application name**. Or run this
once in phpMyAdmin; it is idempotent and touches nothing an operator has already
customised:

```sql
UPDATE `settings` SET `setting_value` = 'D2 Recovery Solutions & Services'
 WHERE `setting_key` IN ('app_name', 'smtp_from_name')
   AND `setting_value` = 'LRMS';

UPDATE `settings` SET `setting_value` = REPLACE(`setting_value`, 'LRMS', 'D2 Recovery Solutions & Services')
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
