# D2 Recovery — Loan Recovery Management System

Field verification and recovery follow-up for **BC/DC agents** working on behalf of banks.

Agents visit borrowers, record what they found, capture a photo and the borrower's
photograph, and log a promise-to-pay date. **Agents never collect cash and the system
never processes a payment** — repayment always happens through the bank's own channels.

| Component | Stack |
|---|---|
| Admin panel | PHP 8.1+ · Bootstrap 5.3 (CDN) · server-rendered views |
| REST API | PHP 8.1+ · JWT (HS256) · JSON |
| Database | MySQL 8 / MariaDB 10.4+ |
| Mobile app | Android · Kotlin 2.2 · Material Design 3 (Views + ViewBinding) |
| Hosting | Shared hosting / cPanel / LiteSpeed — **no Composer, zero PHP dependencies** |

---

## Contents

- [What it does](#what-it-does)
- [What it deliberately does not do](#what-it-deliberately-does-not-do)
- [Repository layout](#repository-layout)
- [Quick start](#quick-start)
- [Roles and permissions](#roles-and-permissions)
- [Reports](#reports)
- [REST API](#rest-api)
- [Android app](#android-app)
- [Security](#security)
- [Verification](#verification)
- [Continuous integration](#continuous-integration)
- [Deployment](#deployment)

---

## What it does

**Lead management**
- Bulk import of loan accounts from bank Excel/CSV exports, with column mapping,
  dry-run preview, per-row validation and a downloadable error CSV.
- **35 columns are recognised from their headings**, which is everything a core banking
  NPA or recovery statement carries: the balances and the settlement position, plus asset
  classification, DPD, rate of interest, instalment, last payment date and amount,
  security value, guarantor, maturity date, purpose and the closure figure. Each has a
  synonym list — `dpd`, `days past due`, `ageing days` and `अतिदेय दिन` are the same
  column — so a real export usually needs no mapping at all. Asset classification is
  stored as text rather than an enum: banks write it twenty ways (`SS`, `D-2`,
  `DOUBTFUL-II`, `SMA 2`, `LOSS ASSET`), the recognised spellings collapse to one
  canonical label, and anything unrecognised is kept verbatim rather than becoming a
  `NULL` — it is the most useful prioritisation column in the file.
- **Leads spread evenly across a branch's agents**, on import or as a bulk action. This
  balances what each agent is *already* carrying rather than dealing the rows out in
  turn: round-robin looks fair inside one file and isn't, because two imports both start
  at the same agent and one person ends up with every other lead. Naming a single agent
  is still available for when a specific village should go to a specific person, and
  reassignment afterwards works either way. An import never moves a lead somebody is
  already working, and a branch with no active agent leaves its leads unassigned and says
  so rather than importing them to nobody.
- Manual assign / reassign / transfer / unassign, and duplicate detection on loan account
  number.
- **A borrower can also be added by hand, and a BC agent can do it.** An import assumes
  head office has the account before the field does, and it is often the other way round:
  a branch hands an agent a new NPA, a takeover, or an account opened elsewhere on paper,
  weeks before it appears in anybody's export. Building a one-row spreadsheet to get that
  into the system is not a thing anybody does, so there is **Add borrower** on the list and
  **Add another account** on a borrower's page — a KCC and an OD-2 are two accounts and one
  person. `customers.create` is its own permission rather than part of `customers.update`,
  because adding an account is not correcting one and an auditor must never be able to
  invent one.
- **What is typed by hand is a placeholder, and the form says so.** A created account
  carries no override, so the first import that carries the same number replaces the typed
  figures with the bank's — the core banking system owns a balance. A figure *corrected
  later* is the opposite claim and is stamped as hand-edited, so imports leave that one
  alone. A duplicate account number is refused with the name and branch of the borrower who
  already holds it, and the timeline records **"Lead created by hand"** so a typed account
  is never mistaken for one the core banking system produced.
- **What the agent finds out at the door has somewhere to go.** The edit form was there to
  fix a mistake; it is now also where the things nobody knew when the file was built get
  recorded. A **second phone number with a label** — the borrower's own phone is dead more
  often than not and the number that reaches them belongs to a son, a brother or the shop at
  the crossroads, so recording it used to mean overwriting the number the bank was given at
  sanction, or writing it into a note nothing can dial. It is encrypted and hashed like the
  primary, so it is **searchable** (somebody with a missed call is searching the number that
  called them) and **no importer can touch it**, because the bank's export has no such
  column. Plus the sanction side of the passbook — sanction date, sanction limit, drawing
  power, interest overdue — and a standing **note on the account**, which is what an agent
  usually comes back with: "shifted to Delhi, brother works the land" is not a number and
  had nowhere to live.
- An account an agent creates is **assigned to that agent, in that agent's branch**,
  whatever the form posts — the panel shows an agent only their own leads, so an unassigned
  new lead would vanish the moment they saved it.

**Field reports — three types**
- **Recovery visit** — the standard call: contact, verification, recovery
  possibility, non-payment reason, recommendation.
- **KRM / OTS settlement** — eligibility, scheme, relief %, borrower's payable
  amount, the 10% initial deposit (recorded from the *bank's* receipt — the agent
  never handles money), approval status, validity window, borrower's acceptance.
- **CKCC OD-2 renewal** — account figures, renewal deadline with the **expected
  NPA date** if it is missed, KYC/Aadhaar seeding, documents the borrower had in
  hand, renewal consent and biometrics, agent observation and report status.

**Field visits**
- Agent opens a lead, records outcome (paid, promised, refused, not found,
  disputed, absent, …), amount promised, promise date and free-text remarks.
- Photo capture (borrower / house / document / the agent's own, at the door).
- **Photographs are geo-stamped and carry their source.** Each photo stores its own
  coordinates, accuracy, capture time and whether it came from the camera or the gallery,
  and both the printed report *and the panel* caption every photograph with that. The
  source is sent by the app, never inferred from whether a fix was obtained — a camera
  photo taken inside a house has no fix, and recording that as a gallery pick is an
  accusation on a recovery file. On screen a gallery pick is badged differently from a
  camera capture, and a fix too coarse to place a particular house (worse than 50 m —
  roughly a cell-tower triangulation) is marked as such, because "26.912400, 75.787300"
  reads identically whether it is accurate to 8 metres or to a district.
- **The agent's own photograph records where the agent was standing.** A camera-only slot
  takes it at the door — camera-only because the slot's entire purpose is to record
  presence, and an image chosen from the gallery was taken at an unknown time in an unknown
  place.
- **Only photographs taken at the visit are printed.** There is no fallback to a stored
  portrait. No image is held against a person at all any more — an image belongs to the
  thing it evidences, so a photograph belongs to the visit it was taken at. When a report
  has no doorstep photograph the printed copy says so in words, which is a weaker claim
  than a portrait and a true one.
- **Signatures are signed on paper, not on a screen.** Nothing in the app or the panel
  captures one. The printed report carries empty ruled boxes — the borrower's and the
  agent's, directly under the agent's photograph from the visit, each with a rule to sign
  above and a name and date line beneath — and they are signed by hand after printing. A
  mark drawn with a fingertip on a 5-inch screen is not something anybody accepts across a
  counter, and a bank that has to produce a signed acknowledgement needs the signed
  acknowledgement rather than a photograph of a squiggle.
- Submissions are **multipart and idempotent** — a `client_uuid` makes a retry on a
  flaky rural connection return the original report instead of creating a duplicate.
- **Visit history is append-only.** The `visit_history` table is written by trigger-free
  service code and the panel exposes no update or delete path for it.
- **A filed report can be corrected, and the correction is part of the record.** Eight
  identity fields (names, address, village, family member, supervisor) may be fixed by a
  reviewer with a mandatory reason. The row is updated so the report reads correctly from
  now on, and the same transaction writes the before and after of every field that moved
  into `visit_report_revisions`, which nothing ever updates or deletes. The submitted
  original is reconstructible by replaying those backwards, and the printed report states
  how many times it was corrected — a clean-looking document cannot hide that it differs
  from what was filed. The tick boxes, the recommendation and the remarks are **not**
  correctable: those are the agent's assertions about what they saw, and a reviewer
  overwriting them turns the agent's report into the reviewer's.

**Approval of a filed report**
- A branch manager or admin approves or rejects a visit report with **their own
  geo-tagged photograph**, plus remarks — because "I approved it at the branch" and "I
  approved forty of them from home at midnight" are different claims and only one of them
  is verification. Their signature goes in the blank box the printout carries for it —
  the same size as the borrower's and the agent's, and printed before approval as well as
  after, because a form with nowhere to sign is no use to the person about to sign it.
- The position comes from the browser and records `device`, `denied` and `unavailable`
  as distinct outcomes. A missing fix does not block the approval: refusing to accept it
  would push approvals off the system and onto a phone call, which records nothing.
- Approval is purely additive — it writes the approval columns and touches nothing the
  agent submitted. The approver's photograph, position and remarks all print on the report,
  above a blank box for their signature.

**Promises to pay**
- Promise ledger per loan account with kept / broken / pending state.
- Due and overdue promise reminders raised by cron into in-app notifications
  (and optional SMS/push).

**Reporting**
- Eight report types with on-screen tables plus **PDF and Excel (xlsx) export**,
  both generated by hand-rolled writers with no external libraries.

**Administration**
- Branch, user and role/permission management with a real permission matrix.
- **No photograph or signature is held against a person.** Creating a BC asks for neither.
  There were both, printed at the foot of every report that person filed, and they were
  removed: a stored image says nothing about the visit it gets printed on. A photograph now
  belongs to the thing it evidences — the visit it was taken at, with its own fix. And no
  signature is stored anywhere at all: the printed page carries the space, the paper
  carries the mark.
- **Borrower and loan figures are fully editable**, and every hand-correction is
  recorded in `manual_overrides` with who and when. The next import **skips** the columns
  a human corrected and reports which accounts and fields it left alone. Without that the
  edit is pointless: somebody fixes an outstanding balance, the nightly import silently
  restores the wrong number, and nobody finds out until an agent quotes it at a doorstep.
- **Fields you add yourself**, without a code change — on borrowers, loan accounts or
  visit reports. Eight types (text, long text, number, money, date, select, toggle), and
  each definition can be marked to print on the visit report. Stored as definitions plus
  values, not a JSON bag, so "which borrowers have no PAN" is answerable. A blank answer
  deletes the row so *not recorded* stays distinguishable from *recorded as empty*; an
  unchecked toggle is a real "no". Retiring a field keeps its answers, deleting one
  destroys them, and the confirmation says how many.
- Audit log (who changed what) and activity log (who did what, from where).
- Database backup to `.sql` from the panel or cron, with retention pruning.
- Settings screen for app name, pagination, promise reminder windows, SMS gateway
  credentials and optional FCM server key.

---

## What it deliberately does not do

These are hard product constraints, not missing features:

- ❌ **No payment collection** of any kind — no cash logging, no gateway, no UPI.
- ❌ **No attendance, check-in/check-out or working-hours monitoring.**
- ❌ **No deletion of submitted visit history, and no untracked editing of a filed
  report.** A reviewer may correct the identity fields of a report with a reason, and
  every such correction is written to an append-only revisions table and counted on the
  printed document. What is refused is a silent edit: there is no path anywhere that
  changes a filed report without leaving the previous value, the reason and the author
  behind it. Refusing corrections outright was considered and rejected — it does not stop
  a misspelled name being fixed, it just moves the fix to a phone call that records
  nothing.

### The daily report reminder

**All of it is the bank's. None of it is the agent's.** That is a change: the app used to
carry a reminder switch and a "nudge me 30 minutes early" picker, and both are gone. A
reminder that the person being measured against the deadline can move or silence is not
doing the job it exists for, and the panel is responsive, so an operator can set it from
their own phone anyway.

**Where it is set: Settings → Notifications, in the panel.** Nothing is set on the phone.
Four settings, all arriving on the phone through `/meta` and cached so the alarm still
fires with no network:

| Setting | Default | What it does |
|---|---|---|
| `daily_report_reminder_enabled` | On | The master switch, as a toggle |
| `daily_report_due_time` | 17:00 | When the first nudge fires. A dropdown of half-hour steps, 15:00 to 20:00 |
| `daily_report_reminder_repeat_minutes` | 15 | How often it comes back until the report is in. 0 means one reminder and no repeating |
| `daily_report_reminder_until_hour` | 22 | When repeats stop for the night |

**It keeps coming until the report is filed.** One notification at the deadline is one
swipe away from being nobody's problem until tomorrow, so it re-fires on the bank's
interval and the notification does not vanish when brushed aside. It stops the instant a
visit or the day's SSS figures are filed — the way to make it go away is to do the thing it
is asking for. Every value is clamped on the way out of the server (a repeat of 1 minute
becomes 5, an hour of 99 becomes 23) because the settings screen has no per-type validation
and these drive an alarm on somebody's phone.

**And it stops overnight.** "Until it is submitted" cannot literally mean all night: an
alarm at 2 am does not get a report submitted, it gets the app's notifications switched off
entirely and takes the working reminders with it. Repeats stop at the cutoff and resume at
the deadline on the next working day, so an unfiled report is not forgotten either.

It is a **local alarm, not a push**. Firebase is not in the APK at all, so a
server-driven push could not reach the phone; and for agents on patchy rural
connections a device alarm is the only thing that fires reliably. That decision brings
the obligations with it, all of them enforced in code:

- **Inexact, in a ten-minute window.** `SCHEDULE_EXACT_ALARM` / `USE_EXACT_ALARM` are
  restricted by Google to alarm clocks and calendar events. A deadline nudge is not
  one, and an inexact alarm also survives Doze — which an idle phone in a village will
  be in.
- **Re-armed after a reboot and after an app update.** Android drops every alarm on
  both. Without a boot receiver the reminder works until the phone runs flat once and
  then never fires again, while the app still shows it switched on.
- **Each firing books the next one**, before any early return. A repeating alarm could
  not skip Sundays or pick up a changed deadline; and rescheduling *after* the
  notification would mean the one day an agent had already submitted was the day the
  chain silently ended.
- **Never on a Sunday**, matching the rest of the system — nothing is assessed on one.
- **Silent once the work is done.** Filing a visit or saving enrolment figures marks
  the day, and the alarm then stays quiet. Nagging somebody who has already filed is
  how a reminder becomes noise, and noise gets silenced.
- **The permission is asked for.** On Android 13+ a notification the agent never
  allowed is dropped silently, so the reminder would look on and simply never arrive.

### Addresses are derived, never stored as the record

Coordinates are what a visit and a location point carry. An address is resolved
later, cached, and shown as a label — using **OpenStreetMap's Nominatim**, which is
free and needs no key.

That ordering is the whole design. A free service will sometimes name the wrong
village in rural India, or nothing at all, and a name frozen into the report would
become indistinguishable from something the agent asserted. So `geocode_cache` sits
beside the record rather than inside it, and the report keeps the coordinate.

The provider's terms then shape the rest: one request per second, no bulk
reverse-geocoding, and a User-Agent identifying who is calling. Lookups therefore
run only from `cron/geocode-backfill.php`, throttle themselves, cache on a ~11 m
grid so a day outside one house is one lookup, remember failures so a nameless
coordinate is not retried forever, and **stay switched off until an operator
supplies a contact email** rather than sending anonymous traffic from a shared host.
No page render ever waits on it; unresolved coordinates are simply shown as
coordinates.

### Location recording — this changed

Earlier releases captured **no** location at all, and this section said so. The
operator has since decided to record it, so the honest statement is now:

- ✅ **Location IS recorded** — an agent's position during a duty session, and the
  position at the moment a visit photo is taken.
- ✅ **The agent does not start it.** A duty session begins on its own when a signed-in
  agent opens the app and the permission is held; there is no Start button and no switch
  left in the off position. What the bank measures is not the measured person's setting,
  for the same reason the daily reminder time is not. The ongoing notification Android
  requires is still there, and its **Stop** ends the session that is running — it is not a
  setting, so recording resumes the next time the app is opened.
- ✅ **Consent is the operating system's permission dialog, and nothing else asks twice.**
  This also changed. There used to be a second, in-app consent notice with its own on/off
  control, and it could disagree with the OS setting — an agent could grant the permission
  and still have every coordinate refused by the server, with nothing anywhere explaining
  why their visits carried no location. Granting the permission is now the whole of it.
- ✅ **It is still recorded server-side, versioned, for the audit trail.** The app posts
  the consent once, with the notice version the server is currently serving, so the record
  says which notice applied, when, and from which device. `TrackingService::record()` still
  throws without it — the gate did not move, only the second question the agent was asked.
- ✅ **Withdrawal is the OS permission.** Revoking it in Android settings stops collection,
  which is the one place an agent will think to look and the one that cannot disagree with
  itself.
- ✅ **It expires.** Points are deleted after the retention window (90 days by
  default) by `cron/purge-location-logs.php`. A permanent record of somebody's
  movements is a liability that grows.
- ✅ **There is a map, and it is OpenStreetMap.** *Location Trail* in the sidebar draws an
  agent's day as a line, with the visit reports they filed that day on the same map in a
  different colour — because the question worth asking is not "where did they go" but "was
  the report filed where the visit happened". Leaflet (BSD-2) against OSM's own tiles: **no
  API key, no Google account, no billing**, SRI-pinned and checked against the real bytes by
  `tools/verify-cdn-integrity.php`. This screen was missing for a long time, and its absence
  made the whole feature dishonest: points were collected every four minutes and purged
  after ninety days, and in between nobody could look at any of them.
- ✅ **An empty day says what it means.** "Nothing was recorded" means the app was not open
  with permission granted, *not* that the agent did no work, and the page says so in those
  words. The distance is the sum of the legs, under-reports whenever the phone had no
  signal, drops any jump over 20 km as a bad fix, prints how many it dropped, and states
  that it is not a timesheet.
- ✅ **Reading somebody else's trail is audited**, like any other access to
  sensitive personal data. An agent reading their own is not — and they can read their own,
  which is the least a system that records somebody owes them.
- ✅ **No Firebase anywhere in this.** Tracking is the phone's own `LocationManager` posting
  to this server; the daily alarm is a local `AlarmManager` alarm that works with no
  network; alerts are rows in `notifications`. Firebase is optional and buys exactly one
  thing — an alert landing on a locked phone in seconds rather than at next app open. See
  DEPLOYMENT.md §6.5.
- ✅ **Off duty is off.** Points carry an `on_duty` flag and the app stops sending
  when a duty session ends — on sign-out, on **Stop**, or when the process dies. Nothing
  is collected in the background: `ACCESS_BACKGROUND_LOCATION` is never requested, so
  there is no collection without the notification that says it is happening.

If you are deploying this, that last set of bullets is not decoration — a system
that tracks staff without notice, without a way out and without an expiry date is
a different and much harder thing to defend.

---

## Repository layout

```
schema.sql                  35-table MySQL schema + seed roles, permissions, admin user
DEPLOYMENT.md               step-by-step cPanel / shared-hosting deployment guide

admin/                      web root for the panel and the API
  index.php                 single front controller
  .htaccess                 pretty URLs, security headers, PHP hardening
  config/
    config.sample.php       copy to config.php and fill in (config.php is git-ignored)
  app/
    bootstrap.php           autoloader + error handler + container wiring
    Core/                   25 framework classes, all hand-written, zero dependencies
    Models/                 11 data models (Branch, User, Customer, LoanAccount, CustomField, …)
    Services/               10 services (Import, Visit, Assignment, Report, Dashboard, Backup, …)
    Controllers/Admin/      20 panel controllers + shared base
    Controllers/Api/        11 API controllers + shared base
    routes/web.php          panel routes
    routes/api.php          /api/v1 routes
  views/                    43 server-rendered view files
  assets/                   app.css + app.js (Bootstrap itself comes from CDN)
  uploads/                  photos, approvals, documents — denied to the web
  storage/                  logs, backups, import files, tmp
  cron/
    backup.php              nightly database backup + retention
    reminders.php           promise due/overdue notifications

android/                    Gradle project (AGP 8.13, Kotlin 2.2, Gradle 8.14, JDK 17)
  app/src/main/java/com/lrms/recovery/
    data/                   Retrofit service, DTOs, repository, encrypted session store
    ui/                     10 screens (splash, login, main + 4 tabs, visit, photos, …)
    util/                   formatters, image compression / file store

tools/                      verification harnesses (see Verification below)
.github/workflows/          build-android.yml, verify-backend.yml
```

The `admin/` directory is the only thing that needs to be web-accessible. Everything
below `admin/app/`, `admin/config/`, `admin/views/`, `admin/storage/` and
`admin/uploads/` is additionally protected by its own `.htaccess`, so the layout is
safe even if you cannot configure a document root (the usual shared-hosting case).

---

## Quick start

Full instructions live in **[DEPLOYMENT.md](DEPLOYMENT.md)**. The short version:

```bash
# 1. Database
mysql -u root -p -e "CREATE DATABASE lrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p lrms < schema.sql

# 2. Configuration
cp admin/config/config.sample.php admin/config/config.php
#    edit: db credentials, app_url, and generate the two secrets:
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # -> encryption_key
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # -> jwt_secret

# 3. Upload admin/ to public_html (or point a vhost at it) and open it in a browser.
```

Sign in with **either an employee code or an email address** — office staff know
their address, field agents know their code. Email is unique in the schema because
it is a login identifier; if a restored database somehow holds two accounts on one
address, sign-in is refused rather than guessing which person it meant.

Password resets send a 6-digit code **by email when SMTP is configured**, falling
back to SMS. Email is preferred: it costs nothing and is not silently dropped by a
DND registry the way transactional SMS can be. Only a SHA-256 hash of the code is
stored, requesting a new code retires the previous one, and the address shown back
to the user is masked (`ra****@example.com`).

First login is seeded by the schema:

| Employee ID | Password | Role |
|---|---|---|
| `ADMIN001` | `Admin@123` | Super Admin |

The account is flagged `must_change_password`, so the panel forces a new password
before anything else. **Change it immediately and delete the demo users** if you seeded
them (`php tools/seed-demo.php`).

Local development without Apache:

```bash
php -S 127.0.0.1:8080 -t admin tools/router-dev.php
```

---

## Roles and permissions

| Role | Scope |
|---|---|
| **Super Admin** | Everything, all branches, role editing, backups, settings |
| **Branch Manager** | Own branch: leads, agents, assignment, promises, reports |
| **Agent** | Own assigned leads only: view, visit, upload, promise history, and correcting their own borrowers |
| **Auditor** | Read-only: reports, audit and activity logs. No edits, no PII |

45 permissions across the four seeded roles, including the BC performance group.

`visits.approve` and `visits.revise` are held by branch managers and super admins, not
agents: an agent approving their own report, or correcting the name on it after filing,
would make both the approval and the revision log worthless.

**Agents have a narrow panel surface**, which they did not have before. They were refused
the web panel outright, and the effect was that an agent who noticed a wrong father's name
or a dead phone number at a doorstep either got it fixed by ringing somebody at the branch,
or it stayed wrong. They can now sign in and correct **their own** borrowers, and add
custom fields to collect what they keep being asked for. Everything else refuses them: no
dashboard, no visit screens, no reports, no import, no other agent's borrowers.

Three things make that boundary hold rather than depend on remembering it:

- The panel gate is **fail-closed per route**. `Auth::requirePanel($request, $allowAgent)`
  defaults to `false`, so a new screen is shut to agents until somebody decides otherwise.
  Forgetting the flag hides a page; forgetting to *add* a check would expose one.
- **Branch scope is not enough**, because a branch holds several agents. Opening or editing
  a single lead runs `assertOwnLead()`, which requires it to be assigned to the caller.
- **The list query is pinned before the request is read.** `agentFilter()` returns the
  caller's own id and never consults `?agent_id=`, so the parameter cannot widen it. There
  is a smoke assertion for each of five query strings that the set of visible leads is a
  *subset* of their own — narrowing is fine, adding is not.

The navigation mirrors those flags rather than the permission list, because they are
different things: an agent holds `visits.view` so the Android app can list their visits,
while the panel's visit screens are not scoped to one agent and stay shut. A link to a page
that refuses you is worse than no link.

Permissions are rows in `permissions`, joined to roles through `role_permissions`, so
the matrix is editable in the panel rather than hard-coded. Two decisions worth knowing:

- Agents **do** hold `dashboard.view` and `customers.view_pii` — they need their own
  daily summary, and they need a real mobile number to call the borrower.
- Agents **do not** hold `promises.update`. Marking a promise kept or broken is a
  branch decision, and there is an integration test that asserts an agent is refused.

Branch scoping is enforced in the query layer, not in the view, so a manager cannot
reach another branch's records by editing an ID in the URL.

---

## Reports

| Report | Content |
|---|---|
| Daily | Visits and outcomes for a chosen date |
| Weekly | Visit and promise summary for a chosen week |
| Monthly | Full-month performance per agent |
| Branch-wise | Leads, visits, recovery progress per branch |
| Village-wise | Coverage and outstanding amounts grouped by village |
| Loan-type-wise | Portfolio split by loan type |
| Agent performance | Visits, promises made, promises kept, conversion |
| Promise tracking | Due, overdue, kept and broken promises |
| **BC Daily** | Per-agent visits, contacts, PTP, **APY / PMJJBY / PMSBY / PMJDY**, OD-2 renewals and recovery for one date, against that day's target |
| **KCC Renewal Worklist** | Kisan Credit Card accounts by renewal due date, soonest first |
| **OD-2 Renewal Worklist** | OD-2 accounts by renewal due date, soonest first |

The two renewal worklists are separate rows on purpose. Both facilities renew against the
same date column and used to be reviewed as one list, which buried forty OD-2 renewals
inside three hundred KCC ones — they are not one queue, the volumes differ by an order of
magnitude and the paperwork differs. Which facility an account is gets read off the loan
type the bank's own file already carries, and is correctable by hand where a product name
is not a facility name. A bare "OD" or "Overdraft" is deliberately left undetermined rather
than guessed into a list somebody works down by hand.

They are worklists rather than summaries — one row per account, because nobody renews an
average. Accounts with no renewal date recorded are included and sorted last: those are the
ones nobody is tracking, and dropping them would make the list look complete when it is not.

The BC Daily report is worth one note. Visits are **counted, never entered** — the
number is `COUNT(visit_reports)` for that agent and date, so there is no field
anywhere in the system for typing it and nothing to inflate or fall behind. The four
scheme figures are the only numbers a human enters, once per agent per day under a
unique key, either by the agent in the app or by a manager in the panel. Agents who
filed nothing are still listed: "who reported nothing today" is the most useful line
on the report, and dropping those rows would hide exactly the people it should show.

Every report supports the same filters (date range, branch, agent, village, loan type,
status) and exports to **PDF** and **Excel**. Both writers are in `admin/app/Core/`
(`Pdf.php` emits real PDF objects with an embedded font; `Xlsx.php` writes the OOXML
zip by hand), because Composer is not available on the target hosting.

### The printed visit report

`Pdf.php` embeds real images, written from scratch for the same reason — no Composer,
so no PDF library. A baseline RGB or greyscale JPEG passes straight through as
`/DCTDecode`; PNG, GIF and BMP are decoded with GD, **flattened onto white**, and
deflated as raw RGB; WebP, CMYK JPEG and **progressive** JPEG are re-encoded to baseline
JPEG. Two details are not optional. Flattening is mandatory because transparent pixels
read as black in RGB, so an unflattened logo prints as a solid black rectangle.
Progressive JPEGs are detected by scanning the markers rather than trusted, because
`/DCTDecode` renders one as a grey box — a report that looks fine until someone opens it.

The printed report carries:

- the borrower and loan details as filed, with the **closure amount** (the figure needed
  to close the account, distinct from an OTS amount, which is a settlement the branch
  agrees to accept for less),
- **Location recorded** — the visit's coordinates, accuracy and how they were obtained,
  distinguishing "the device had no fix" from "the agent declined location",
- **Field photographs**, each captioned with its own coordinates and whether it came from
  the camera or the gallery,
- **KRM / OTS Settlement** or **CKCC OD-2 Renewal** when the report is one of those types —
  the settlement arithmetic with the percentages each figure came from, the deposit with the
  bank's own receipt reference, and the validity window; or the renewal deadline with the
  expected NPA date if it is missed, the account snapshot, and every tick list printed as
  the items that were actually ticked. These were missing from the printout for a long time:
  the screen had them, so a settlement or a renewal printed as though it were a plain
  recovery visit and dropped the very thing the visit existed to collect,
- **Signatures** — the **agent's own photograph** from the visit, captioned with where it
  was taken, and directly beneath it **empty ruled boxes** for the borrower's and the
  agent's signature, each with a name and date line: they are signed by hand on the
  printed copy,
- **Approval** — status, approver, their position, remarks and photograph, above **their
  own blank signature box**, which prints whether or not the report has been reviewed yet:
  the copy somebody prints in order to sign it is precisely the one still pending,
- any custom fields marked to print,
- and, in the footer, the number of times the report was corrected after filing.

---

## REST API

Base path `/api/v1`. Bearer JWT on everything except login/refresh/forgot/reset/ping.

```
POST   /auth/login                 POST   /auth/refresh          POST /auth/logout
POST   /auth/forgot-password       POST   /auth/reset-password
POST   /auth/change-password       GET    /auth/me               POST /auth/device-token

GET    /ping                       GET    /meta                  GET  /dashboard

GET    /leads                      GET    /leads/search
POST   /leads/assign               POST   /leads/reassign        POST /leads/transfer
POST   /leads/status
GET    /customers/{id}             GET    /customers/{id}/history

GET    /visits                     POST   /visits   (multipart, idempotent)
GET    /visits/form-options        GET    /visits/{id}

GET    /promises                   POST   /promises/{id}/settle

GET    /notifications              GET    /notifications/unread-count
POST   /notifications/read-all     POST   /notifications/{id}/read
POST   /notifications/send

GET    /import                     POST   /import/upload        POST /import/preview
GET    /import/{id}/errors

GET    /reports                    GET    /reports/{type}       GET  /reports/{type}/export

GET    /media?f=…                  authenticated, branch-scoped file streaming
```

Responses are uniform: `{"success":true,"data":…}` or
`{"success":false,"message":…,"errors":{…}}` with a correct HTTP status.
Access tokens are short-lived; refresh tokens are **rotated on every use** and only
their SHA-256 hash is stored, so a stolen refresh token dies the moment the real
device refreshes.

---

## Android app

Ten screens, Material 3, no Compose (Views + ViewBinding for build reliability):

Splash · Login · Forgot password · Change password · Main (bottom nav: **Leads**,
**Search**, **Notifications**, **Account**) · Customer profile (timeline, promises,
media) · Visit report · Photo upload · Visit history · Visit detail.

Notable choices:

- **There is no signature pad.** There was one — landscape-only and full-screen, because a
  narrow portrait box produced marks nobody could recognise. It is gone: even at full
  width, a fingertip signature is not one anybody accepts across a counter. The printed
  report carries empty ruled boxes and the paper is signed after printing.
- **Photos are downscaled and re-compressed on device** before upload — rural
  uplinks cannot carry a 12 MP JPEG.
- **Session tokens live in `EncryptedSharedPreferences`.**
- **No `google-services.json` is committed** and the Firebase Gradle plugin is not
  applied, so the project builds in CI out of the box. In-app notifications are the
  source of truth; push is opt-in and configured server-side.

The API host is **compiled into the APK** (`https://my.controversy.blog/api/v1/`)
and is not editable in the app. An app holding borrower PII that will talk to any
host someone types into it is a phishing target. A debug build sets
`ALLOW_CUSTOM_SERVER = true` so it can be aimed at a laptop; the release build has
no code path that reads a typed address.

Build it yourself:

```bash
cd android
echo "sdk.dir=$ANDROID_SDK_ROOT" > local.properties
./gradlew :app:assembleDebug          # JDK 17 required
```

Or download the APK from the **Build Android APK** GitHub Actions run — see
[DEPLOYMENT.md §6](DEPLOYMENT.md#6-android-app) for the server URL and signing setup.

---

## Security

- **Passwords**: bcrypt cost 12, forced rotation flag, throttled login attempts.
- **PII at rest**: mobile, Aadhaar and address are encrypted with **AES-256-GCM**
  and stored base64-encoded in `VARBINARY` columns, with a separate HMAC column for
  exact-match lookup without decryption. Viewing PII requires `customers.view_pii`
  and is written to the audit log.
- **Uploads are never web-served.** `admin/uploads/.htaccess` denies everything;
  files are streamed through `GET /media?f=` (panel) and `/api/v1/media` (app) after
  authentication, branch-scope and path-containment checks. Guessing a filename
  gets you a 403, not an Aadhaar photo.
- **CSRF tokens** on every panel POST; API is token-authenticated and stateless.
- **Prepared statements everywhere** — no string-concatenated SQL.
- **Output escaping** by default in the view layer.
- **Security headers** and PHP hardening (`expose_php`, `allow_url_fopen`,
  `display_errors`) applied from `.htaccess`.
- App traffic is **HTTPS-only** — cleartext is disabled in the network security config,
  with an exception for `localhost` / `127.0.0.1` / `10.0.2.2` so a developer can point
  the app at a local PHP server without weakening the policy for real deployments.

The full pre-launch checklist is [DEPLOYMENT.md §8](DEPLOYMENT.md#8-security-checklist).

---

## Verification

Nothing here is hand-waved. The `tools/` harnesses run against a **real MySQL 8 server
and a real PHP HTTP server**, and the Android build runs a real Gradle assemble.

| Harness | Command | Checks |
|---|---|---|
| Syntax | `find admin tools -name '*.php' -print0 \| xargs -0 -n1 php -l` | 143 files (a file count, not assertions) |
| Core unit tests | `php tools/selftest-core.php` | **249** — includes column detection against real bank-export shapes, the PDF image encoder, the blank signature boxes the printout carries, multi-line captions, and how a recorded position is worded |
| Schema | `sh tools/verify-schema.sh` | **28** — 34 tables, 54 FKs, seeds, bcrypt login hash, and every dropdown setting checked for choices it can actually offer (including a deliberately broken row, to prove the check fails) |
| **Upgrade SQL** | `sh tools/verify-upgrade-sql.sh` | **18** — all six release migrations in `DEPLOYMENT.md` are extracted from the document and run as a chain on a *populated* pre-release database, then the result is compared against `schema.sql` column by column, index by index, FK delete rule by FK delete rule, setting by setting — including what kind of control each setting renders as and the choices it offers — and grant by grant |
| Integration | `sh tools/integration-test.sh` | **780** — includes the customer sheet PDF, warning escalation, the tracking consent gate, the geocode cache, dense ranking, live same-day figures, visit-counter repair, hand-corrected figures surviving the next import, report corrections replayed back to the filed original, user-added fields, the agent's own geo-tagged photograph, every banking column a recovery statement carries, leads spread evenly across a branch, a lead typed in by hand which the next import then owns, and a second phone number that no import can flatten |
| Cron jobs | `sh tools/verify-cron.sh` | **52** — backup restores; every job is idempotent, and the CLI-only guard is checked for every file in `cron/` rather than a list kept in the test |
| Panel smoke | `sh tools/smoke-panel.sh` | **417** panel + **228** API — includes an audit of **every `<select>` on every page**: none empty, none with two options selected, every filter dropdown holding the value it was given |
| Android | `sh tools/verify-android.sh` | **227** unit tests + both APKs + adaptive-icon safe zone |
| Icon geometry | `python3 tools/check-icon-safezone.py` | every path point survives a circular launcher mask |
| Brand assets | `python3 tools/prepare-brand-assets.py` | regenerates the shipped lockup and monogram from `docs/brand/` |
| Brand previews | `python3 tools/render-brand-preview.py` | composites the real shipped artwork into `docs/previews/` for review |
| **App/API contract** | `:app:testDebugUnitTest` (`ApiContractTest`) | **20** — real server JSON through the real DTOs (a subset of the Android row) |
| **Tracking promises** | `:app:testDebugUnitTest` (`LocationTrackingTest`) | **16** — the OS permission is the whole consent, recording starts without the agent, consent is posted before the first point, foreground-only, no background permission (a subset of the Android row) |
| **Photo geo-stamping** | `:app:testDebugUnitTest` (`PhotoGeoStampTest`) | **15** — a camera capture may carry coordinates, a gallery pick may not, the capture source and time are sent rather than guessed, and the agent's own photograph is camera-only (a subset of the Android row) |
| **SSS entry** | `:app:testDebugUnitTest` (`SssEntryTest`) | **11** — four typed schemes, and no field anywhere for typing a visit count (a subset of the Android row) |
| **Reminder arithmetic** | `:app:testDebugUnitTest` (`ReportReminderPlanTest`) | **24** — real behavioural tests: never in the past, never on a Sunday, and a repeat that keeps firing until the report is filed but stops at the cutoff hour (a subset of the Android row) |
| **Reminder wiring** | `:app:testDebugUnitTest` (`ReportReminderWiringTest`) | **16** — survives reboot, cannot stop rescheduling, no exact-alarm permission, and filing the report is the only thing that clears it (a subset of the Android row) |
| **Tab switching** | `:app:testDebugUnitTest` (`TabSwitchingTest`) | **9** — one source of truth for which tab is on screen; verified to fail against the old code (a subset of the Android row) |
| Release signing | `sh tools/verify-signing.sh` | **21** — signs, verifies, proves the unsigned fallback, and proves the debug APK comes from the committed keystore so a new build installs as an update rather than demanding an uninstall |
| **Real Apache** | `sh tools/verify-apache.sh` | **27** — `.htaccess` under `AllowOverride All` + php-fpm |
| Cross-validation | `php tools/crossvalidate.php .verify && python3 tools/crossvalidate.py .verify` | exported PDF/XLSX re-parsed independently |
| CDN integrity | `php tools/verify-cdn-integrity.php` | **7** — every SRI hash in every view, not just the layouts, matches the file the browser fetches |
| **Key setup** | `sh tools/verify-setup-keys.sh` | **38** — `setup-keys.php` fills blanks, never overwrites a live key, never mangles a config |
| **Install diagnostic** | `sh tools/verify-hosting-diag.sh` | **25** — no false alarms, no leaked secrets |

**2,117 assertions total** — the sum of the bold counts above, counting the seven
subset rows only once and excluding the syntax row, which counts files. Release APK
is 2.9 MB after R8; debug APK is 8.0 MB (measured with `du --apparent-size` — a
signed, zipaligned APK is block-padded on disk, so plain `du -h` overstates it).

Exported files are cross-validated by a *second, independent* implementation: the
generated `.xlsx` and `.pdf` are re-read in Python (`openpyxl` / `pypdf`) and the
numbers compared against what PHP thought it wrote — otherwise a hand-rolled writer
can only ever agree with itself.

`sh tools/debug-request.sh /some/path` dumps the raw response plus the error log for a
single URL, which is the fastest way to diagnose a 500 on a live host.

### Bugs these harnesses actually caught

Kept here because they are the reason the tests exist:

1. **Excel import shifted every column** on real bank exports, because the reader took
   the first non-empty row as the header and bank files start with a merged title row.
   Fixed with header detection (first row with ≥2 filled cells inside a 15-row window).
2. **`visit_date` was written in IST but compared against MySQL `CURDATE()` in UTC**, so
   "visits today" silently broke every day between 18:30 and 24:00 UTC. Fixed by
   pinning the session time zone to a numeric offset (shared hosts often lack the
   MySQL time-zone tables, so named zones are not safe).
3. A `SELECT` built by appending a column *after* the `JOIN` clauses parsed as a table
   reference and failed with `Unknown database 'c'`.
4. `extract(EXTR_SKIP)` in the view renderer refused to overwrite a local named
   `$data`, so a view variable called `data` silently received the whole merged array
   and the dashboard 500'd. All renderer locals are now `__lrms`-prefixed.
5. `const SELECT` omitted `closed_at` / `last_promise_id`, so `find()` always reported
   them as null.
6. PHP 8.4 deprecations in `fgetcsv` / `fputcsv` (`$escape` must now be explicit).
7. A Kotlin expression-body function containing `return` — a hard compile error.
8. **The CI release-signing step did not exist**, despite this README once claiming
   it did. `tools/verify-signing.sh` was written to test the claim, which is how the
   gap surfaced; the step now exists and is verified end to end.
9. **A `keytool` keystore with different store and key passwords cannot work.**
   Since Java 9 keytool defaults to PKCS12, which encrypts the key with the *store*
   password and silently ignores `-keypass`. Gradle then fails with
   `Given final block not properly padded`, which never mentions passwords. The
   workflow now rejects that combination up front with an explanation, and no
   `keytool` probe can detect it — proven in the test, which is why the check
   compares the two values instead.
10. **`du -h` overstated the APK size by 2.5×.** A signed, zipaligned APK is
    block-padded on disk, so the build summary would have reported a 2.7 MB app as
    6.7 MB. Size reporting now uses `--apparent-size`.
11. **`mysqldump` wrote its stderr into the `.sql` file.** The dump command ended
    in `2>&1`, so any warning on an otherwise successful run was pasted into the
    backup and the restore would die with a syntax error on line 1. stderr now goes
    to a separate file, and a dump is rejected (falling back to the PHP dumper)
    unless it really contains `CREATE TABLE` and disables foreign key checks.
12. **The backup test only ever exercised one of two code paths** — it passed
    locally, where `mysqldump` was not installed, and failed in CI, where it is,
    because the assertion was written against the PHP dumper's exact spelling
    `FOREIGN_KEY_CHECKS = 0` while mysqldump emits `/*!40014 … FOREIGN_KEY_CHECKS=0 */`.
    Both paths now run on every invocation and the assertions check the invariant
    rather than one method's syntax.
13. **Two backups taken in the same second overwrote each other** — the filename
    only had second resolution, so double-clicking *Backup now* silently destroyed
    the first file. Colliding names now get a numeric suffix.
14. **`.htaccess` had never been executed even once.** Every harness used PHP's
    built-in server, which ignores `.htaccess` entirely. Running the package under
    a real Apache with `AllowOverride All` immediately found the next item.
15. **The whole REST API would have returned 401 in production.** Apache reserves
    the `Authorization` header for its own authentication and does **not** forward
    it to a FastCGI/CGI/LSAPI backend — which is how PHP runs on practically every
    cPanel host. The `.htaccess` did carry a rewrite rule for it, but the rule sat
    *after* `RewriteRule ^ index.php [L]`, and `[L]` ends processing, so for every
    API request it never ran. The admin panel would have worked perfectly while
    the Android app failed to authenticate against everything. Now fixed with two
    independent mechanisms and asserted end to end.
16. **`schema.sql` destroys data and nothing said so.** Every table begins with
    `DROP TABLE IF EXISTS`, so re-importing it deletes every customer, visit and
    promise. "Re-import the schema to be safe" is a natural thing to try during an
    upgrade. Now warned about in the file header, `DEPLOYMENT.md` §2.2 and §10, and
    the hosting README — and a test inserts a canary row and asserts the re-import
    removes it, so the behaviour is pinned rather than assumed.
17. **`schema.sql` never re-enabled `FOREIGN_KEY_CHECKS`.** It turns them off to
    allow any table order but never restored them, so the rest of that session — a
    phpMyAdmin tab, an operator's shell — could write rows that break referential
    integrity without complaint.
18. **`verify-schema.sh` was broken while being reported as passing.** It used
    `mysqladmin ping` for readiness, which succeeds against MySQL's temporary init
    server, so it imported too early and failed with `Access denied`. The same bug
    had been fixed in three other scripts; this one was missed.
19. **That script also died silently mid-run.** Its query helper swallowed stderr,
    so under `set -e` one bad query (`permissions.slug`; the column is
    `permissions.code`) aborted the run with no message and no summary, hiding a
    third of the checks.
20. **The Auditor role shipped entirely undocumented** — `schema.sql` seeds four
    roles, the README described three.
21. **The cron scripts had never been executed by any test.** They are the only
    entry points that are neither a web route nor covered by the integration
    suite. Both turned out clean, and are now covered by 20 checks — including
    that the nightly dump actually restores into a fresh database.
22. **A smoke check passed against its own HTML comment.** The new panel test
    looked for the section title "KRM / OTS settlement" to find a settlement
    report — and that string also sits in the `<!-- ... -->` comment above the
    block, on *every* visit page. So it matched a plain recovery report and then
    failed all thirteen of its own sub-checks, pointing at a bug that did not
    exist. Detection now matches on content only the rendered card contains.
23. **The Call button never appeared in the app's lead list.** `LeadAdapter`
    enables it from `lead.mobile`, but the list endpoint only ever sent
    `mobile_masked` — the decrypted number was attached on the *profile* endpoint
    only. So an agent looking at the day's leads could not phone anybody without
    opening each lead first. Gson made this invisible: a missing key just leaves
    the field null, so there was no error anywhere. The list now attaches the
    decrypted mobile in one extra query per page for callers holding
    `customers.view_pii`; Aadhaar is deliberately still withheld from lists.
24. **A wrong Subresource Integrity hash silently disabled all of Bootstrap CSS.**
    The declared hash for `bootstrap.min.css` matched the real file for its first
    fourteen characters and then diverged, in **all three layouts** — so the login
    page too. A browser refuses a file whose hash does not match, so none of
    Bootstrap's CSS was ever applied on the live site. The symptom reported was
    "the profile menu never closes", which is exactly right: the rule that hides a
    dropdown is Bootstrap's. Nothing server-side could see it — the HTML is
    correct and all 130 panel checks pass — so `tools/verify-cdn-integrity.php`
    now fetches each file and compares, and CI runs it.
25. **Blank crypto keys failed late, as an unattributable 500.** A live install had
    `config.php` present but `data_key` and `hash_pepper` empty. The app booted,
    pages rendered and sign-in worked, yet creating a user with a mobile number
    failed and an app login 500'd — but only when the identifier contained a
    digit, because only then does it fall through to the mobile-hash lookup and
    reach the crypto. Bootstrap now validates the keys at startup and names the
    ones at fault instead of letting an unrelated action explode weeks later.
26. **The Android client had never deserialised a single real server response.**
    All 69 existing tests worked on values the tests themselves built. The new
    `ApiContractTest` parses 19 fixtures captured from a live server through the
    real DTOs, which is what caught #22.
27. **The diagnostic invented a problem.** `diag.php` demanded a world-readable
    document root, so on the live cPanel/LiteSpeed host it reported the `0750`
    root as the cause of a 403 — on a site that was serving traffic perfectly.
    There, PHP and the static handler both run as the account owner, which makes
    `0750` sufficient and in fact tighter than the `0755` it was recommending. A
    tool whose whole job is diagnosis has to be trusted, so the check now
    resolves who actually serves the request; `verify-hosting-diag.sh` pins both
    the suexec and the mod_php verdicts.
28. **The app had no splash screen at all.** `Theme.LRMS.Splash` set a navy
    `windowBackground` and nothing else, which is not a launch screen: Android 7
    to 11 showed a blank colour flash and Android 12+ ignored it and drew its own
    default icon over the top. The brand never appeared on any version. Fixed by
    parenting the theme on `Theme.SplashScreen` from `androidx.core:core-splashscreen`
    and holding the splash across the session check, with the same navy behind it
    so a slow connection reads as loading rather than a hang.
29. **No launcher icon at all on Android 7.** `ic_launcher` existed only in
    `mipmap-anydpi-v26`, but `minSdk` is 24, so on 7.0 and 7.1 the resource
    resolved to nothing. `aapt2 dump resources` on the shipped APK confirmed a
    single `(anydpi-v26)` configuration. A `mipmap-anydpi/` fallback now composes
    the same two layers, and a test derives the requirement from `minSdk` so
    raising it later retires the check automatically.
30. **The icon artwork was cropped by the launcher mask, and nobody had ever
    looked at it.** An adaptive icon only guarantees a 66dp circle in the middle
    of its 108dp canvas; the mark put the top of the "2" at r=42 and the foot of a
    shield at r=39, against a limit of 33 — while the file's own comment claimed
    everything was inside the safe zone. Corners of a bounding box sit much
    further from the centre than their edges, which is what makes this easy to get
    wrong by eye and impossible to see without a device. `check-icon-safezone.py`
    now flattens the paths, applies `<group>` transforms, measures every point
    including Bezier control points, and fails the Android build. The overlapping
    shield was dropped rather than shrunk, and `render-brand-preview.py` renders
    the real drawables to PNG so the artwork can actually be reviewed.
31. **The API answered the mobile app with an HTML page.** Bootstrap's
    configuration check runs before the router exists and replied with a setup
    page regardless of who asked, so a misconfigured server sent the Android
    client a document it cannot parse. The agent saw "something went wrong" and
    the phone took the blame for a server fault — the exact complaint that led
    here. Bootstrap now detects an API caller and returns the normal
    `{success, data, message}` envelope naming the unusable keys and who can fix
    them, while a browser still gets the full page. The app also recognises an
    HTML body behind any failure code and says so.
32. **Every transport failure blamed the phone's internet.** `UnknownHostException`,
    a refused connection, an expired certificate and a timeout all produced
    "Check your internet connection". In the field the usual cause was a server
    that could not be resolved, on a phone whose network was fine, which sent
    agents hunting for signal instead of calling their administrator. Each case
    now reports what actually happened and names the host it tried;
    connectivity is consulted only to choose the wording, never to skip a
    request, because captive portals report themselves as connected.
33. **The app looked nothing like its own icon.** The UI carried a brighter blue
    borrowed from the web panel, so tapping a navy-and-gold launcher icon opened
    a plainly blue app, and a dozen strings still said LRMS after the product
    became D2 Recovery. `BrandingTest` now pins the name, requires the primary to
    be the same navy as the icon background, and computes WCAG contrast for the
    brand pairs — including an assertion that white on the gold measures about
    2:1, so nobody "tidies" `colorOnSecondary` back to white.
34. **The logo was the letters "LR" in a coloured box.** Four panel layouts and the
    app's login screen drew an initial from the old product name as *text*, which is
    why renaming the product did not reach it — there was no logo to replace, only a
    `TextView` and a `<span>`. The supplied artwork now ships as the single master in
    `docs/brand/`, with `prepare-brand-assets.py` deriving the full lockup and a
    monogram crop from it, so the app and the panel cannot drift apart. `BrandingTest`
    scans every layout for an initial drawn as text and fails if one comes back.
35. **The launch screen could be skipped entirely.** The system splash was held until
    the session check finished, so on a warm start with a cached session the activity
    behind it — the one carrying the brand — was created and navigated away from
    within a frame. The system splash is now released as soon as the lockup is drawn,
    and routing waits out a minimum instead, which is the only reason the artwork is
    reliably seen at all.
36. **The importer turned rupees into dates.** A date in a spreadsheet is a number
    wearing a date format, and the reader decided which was which by guessing from
    the value: "an integer in the Excel epoch window is a date". Every whole-rupee
    figure between 32,874 and 65,380 therefore became a date — and `parseAmount()`
    then read that date's *year* as the amount, so **a ₹45,000 outstanding balance
    was imported as ₹2,023**. That band is the bread and butter of BC/DC lending.
    Reproduced end to end before the fix. The reader now parses `xl/styles.xml` and
    converts only cells whose format actually says date, which also fixed dates
    outside the guessed band and dates carrying a time fraction. The file's own
    comment had claimed the band "avoids mangling ordinary amounts".
37. **The header row was found by shape, not by meaning.** "First row with two or
    more filled cells" survives a merged title row and nothing else: the
    `Branch: BR001 | As on: 31.03.2024` line that core-banking exports put under
    the title satisfies it perfectly, and then every column maps to the wrong
    field. Candidate rows are now scored on how many cells are recognisable column
    headings.
38. **Only the first worksheet was ever read.** Bank workbooks lead with a cover
    sheet or a summary pivot and put the accounts on sheet 2 or 3, which produced
    "missing required column(s)" against a file that plainly contained them —
    impossible to explain to the person holding the file. Every sheet is now
    scored and the best one used.
39. **Every row number in the error log was wrong.** They were computed as
    `index + 2`, which assumes the header is physically row 1; with a title block
    above it every number was off by however many rows were skipped. Those numbers
    are precisely what someone uses to find the bad row in Excel.
40. **A CSV dry run could never succeed.** `preview()` read `$_FILES['tmp_name']`,
    which is `/tmp/phpXXXXXX` with no extension, so the reader could not take its
    CSV branch and fell through to a ZIP-magic check. XLSX previews worked, so the
    fault looked like "CSV is not supported" when the same file imported fine.
41. **An unknown branch silently dropped the row.** Importing a new area meant
    typing every branch in by hand first, and one differently-spelled name meant
    rows vanished into the skipped count. Branches are now taken from the sheet and
    created when new, reported by name so a typo is visible — and only for an
    uploader who is not scoped to a single branch.
42. **`findWithPii()` did not select the columns it was asked for.** The four new
    settlement columns were written by the importer and read back as `null`
    everywhere, because the lead projection is spelled out by hand rather than
    `SELECT *` — so a new column is invisible until it is added in two places. The
    customer sheet is what exposed it: the settlement section rendered empty for a
    lead that plainly had figures.
43. **One value missing from an ENUM killed the entire nightly run.** `notifications.type`
    had no `target_warning`, so the first warning insert threw and took the rest of
    `bc-warning-check.php` with it — no warnings, no escalation emails, and the failure
    only visible in a cron log nobody reads. The cron harness now asserts each job is
    idempotent *and* completes.
44. **`audit_logs.action` had no `export`, so customer-sheet downloads were never
    audited** — for two commits. This one is worse than a crash: `Logger::audit()`
    deliberately swallows its own failures so a logging fault cannot break the action
    being logged, which means a missing ENUM value records nothing and says nothing.
    Downloading a borrower's full PII sheet was the exact operation that needed a
    trail. Both ENUMs now carry a comment saying to extend rather than reuse.
45. **The SSS reminder deduplicated on `DATE(created_at)` instead of the date in the
    payload.** A job that ran late — past midnight, or re-run after a failure — did not
    recognise its own earlier notification and sent it again; conversely a catch-up run
    for yesterday was suppressed by today's row. Matching now uses the `date` and `slot`
    carried inside the notification.
46. **Every BC target submission was silently rejected.** The form uses
    `<input type="month">`, which posts `2026-08`, while the validator's `date` rule
    insists on `YYYY-MM-DD`. So the page came back with a red field and the targets
    were never saved — and because the whole feature was new, this looked like "the
    form doesn't work" rather than a two-format mismatch. Caught by a smoke test that
    posts the form rather than only loading it. The parse now accepts both shapes and
    returns null for a non-month instead of defaulting to the current one, which would
    have written targets against a period nobody chose and then measured real agents
    against them.
47. **The cron CLI-only guard was checked against a hardcoded list of five files.**
    Two new cron scripts were added and neither was checked — the test passed while
    saying nothing about them. A cron reachable over HTTP hands an unauthenticated
    visitor a job that emails agents, purges data or dumps the database. The check now
    enumerates `cron/` so a script cannot be added without being covered.
48. **The BC scorecard was blank all day, every day.** It read
    `bc_daily_achievement`, which the 23:55 cron writes — so any range including
    today showed every agent on zero visits, zero contacts and a zero score. The
    screen was empty precisely while it was useful, and it did not read as "the data
    is not in yet", it read as "these agents have done nothing". Every metric is now
    derived from source records by one shared method; the nightly table went back to
    being what it always should have been, a historical snapshot for warnings.
49. **Agents were being warned for figures they had no way to report.** The four SSS
    scheme counts were enterable only in the admin panel, which an agent cannot open,
    while `cron/bc-warning-check.php` measured those same four metrics nightly and
    escalated a sustained shortfall to the supervisor, then the service provider, then
    the regional office. The software was generating written warnings about its own
    gap. There is now an API endpoint and an app screen; the endpoint is an upsert on
    (agent, date) so a retry on a dropped rural connection cannot double a figure that
    feeds a ranking, and backdating is limited to yesterday because a scored metric
    invites exactly that.
50. **Two hardcoded "8 report types" assertions.** Both the panel and API smoke tests
    checked a literal count and iterated a literal list, so a ninth report type would
    have shipped completely unexercised — no table, no Excel, no PDF. Both now read
    the types from what the server actually serves.
51. **Two tabs drawn on top of each other.** `MainActivity` kept its own
    `Map<Int, Fragment>` of the tabs it had created and hid things based on it. That map
    lives in the activity instance; the fragments do not. So anything that recreated the
    activity — a rotation, a system font-size change, **the dark-mode switch on the
    Account tab**, returning after the process was killed — emptied the map while the
    FragmentManager restored the tagged fragments it already had. The map then reported
    "nothing is showing", nothing was hidden, and a second copy was added on top of the
    restored one: two pages visible at once, one bleeding through the other, getting
    worse with every further tap because `hide()` was operating on an instance that was
    no longer on screen. Every lookup now goes through `findFragmentByTag`, so there is
    one source of truth and it is the one that survives recreation. `TabSwitchingTest`
    was checked against the old implementation first and fails four of its assertions
    there — a regression test that passes on the bug it describes is decoration.
52. **Every photograph claimed an unknown source.** The schema comment on
    `photos.capture_source` says a coordinate only means something when the photo came
    from the camera — and nothing ever wrote the column. Every photo read `unknown`, so
    the geo-tag printed on a report could not be distinguished from a gallery pick of a
    picture taken somewhere else on some other day. The app now sends the source
    explicitly per slot; it is never *inferred* from the presence of coordinates,
    because a camera photo taken inside a house has no fix and calling that a gallery
    pick is an accusation on a recovery file.
53. **The whole geo-tagging path shipped exercised by nothing.** `tools/seed-demo.php`
    never granted tracking consent, and `TrackingService` correctly gates every
    coordinate behind it — so all seeded visits stored `gps_source='denied'`, every
    per-photo fix was dropped, and the demo data proved the feature absent rather than
    present. The seed now consents per agent and files GPS, a camera-stamped photo, a
    gallery photo on every third visit.
54. **Bug 42 recurred, in the same shape.** `LoanAccount::findWithPii()` and
    `LoanAccount::SELECT` each spell their columns out by hand, so `closure_amount` and
    `manual_overrides` existed in the table, were written correctly, and were invisible
    to every screen until added to *both*. The comment naming bug 42 is now on both
    lists; two hand-maintained column lists for one table will keep doing this.
55. **A smoke test reassigned a manager's branch.** The new staff-photo block posted the
    user form with a hardcoded `branch_id=1`, which silently moved MGR001 to another
    branch and broke four unrelated API promise-settlement tests — the failures pointed
    nowhere near the cause. It now reads the current values off the form and resubmits
    them unchanged. A test that edits a record has to put back everything it did not
    come to change.
56. **"Nothing was skipped" on every single import.** The import declines to overwrite
    figures a human corrected in the panel, and reports which ones it skipped so a stale
    override cannot freeze a number forever. `$skippedOverrides` was never added to the
    `use` list of the closure that fills it, so each append auto-vivified a *local* array
    that died with the closure — PHP raised nothing at all. The guard worked; the report
    was always empty, which is the worse half to lose: a silent skip is indistinguishable
    from a silent clobber. Caught by asserting on the reported list rather than only on
    the surviving value, and the panel now prints the accounts and the field labels.
57. **Two harnesses passed 18.5 hours a day.** `tools/verify-cron.sh` built its fixtures
    through the `mysql` CLI, which — unlike the app, see `Database::alignTimezone()` —
    does not pin the session timezone, and both smoke harnesses ran in the container's
    UTC while the server runs `Asia/Kolkata`. So a promise inserted as "due today" landed
    on the server's yesterday, and `date('-1 day')` in the API smoke asked to backdate by
    two days, for the 5.5 hours after 18:30 UTC. Found by running the suite after
    midnight IST. The harnesses now keep the same calendar as the server they test.
58. **A readiness probe that answered from a server about to die.** Every docker-backed
    harness waited for MySQL by opening an *authenticated socket* connection — and the
    entrypoint runs a temporary initialisation server that authenticates on the socket
    and is then shut down and restarted. Winning that race left the schema import
    connecting to nothing, reported only as `!! schema import failed` with no hint why.
    The temporary server is started with networking disabled, so all six scripts now
    probe over TCP, which is the one signal it cannot produce.
61. **`photos.captured_at` was never written, for every photograph ever filed.** The
    column has existed since the first release carrying the comment "device clock at
    capture", and the caption builder has always tried to print it — so every row held
    `NULL`, a printed report stated where a photograph was taken but never when, and two
    photographs of the same door an hour apart were indistinguishable. The app now sends
    the capture time packed alongside the coordinates. It is recorded *independently* of
    the fix, because the two are independent: a camera photograph taken inside a house
    has no coordinates and a perfectly good capture time, and the first version of this
    fix threw the time away whenever the position was missing. It is never defaulted to
    "now" either — an upload can arrive hours later from a phone that was out of signal
    all afternoon, and writing the arrival time into a column labelled "captured at"
    turns a missing fact into a wrong one.
62. **The panel showed none of the evidence the printed report carried.** Every
    photograph on screen was a bare thumbnail with a type label, while the PDF of the
    same photograph printed its coordinates, its accuracy and whether it came from the
    camera. A camera capture and a gallery pick — the entire reason the column exists —
    were pixel-identical on screen. The screen is what somebody looks at before
    approving a report, so it was the weaker of the two documents. The wording had been
    private to `VisitController::pdf()`, which is *why* the panel showed nothing: it was
    not reusable. It now lives in `App\Core\Geo` and both renderings go through it, so
    they cannot disagree. The same card also claimed "Not captured" for an agent
    signature the PDF was printing from the agent's record.
63. **Twenty-two test assertions that crashed the JVM instead of failing.** These tests
    read the app's own source and assert on its shape, using
    `Regex("""marker(.|\n)*?other marker""")`. That pattern is catastrophic
    backtracking: on a *non-match* it explores an exponential number of paths and dies
    with `StackOverflowError` from inside `java.util.regex`. Which is exactly what
    happened the moment a guard clause moved into a helper function — the suite reported
    `StackOverflowError at Pattern.java:4962` with no indication of which behaviour had
    changed or where. All twenty-two now use `[\s\S]*?`, a character class, which cannot
    build those backtracking frames. A test that crashes is far worse than one that
    fails, because a failure names the thing that broke.
64. **A printed report showed an office portrait where a reader would assume a doorstep
    photograph.** The agent's photograph on a visit report was *always*
    `users.photo_path` — a portrait uploaded once in a branch office — printed next to
    the borrower's signature under the label "BC / DC Agent" with no indication of when
    or where it was taken. Nothing was technically false, and that is the problem: on a
    document that geo-captions every other photograph, an uncaptioned photograph of the
    agent reads as one more piece of field evidence. There is now a camera-only slot for
    the agent's own photograph taken at the door, captioned with its own fix; the office
    portrait is used only as a fallback, labelled "photo on file", and is explicitly
    captioned "Office portrait - not taken at this visit". It is never stamped with the
    visit's coordinates, which would have the document assert that this picture was
    taken at the borrower's house.
59. **The documented upgrade SQL did not run.** The migration published in
    `DEPLOYMENT.md` for this release referenced a `users.signature_data` column that does
    not exist, and inserted permissions using `group_name` / `description` when the table
    has `module` / `display_name`. Both would have failed on the operator's live database,
    partway through, with the new code already serving. Nothing caught it because no
    harness had ever *run* the documentation. `tools/verify-upgrade-sql.sh` now extracts
    the SQL from the document itself — a copy in a test would drift, and the copy that
    matters is the one someone pastes into phpMyAdmin — reconstructs the pre-release
    schema, seeds rows so the `ALTER`s meet real data, runs it, and compares the outcome
    against `schema.sql`. It found both bugs on its first run, plus four column comments
    that differed, so an upgraded database is now indistinguishable from a fresh one.
60. **A harness that reported success having tested nothing.** The first working version
    of `verify-upgrade-sql.sh` printed `UPGRADE SQL OK` after three checks because the
    Python that replays the schema comparison into the shell counters died on a syntax
    error, and its output was piped into `eval`, which cheerfully evaluated nothing. Every
    structural comparison was skipped and the summary said everything passed. It now
    asserts the expected number of comparisons actually ran. A harness that passes while
    doing nothing is worse than one that fails, because it is believed.
65. **An expired session produced "Method Not Allowed".** `Csrf::enforce()` redirected a
    rejected request back to `$request->path()`. For a route registered with both verbs
    that is the page the form was on, which is fine — but a POST-only route has no `GET`
    handler, so the redirect landed on nothing and the router correctly answered `405`.
    The flash message explaining what had happened was written and then never shown,
    because 405 is not a page. It now returns to the referring page when it is
    same-origin — checked, because Referer is attacker-controlled and this path is reached
    exactly when a request looks like a cross-site post — and never to the route just
    refused, which would loop. Found because the first POST-only route in the codebase was
    added in the same change.
66. **The even distribution overshot, by counting the same leads twice.** Balancing total
    workload means starting from what each agent already holds — but the leads being placed
    are *inside* that count. A lead that kept its agent had that agent's tally incremented
    a second time, so the holder looked busier than they were, the run pushed the next lead
    elsewhere, and a branch that should have ended level ended 8 against 13. Every in-scope
    lead is now subtracted from the tally before placement begins, which makes a placement
    a plain increment for the lead that moves *and* the lead that stays. Caught because the
    test asserted the resulting spread rather than that the code ran.
67. **A test that asserted the wrong invariant about fairness.** The first version of the
    distribution test checked that the shares *inside one imported file* differed by at
    most one. That is round-robin's promise, not this one: an agent already carrying four
    leads **should** receive fewer from the next import, so the correct assertion is that
    total open workload per agent comes out level. The test was measuring the behaviour
    that was deliberately rejected.
68. **Two documented facts that were not true.** The README claimed "auto-assignment of
    leads to agents by branch and village" — a repo-wide search for any such code returns
    nothing, and never did. And the new migration's own confirmation query said
    `loan_accounts` would have 47 columns when the answer is 45, which would have had an
    operator conclude a successful migration had failed. Both were found by checking the
    documentation against the database rather than against memory.
69. **Growing the importer made the mapping screen unusable.** Detection went from 24
    recognised columns to 35, and the mapping table renders one row per *field* rather than
    per column in the file. A real export carries eight or ten, so the screen became
    twenty-five consecutive rows reading "— not in this file —" with the two or three that
    actually needed checking lost somewhere in the middle. Nothing broke, no test failed,
    and the change that caused it was an improvement — which is how this kind of regression
    ships. The table now leads with what was detected plus anything required and missing,
    and folds the rest behind a count. The smoke test measures it by splitting the HTML at
    the disclosure rather than counting the phrase, because every dropdown legitimately
    contains "not in this file" and 34 of those were never visible.
70. **A settings row a migration forgot would have been invisible.** `verify-upgrade-sql`
    compared columns, indexes, foreign keys and grants — but not `settings`. A missing
    settings row is a blank field on the admin screen and a default the app silently falls
    back to, which is exactly how a reminder ends up firing at the wrong time on an
    upgraded install and nowhere else. It now compares them by key and names the ones that
    differ. Found while adding two settings, by asking what the harness would *not* have
    caught.
71. **The documented migration used column names the table does not have.** Two of them:
    `setting_group` and `is_public`, where `settings` has `group_name` and `is_secret`.
    Caught on the first run of the upgrade harness — the third time that harness has caught
    a migration written in the same session as the schema it migrates to, and the reason it
    extracts the SQL from the document rather than keeping its own copy.
72. **Assignment was a one-shot decision disguised as a routine one.** Leads could only be
    assigned in the same breath as the import that created them. Whoever uploaded either
    picked the right agent at that moment, or the batch sat unassigned until somebody
    selected two hundred rows off the borrower list by hand. Nothing in the UI said the
    decision was final, which is what made it a trap rather than a limitation. The import
    history now addresses any past batch: distribute it evenly, hand it to one agent, or
    touch only the leads nobody has yet.
73. **Removing the consent screen removed the only thing that ever started recording.**
    The in-app location notice was taken out because the OS permission is the whole of the
    consent — but that screen also held the Start-duty button and the only
    `requestPermissions` call in the app. With it unreachable, `DutyLocationService` had no
    caller: the permission would never be asked for, no session would ever begin, and the
    app would have gone on looking healthy while capturing not one coordinate. Every test
    passed, because the tests asserted the screen's *internals* and the file was still on
    disk. Two lessons taken: a screen that is unreachable is deleted, not left orphaned
    with tests pinning its behaviour; and the shell every signed-in agent lands on now asks
    for the permission and starts the session — consent posted first, since the server
    answers 412 until it is on file and the service reads a 412 as a withdrawal and stops
    itself. `LocationTrackingTest` now asserts the live path, that signing out stops
    recording, and that the deleted screen's strings went with it.
74. **Every CI build was a different app.** No debug signing config was declared, so
    Gradle generated `debug.keystore` on whichever machine ran the build — a fresh
    certificate per CI run. Android refuses to install an APK over an app signed by a
    different certificate, so the "new" APK could only be installed by uninstalling the
    old one, which takes the agent's cached captures with it. From the outside it looks
    exactly like the build never happened: the file downloads, the install fails, and the
    old app is still there. Nothing in any test noticed, because every harness built and
    verified a *single* APK and never asked whether two consecutive builds agreed. Fixed
    by committing a debug keystore (public default passwords, `.debug` application id, so
    it guards nothing) and asserting the APK's certificate against its fingerprint.
75. **And the build nobody could download.** The APK was published only as a workflow
    artifact, which GitHub serves as a ZIP to signed-in users. The people who need it are
    holding the phone they want to install it on: no unzip tool, no GitHub session. It is
    now also a release asset under a fixed name, so one permanent link downloads a plain
    `.apk`. Worth writing down because the build itself was correct every time — the
    failure was entirely in how it was handed over, which is not something a test suite
    is ever asked about.
76. **Every multi-line caption on the printed report had been running together.**
    `Pdf::text()` stripped control characters, and it ran *before* `Pdf::wrap()`, which is
    what splits on newlines. So a caption built as "name\ncode\nposition" reached the page
    as `Suresh YadavBC000726.912400, 75.787300`. It had been like that for as long as
    captioned images existed and **every test passed**, because each harness asserted
    `str_contains($pdf, 'some phrase')` and each phrase was individually present — nothing
    ever asked what sat next to it. Found by extracting the text operators out of a
    generated PDF in page order and reading them, which is the first time anybody looked
    at the output rather than searched it. `text()` now keeps `\n` and drops the rest, and
    `escape()` turns any newline that still reaches a single-line draw into a space, since
    one text operator draws one line wherever the cursor already is. The block's cursor
    advance is now asserted exactly rather than with `>=`: the loose bound was large
    enough to hide a caption measured as one line when it was three.
77. **Three things wrong with the dropdowns, found by auditing all of them at once.**
    Asked to "check the dropdown menus", so every `<select>` on every page was parsed out
    of the rendered HTML rather than read in the views — the options come from the
    database, from a permission check, or from a filter applied a moment ago, so a select
    that looks fine in the source can still render as an unusable control. What came out:
    - **Two settings were dropdowns whose choices were `1` and `0`.** "Remind agents to
      submit" and "Resolve addresses from coordinates" are yes/no questions, and the
      operator had to guess which number meant on. `input_type = 'toggle'` already existed
      in the schema and in the controller, and *nothing used it*. They are switches now.
    - **A filter dropdown silently threw away the sort.** `sort_link()` builds on the
      current query string, so sorting kept the filters — but a filter form submits only
      its own fields, so choosing a village after sorting by outstanding came back in the
      default order with nothing to say why. One-directional losses are the worst kind:
      the feature appears to work in the direction you tested. Fixed with `sort_hidden()`
      in all five sortable lists.
    - **The settings screen had no defence against a dropdown it could not render.** A
      `select` setting with no `options` produced an empty menu — a control that shows
      nothing and submits nothing, indistinguishable from a broken page. And a stored
      value outside its own list was worse: the browser displayed the *first* choice as
      though it were current, so the next Save silently rewrote the setting. Now an
      optionless select degrades to a text box that says why, and an unlisted value is
      kept, marked "current, not in the list", and warned about.

    The audit is now part of the smoke run (18 assertions), `verify-schema` refuses a
    dropdown setting with no choices or a value outside its own list — and proves those
    checks fail by inserting a broken row on purpose — and `verify-upgrade-sql` compares
    each setting's *control type and choices*, not just that its key exists. The app's own
    dropdowns were already covered: `VisitFormDataTest` pins the occupation list to the
    server enum and `SssEntryTest` pins the four schemes.

---

## Continuous integration

| Workflow | Trigger | Output |
|---|---|---|
| `.github/workflows/verify-backend.yml` | push / PR | `php -l` sweep, core self-test, schema import and integration suite against a MySQL 8 service container |
| `.github/workflows/build-android.yml` | push / PR / manual | JDK 17 + Android SDK, unit tests, and an **installable** APK — as a build artifact, and on `main` also as a release asset |

Both trigger on `main` and on `feat/**` / `fix/**` branches.

`build-android.yml` produces a signed release APK when the repository provides
`KEYSTORE_BASE64`, `KEYSTORE_PASSWORD`, `KEY_ALIAS` and `KEY_PASSWORD` secrets —
it recreates the keystore on the runner, verifies the signature with `apksigner`,
and deletes the keystore afterwards.

**Without those secrets it builds the debug APK instead.** That is the important
part: `assembleRelease` with no signing config emits `app-release-unsigned.apk`,
and **Android refuses to install an unsigned APK**, so uploading one as "the
build" would give you a file you cannot put on a phone. The debug APK is signed
and installs immediately. `tools/verify-signing.sh` asserts both halves of this:
that the unsigned release APK fails `apksigner verify`, and that the debug APK
passes it.

**The debug keystore is committed**, which is deliberate and is what makes the
build usable in the field. Gradle's default is to generate one on whichever machine
is building, so every CI run signed with a fresh certificate — and Android refuses
to install an APK over an app signed by a different certificate. Each build was
therefore a *new app* that could only be installed by uninstalling the previous one,
taking the agent's cached work with it. "The new APK does not work" is exactly what
that looks like on a phone. A debug key protects nothing (public default passwords,
and a `.debug` application id), so it is committed and every build is a normal
update of the one before it. `verify-signing.sh` compares the APK's certificate
against that keystore's fingerprint.

**On `main`, the APK is also published as a release asset** under a fixed name, so
there is one permanent link that installs straight from a phone browser:

```
https://github.com/<owner>/<repo>/releases/download/latest-apk/d2recovery-latest.apk
```

A build artifact is a ZIP behind a GitHub login. On a phone that is a dead end — no
unzip tool, no session, nothing installable — which is a fine way to ship a build
nobody can actually run.

---

## Branches

| Branch | Contents |
|---|---|
| `main` | full source: backend, Android app, tests, CI, docs |
| [`hosting`](../../tree/hosting) | **upload-ready package** — drop straight into `public_html` |

The `hosting` branch is generated, never edited by hand:

```bash
sh tools/make-hosting-package.sh                    # build into .verify/hosting
LRMS_DOCROOT=.verify/hosting sh tools/smoke-panel.sh   # prove it serves traffic
```

It contains the *contents* of `admin/` at the root, plus `schema.sql`,
`DEPLOYMENT.md` and a hosting-specific README — no Android project, no test
harnesses, no CI config, and no `config.php`. The builder reads from the
committed git tree rather than the working directory, precisely so local run
artefacts (error logs, imported CSVs, a real `config.php` with live credentials)
cannot leak into a published package, and it fails the build if any of them
appear.

To install:

```bash
git clone -b hosting https://github.com/dhdhdh51/Bankmitra2.git lrms
cp -r lrms/. public_html/ && rm -rf public_html/.git
```

## Deployment

See **[DEPLOYMENT.md](DEPLOYMENT.md)** — requirements, database import, upload layout,
sub-directory installs, file permissions, settings reference, cron entries, Android
build and signing, the security checklist, troubleshooting, and the upgrade path
(including notes on AGP 9.x, which this project intentionally does not use yet).

---

## Licence

No licence has been chosen yet; add one before distributing.
