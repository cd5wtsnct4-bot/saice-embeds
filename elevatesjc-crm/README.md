# Elevate SJC CRM

A lightweight CRM for Elevate SJC's training/consulting pipeline — contacts,
deals (training enquiries), a Kanban pipeline, tasks, and a programs catalog
(Leadership Development, Technical Skills, Soft Skills, Data Analytics &
Visualisation, E-Learning). Plain PHP + MySQL, no framework, no build step —
upload it to any standard PHP/MySQL web host.

**Branding note:** the live site at elevatesjc.co.za wasn't reachable when
this was built, so the colors in `css/styles.css` (`:root` variables at the
top of the file) are a professional placeholder, and the header uses a
text/initial mark instead of the real logo. Swap `--brand-primary` /
`--brand-accent` for the exact brand hex codes and drop the real logo file
in once you have them — everything else references those two variables.

## Requirements

- PHP 8.0+ with `pdo_mysql`, `curl`, `openssl`, `json` extensions (all
  standard on shared hosting)
- MySQL 5.7+ / MariaDB 10.2+
- Apache with `mod_rewrite`/`mod_headers` (an `.htaccess` is included) or
  nginx (see the snippet below)

## 1. Database setup

```sql
CREATE DATABASE elevatesjc_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'elevatesjc_crm'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON elevatesjc_crm.* TO 'elevatesjc_crm'@'localhost';
FLUSH PRIVILEGES;
```

Then import the schema (tables + a few sample contacts/deals/tasks so the
CRM isn't empty on first login):

```bash
mysql -u elevatesjc_crm -p elevatesjc_crm < schema.sql
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

## 5. Security notes

- All data-layer queries use parameterised PDO statements (no string-built
  SQL).
- Passwords are hashed with bcrypt (`password_hash`/`password_verify`);
  plaintext passwords are never stored.
- Sessions are `httponly`, `SameSite=Lax`, and marked `secure` whenever
  `CRM_FORCE_SECURE_COOKIES` is on (default) — serve the CRM over HTTPS.
- Every state-changing API request (POST/PUT/DELETE) requires a matching
  CSRF token, checked against the session.
- `config.php`, `schema.sql` and everything under `includes/` are blocked
  from direct web access via `.htaccess`. **On nginx**, add the equivalent
  yourself, e.g.:
  ```nginx
  location ~ ^/(config\.php|schema\.sql)$ { deny all; }
  location ^~ /includes/ { deny all; }
  ```
- This app is single-tenant/single-organisation by design (one MySQL
  database, a handful of named users) — it is not built for public
  self-signup, and there is no "forgot password" flow; an admin resets
  passwords via **Users**.

## Folder layout

```
elevatesjc-crm/
├── index.php            # main app shell (requires login)
├── login.php             # sign-in page (local + optional Microsoft button)
├── logout.php
├── config.php            # DB + Microsoft OAuth config
├── schema.sql             # MySQL schema + seed data
├── includes/
│   ├── db.php             # PDO connection
│   ├── auth.php           # session/CSRF/login helpers
│   ├── response.php       # JSON response helpers
│   └── msal_lite.php      # Microsoft OAuth2 + JWT verification (no external deps)
├── auth/
│   ├── ms_login.php       # redirects to Microsoft sign-in
│   └── ms_callback.php    # handles the return trip, verifies + logs in
├── api/                   # JSON endpoints consumed by js/app.js
│   ├── auth.php, contacts.php, deals.php, tasks.php,
│   └── programs.php, settings.php, dashboard.php, users.php
├── css/styles.css
└── js/app.js              # single-page app: router + all views
```

## What this is not

This is a small-team CRM for one organisation's own staff, not a
multi-tenant SaaS product — there's no billing, no public registration, no
audit log, and no rate limiting on the login form. If Elevate SJC's needs
grow past a handful of internal users, treat this as a solid starting point
rather than a finished platform.
