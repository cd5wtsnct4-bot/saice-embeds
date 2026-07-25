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
migration, which only adds what's missing:

```bash
mysql -u elevatesjc_crm -p elevatesjc_crm < migrations/002_calendar_proposals_invoices_expenses.sql
```

## 2. Configure `config.php`

Either edit `config.php` directly, or (preferred) set these as real
environment variables on the server and leave the file's `getenv()`
fallbacks in place:

| Variable | Purpose |
|---|---|
| `CRM_DB_HOST`, `CRM_DB_PORT`, `CRM_DB_NAME`, `CRM_DB_USER`, `CRM_DB_PASS` | MySQL connection |
| `CRM_MS_CLIENT_ID`, `CRM_MS_CLIENT_SECRET`, `CRM_MS_TENANT_ID`, `CRM_MS_REDIRECT_URI` | Microsoft sign-in (optional — leave `CRM_MS_CLIENT_ID` blank to hide the button) |
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

## 5. Calendar

A month-view calendar (**Calendar** in the nav) for meetings, training
sessions and follow-up calls, optionally linked to a contact and/or deal.
Click a day to add an event, click an event to edit or delete it.

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

## Folder layout

```
elevatesjc-crm/
├── index.php               # main app shell (requires login)
├── login.php                # sign-in page (local + optional Microsoft button)
├── logout.php
├── config.php               # DB + Microsoft OAuth config
├── schema.sql                # MySQL schema + seed data (fresh installs)
├── migrations/
│   └── 002_calendar_proposals_invoices_expenses.sql   # for existing installs
├── proposal_print.php        # printable proposal letterhead
├── invoice_print.php         # printable invoice letterhead (+ banking details)
├── download_receipt.php      # gated receipt image viewer
├── includes/
│   ├── db.php                # PDO connection
│   ├── auth.php               # session/CSRF/login helpers
│   ├── response.php           # JSON response helpers
│   ├── numbering.php           # atomic invoice/proposal numbering
│   ├── uploads.php             # secure receipt upload handling
│   ├── ocr.php                  # best-effort tesseract OCR (feature-detected)
│   └── msal_lite.php           # Microsoft OAuth2 + JWT verification (no external deps)
├── auth/
│   ├── ms_login.php           # redirects to Microsoft sign-in
│   └── ms_callback.php         # handles the return trip, verifies + logs in
├── api/                       # JSON endpoints consumed by js/app.js
│   ├── auth.php, contacts.php, deals.php, tasks.php, calendar.php,
│   ├── proposals.php, invoices.php, expenses.php, expenses_upload.php,
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
