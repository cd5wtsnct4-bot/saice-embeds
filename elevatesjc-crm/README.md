# Elevate SJC CRM

A lightweight CRM for Elevate SJC's training/consulting pipeline — contacts,
deals (training enquiries), a Kanban pipeline, a calendar, proposals,
invoicing, expense tracking with phone receipt scanning, and a programs
catalog (Leadership Development, Technical Skills, Soft Skills, Data
Analytics & Visualisation, E-Learning). Plain PHP + MySQL, no framework, no
build step — upload it to any standard PHP/MySQL web host.

**Branding note:** the live site at elevatesjc.co.za wasn't reachable when
this was built, so the colors in `css/styles.css` (`:root` variables at the
top of the file) are a professional placeholder, and the header uses a
text/initial mark instead of the real logo. Swap `--brand-primary` /
`--brand-accent` for the exact brand hex codes and drop the real logo file
in once you have them — everything else references those two variables.

## Requirements

- PHP 8.0+ with `pdo_mysql`, `curl`, `openssl`, `json`, `gd` (or `exif`)
  extensions (all standard on shared hosting)
- MySQL 5.7+ / MariaDB 10.2+
- Apache with `mod_rewrite`/`mod_headers` (an `.htaccess` is included) or
  nginx (see the snippet below)
- *(Optional)* the `tesseract-ocr` command-line tool, for automatic text
  recognition on scanned receipts — see **Expenses & receipt scanning**
  below. Everything works without it; you just type receipt details in by
  hand instead of having them suggested.

## 1. Database setup

```sql
CREATE DATABASE elevatesjc_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'elevatesjc_crm'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON elevatesjc_crm.* TO 'elevatesjc_crm'@'localhost';
FLUSH PRIVILEGES;
```

Then import the schema (tables + sample contacts/deals/tasks/proposals/
invoices/expenses so the CRM isn't empty on first login):

```bash
mysql -u elevatesjc_crm -p elevatesjc_crm < schema.sql
```

**Upgrading an existing install** that predates the calendar/proposals/
invoicing/expenses tables? Don't re-run `schema.sql` — instead apply the
migrations in order, which only add what's missing:

```bash
mysql -u elevatesjc_crm -p elevatesjc_crm < migrations/002_calendar_proposals_invoices_expenses.sql
mysql -u elevatesjc_crm -p elevatesjc_crm < migrations/003_ms_calendar_sync.sql
```

Logo/colors/template-style/footer-notes (§6) need **no migration at all** —
they're just new keys in the existing `settings` table. Just make sure the
new `assets/branding/` folder (with its `.htaccess`) is uploaded alongside
the PHP changes.

## 2. Configure `config.php`

Either edit `config.php` directly, or (preferred) set these as real
environment variables on the server and leave the file's `getenv()`
fallbacks in place:

| Variable | Purpose |
|---|---|
| `CRM_DB_HOST`, `CRM_DB_PORT`, `CRM_DB_NAME`, `CRM_DB_USER`, `CRM_DB_PASS` | MySQL connection |
| `CRM_MS_CLIENT_ID`, `CRM_MS_CLIENT_SECRET`, `CRM_MS_TENANT_ID`, `CRM_MS_REDIRECT_URI` | Microsoft sign-in (optional — leave `CRM_MS_CLIENT_ID` blank to hide the button) |
| `CRM_MS_CALENDAR_REDIRECT_URI` | Second redirect URI for "Connect Microsoft Calendar" (see §4b) |
| `CRM_TOKEN_ENC_KEY` | Base64 32-byte key encrypting stored calendar OAuth tokens (see §4b) — leave blank to hide calendar-connect entirely |
| `CRM_CALENDAR_SYNC_PAST_DAYS`, `CRM_CALENDAR_SYNC_FUTURE_DAYS` | Sync window size around today (defaults 30 / 180 days) |
| `CRM_CRON_SECRET` | Shared secret for `cron/sync_calendars.php` background sync (see §5) — leave blank to disable it |
| `CRM_FORCE_SECURE_COOKIES` | `true` in production (HTTPS); the session cookie won't be sent over plain HTTP otherwise |
| `CRM_DEBUG` | `true` only while developing — surfaces PHP errors and DB error detail |

## 3. Upload

Copy the whole `elevatesjc-crm/` folder to your webserver (e.g.
`public_html/crm/`). Point your browser at `.../crm/index.php` — it will
redirect to `login.php` if you're not signed in yet.

**Default login:** username `admin`, password `ElevateSJC!2026`.
**Change this password immediately** after your first login isn't currently
exposed in the UI as a self-service "change my password" — as the admin,
go to Users, edit the `admin` account, and set a new password there.

## 4. (Optional) "Sign in with Microsoft"

This lets staff sign in with their Microsoft 365 / Entra ID work account
instead of a local password.

1. In the [Azure Portal](https://portal.azure.com) go to **Microsoft Entra
   ID > App registrations > New registration**.
2. Redirect URI: **Web** platform,
   `https://YOUR-DOMAIN/path/to/elevatesjc-crm/auth/ms_callback.php`
   (must match `CRM_MS_REDIRECT_URI` exactly, including https).
3. Under **Certificates & secrets**, create a new client secret — this is
   `CRM_MS_CLIENT_SECRET`.
4. Copy the **Application (client) ID** — `CRM_MS_CLIENT_ID`.
5. Set `CRM_MS_TENANT_ID` to your organisation's **Directory (tenant) ID**
   (recommended) rather than `common` — `common` would let *any* Microsoft
   or Outlook.com account attempt to sign in, though see the next point for
   why that's still safe by default.
6. **Accounts must be pre-provisioned.** Signing in with Microsoft never
   auto-creates a new CRM account — it only logs a person in if an admin has
   already added them under **Users** with their work email address. Their
   first Microsoft sign-in links that email to their Microsoft account
   automatically; nobody else gets in.

The ID token's signature is verified against Microsoft's published signing
keys (RS256, fetched from the tenant's JWKS endpoint and cached for an
hour) — a forged or tampered token is rejected before any session is
created.

## 4b. (Optional) Two-way Microsoft calendar sync

Lets any CRM user connect their own Microsoft 365 mailbox so its calendar
events show up in the CRM's **Calendar** view alongside everyone else's, and
events created in the CRM get pushed to that mailbox too.

1. **Generate an encryption key** for stored tokens — run
   `openssl rand -base64 32` and set the result as `CRM_TOKEN_ENC_KEY`. This
   never lives in the database; losing or rotating it just means everyone
   reconnects their calendar (nothing else is affected).
2. On the **same** Azure app registration used for sign-in (§4), add a
   second **Web** redirect URI:
   `https://YOUR-DOMAIN/path/to/elevatesjc-crm/auth/ms_calendar_callback.php`
   — set `CRM_MS_CALENDAR_REDIRECT_URI` to match exactly.
3. Under **API permissions**, add the delegated Microsoft Graph permission
   `Calendars.ReadWrite` (in addition to the default `openid`/`profile`/
   `email`/`offline_access` already used for sign-in).
4. Each user connects their own calendar from **Settings > Connected
   Calendars** — this is a per-user consent, independent of how they logged
   into the CRM (local password or Microsoft SSO), so it works either way.

**How sync works:** a connected event stays tied to the calendar it was
created on — the CRM never guesses which mailbox to push a plain local
event to, and a user can only file new events into their *own* connected
calendar(s) (Microsoft doesn't let you write into someone else's mailbox
without delegated access). Opening the Calendar tab opportunistically
pulls fresh data for your own connections; there's also a manual **Sync
now** button. For sync that doesn't depend on anyone having the tab open,
add a cron job:

```bash
# cPanel > Cron Jobs, e.g. every 15 minutes
php /home/youruser/public_html/elevatesjc-crm/cron/sync_calendars.php YOUR_CRM_CRON_SECRET
```

(set `CRM_CRON_SECRET` to a long random value first — the script refuses to
run without it matching).

**Known limitation:** events deleted directly in Outlook aren't
automatically removed from the CRM — pulling only adds/updates what
Microsoft's calendar-view API returns, which doesn't report deletions
(that needs Graph's `/delta` endpoint, a possible future enhancement).
Deleting from the CRM side does push a real delete to Outlook.

## 5. Calendar

A month-view calendar (**Calendar** in the nav) for meetings, training
sessions and follow-up calls, optionally linked to a contact and/or deal.
Click a day to add an event, click an event to edit or delete it. A colour
legend at the top lets you show/hide each connected Microsoft calendar (see
§4b) alongside purely local CRM events. A booking spanning more than one day
shows as booked on every day it covers, with a "› cont." marker on the
later days.

Below ~640px wide (a phone, or the installed app — see §9) the grid switches
to a day-grouped agenda list instead, since a 7-column grid is too cramped
to read at that size. This happens live on resize (rotating the phone,
resizing a browser window) — no page reload needed.

## 9. Installing on your iPhone (or any phone) as an app

The CRM is a installable web app (PWA) — no App Store needed:

1. Open the CRM in **Safari** on your iPhone (not Chrome — iOS only allows
   Safari to install web apps to the home screen).
2. Tap the **Share** icon, then **Add to Home Screen**.
3. It installs with your company's logo/name as the icon (see below) and
   opens full-screen, without Safari's address bar — hamburger menu and all
   the same responsive behaviour as the browser version.

**Using your own logo as the app icon:** upload it once under **Settings >
Logo**. Most logos are a wide/tall lockup (a mark plus a wordmark), which
would look squashed or illegible as a tiny home-screen icon, so the upload
automatically generates a square crop for that specific purpose —
`includes/branding.php`'s `generate_square_icon()` crops to the top of the
image for portrait-oriented logos (where the mark usually sits, with
tagline/text below) and to the centre for landscape/square ones. Settings
shows a preview of exactly what will be used as the icon; if it doesn't
frame your logo well, crop a square version yourself and upload that
instead — it'll be used as-is by the same generator (a square input crops
to itself).

Under the hood: `manifest.php` is a dynamically-generated Web App Manifest
(name/colours/icon follow your current Settings, not a hardcoded default),
and `sw.js` is a minimal service worker that caches only `css/styles.css`
and `js/app.js` for a faster/offline-tolerant app shell — it never caches
API responses or any `*.php` page, since those carry live business data and
a per-session CSRF token that must never go stale.

## 6. Proposals & Invoicing

- **Proposals** — build a quote with line items against a contact/deal,
  move it through `draft → sent → accepted/declined`, and open **View** for
  a print-ready letterhead (`proposal_print.php`, uses your Settings >
  Company Details). Numbered automatically (`PRO-2026-0001`, ...).
- **Invoices** — same idea with a tax rate, issue/due dates, and a
  **Mark Paid** action. An **accepted** proposal gets a **To Invoice**
  button that copies its line items straight into a new draft invoice
  (`invoices.php?from_proposal_id=`). Numbered separately
  (`INV-2026-0001`, ...). The print view also shows your banking details
  (Settings) so clients know where to pay.
- Both number sequences are allocated with `SELECT ... FOR UPDATE` inside a
  transaction (`includes/numbering.php`), so two people creating a document
  at the same moment can never collide on the same number. A failed save
  after a number was allocated leaves a gap rather than reusing it — normal
  behaviour for sequential document numbering, not a bug.

### Branding: logo & colours

**Settings > Logo** lets an admin upload a JPEG/PNG/WebP logo (max 3MB) —
it replaces the letter-mark in the sidebar, the login screen, and the
proposal/invoice letterhead. It's stored under the public `assets/branding/`
folder (unlike `uploads/`, this one is deliberately *not* access-denied,
since the login screen shows it before anyone is authenticated) with a
random filename; the previous file is deleted whenever you upload a
replacement or remove it. SVG uploads aren't accepted — a directly-browsed
SVG can carry a `<script>`, and this folder has no auth gate to stop that.

Beyond **Primary** and **Accent** color, there's now a **Secondary Accent
Color** (defaults to gold, `#F4A300`) used for KPI highlights and the "Won"
pipeline stage — three colors is enough to skin the whole app without
turning this into a full theme editor.

### Customisable quote/proposal/invoice templates

**Settings > Quote / Proposal / Invoice Template** picks one of three
letterhead layouts, all reading the same data (logo, colors, company
details) — no PHP editing required to reskin a document:

- **Classic** — bordered letterhead, closest to the original design.
- **Modern** — a bold color band across the top.
- **Minimal** — an understated thin rule line, no color block.

**Proposal Footer Note** / **Invoice Footer Note** add your own free-text
block (terms, a thank-you line, banking reminders) to the bottom of each
document type. The shared layout logic lives in
`includes/print_template.php` so `proposal_print.php` and
`invoice_print.php` never duplicate CSS.

## 7. Expenses & receipt scanning

Track expenses (category, amount, vendor, payment method), optionally
linked to a deal, with a lightweight approval flow: a submitter's own
expense starts **pending**; an administrator **Approve**s and later marks
it **Reimbursed**. Non-admins can only edit/delete their *own* still-pending
expenses.

**"Scan a Slip"** on the Expenses page opens your phone's camera
(`<input type="file" capture="environment">` — on a phone this opens the
camera directly instead of a file picker) or lets you choose an existing
photo. The image uploads to the server and, if available, is run through
OCR to *suggest* an amount, date and vendor — you always see them in a
normal editable form before saving, never saved automatically.

Automatic text recognition needs the `tesseract-ocr` package on the
server:

```bash
# Debian/Ubuntu
sudo apt-get install tesseract-ocr
```

If it isn't installed (common on shared hosting), the photo still attaches
to the expense fine — you just fill in the amount/date/vendor by hand.
The app detects availability at runtime (`includes/ocr.php`); there's
nothing to configure.

Receipt images are validated server-side with `getimagesize()` (a real
image decode, not a trusted file extension), renamed to a random filename,
and stored under `uploads/receipts/`, which itself **denies all direct web
access** — the only way to view one is `download_receipt.php?expense_id=`,
which requires a logged-in session and looks the file up from the
database rather than trusting a client-supplied path.

## 8. Security notes

- All data-layer queries use parameterised PDO statements (no string-built
  SQL).
- Passwords are hashed with bcrypt (`password_hash`/`password_verify`);
  plaintext passwords are never stored.
- Sessions are `httponly`, `SameSite=Lax`, and marked `secure` whenever
  `CRM_FORCE_SECURE_COOKIES` is on (default) — serve the CRM over HTTPS.
- Every state-changing API request (POST/PUT/DELETE) requires a matching
  CSRF token, checked against the session.
- `config.php`, `schema.sql` and everything under `includes/` and
  `uploads/` are blocked from direct web access via `.htaccess` (verified
  against a real Apache instance while building this — not just written
  and assumed). **On nginx**, add the equivalent yourself, e.g.:
  ```nginx
  location ~ ^/(config\.php|schema\.sql)$ { deny all; }
  location ^~ /includes/ { deny all; }
  location ^~ /uploads/ { deny all; }
  ```
- Uploaded receipt images are validated as real images (not just by file
  extension), renamed randomly, and only ever served back through
  `download_receipt.php` after an auth check — never linked to directly.
- This app is single-tenant/single-organisation by design (one MySQL
  database, a handful of named users) — it is not built for public
  self-signup, and there is no "forgot password" flow; an admin resets
  passwords via **Users**.
- Microsoft calendar OAuth tokens are encrypted at rest (AES-256-GCM,
  `CRM_TOKEN_ENC_KEY`) — a database dump alone doesn't expose usable
  tokens. `cron/sync_calendars.php` is reachable over plain HTTP but is
  useless without `CRM_CRON_SECRET`, compared with `hash_equals()`.
- The logo lives under `assets/branding/`, which is intentionally *not*
  access-denied (unlike `uploads/`) since the login screen needs to show
  it while logged out. Only JPEG/PNG/WebP are accepted — validated with a
  real `getimagesize()` decode, not a file extension check — specifically
  to keep SVG (and its script capability) out of a folder with no auth
  gate. Uploads are admin-only and CSRF-protected.
- `manifest.php` and `sw.js` are public and unauthenticated by design — a
  browser must be able to fetch both before a user has logged in (that's
  the whole point of "Add to Home Screen"). Neither exposes anything
  beyond what the login screen itself already shows (company name,
  colours, logo); `sw.js` only ever caches static CSS/JS, never a page or
  API response, so there's no live data to leak through it.

## Folder layout

```
elevatesjc-crm/
├── index.php               # main app shell (requires login)
├── login.php                # sign-in page (local + optional Microsoft button)
├── logout.php
├── config.php               # DB + Microsoft OAuth config
├── schema.sql                # MySQL schema + seed data (fresh installs)
├── migrations/
│   ├── 002_calendar_proposals_invoices_expenses.sql   # for existing installs
│   └── 003_ms_calendar_sync.sql                        # adds Microsoft calendar connections
├── proposal_print.php        # printable proposal letterhead (customisable, see §6)
├── invoice_print.php         # printable invoice letterhead (customisable, see §6)
├── download_receipt.php      # gated receipt image viewer
├── manifest.php               # dynamic PWA Web App Manifest (see §9)
├── sw.js                       # service worker — caches only css/styles.css + js/app.js
├── assets/
│   ├── branding/               # uploaded logo + generated icon crop (public, see Security notes)
│   └── icons/                  # default app icon set, used until a logo is uploaded
├── cron/
│   └── sync_calendars.php     # background sync for all connected calendars (secret-guarded)
├── includes/
│   ├── db.php                # PDO connection
│   ├── auth.php               # session/CSRF/login helpers
│   ├── response.php           # JSON response helpers
│   ├── numbering.php           # atomic invoice/proposal numbering
│   ├── branding.php             # logo upload validation + storage + square icon crop
│   ├── print_template.php       # shared letterhead CSS/markup for the 3 template styles
│   ├── uploads.php             # secure receipt upload handling
│   ├── ocr.php                  # best-effort tesseract OCR (feature-detected)
│   ├── msal_lite.php           # Microsoft OAuth2 + JWT verification (no external deps)
│   ├── crypto.php               # AES-256-GCM encryption for stored OAuth tokens
│   ├── graph_calendar.php       # Microsoft Graph calendar API client
│   └── calendar_sync.php        # two-way sync orchestration (push retries + pull)
├── auth/
│   ├── ms_login.php           # redirects to Microsoft sign-in
│   ├── ms_callback.php         # handles the return trip, verifies + logs in
│   ├── ms_calendar_connect.php  # redirects to Microsoft calendar consent
│   └── ms_calendar_callback.php # stores the encrypted connection for the logged-in user
├── api/                       # JSON endpoints consumed by js/app.js
│   ├── auth.php, contacts.php, deals.php, tasks.php, calendar.php,
│   ├── calendar_connections.php, proposals.php, invoices.php,
│   ├── expenses.php, expenses_upload.php, settings_logo.php,
│   └── programs.php, settings.php, dashboard.php, users.php
├── uploads/receipts/          # scanned slips (denies direct web access)
├── css/styles.css
└── js/app.js                  # single-page app: router + all views
```

## What this is not

This is a small-team CRM for one organisation's own staff, not a
multi-tenant SaaS product — there's no billing, no public registration, no
audit log, and no rate limiting on the login form. If Elevate SJC's needs
grow past a handful of internal users, treat this as a solid starting point
rather than a finished platform.
