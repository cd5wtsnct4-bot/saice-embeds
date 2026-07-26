<?php
/**
 * Elevate SJC CRM — configuration
 * Copy real values in here (or better: set them as environment variables on
 * the webserver and leave the getenv() fallbacks). Never commit real
 * production secrets to source control.
 */

// ---------------------------------------------------------------
// Database (MySQL)
// ---------------------------------------------------------------
define('DB_HOST', getenv('CRM_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('CRM_DB_PORT') ?: '3306');
define('DB_NAME', getenv('CRM_DB_NAME') ?: 'elevatesjc_crm');
define('DB_USER', getenv('CRM_DB_USER') ?: 'elevatesjc_crm');
define('DB_PASS', getenv('CRM_DB_PASS') ?: 'CHANGE_ME');

// ---------------------------------------------------------------
// Microsoft Entra ID (Azure AD) — "Sign in with Microsoft"
// Register an app at https://portal.azure.com > Entra ID > App registrations.
// Redirect URI must exactly match MS_REDIRECT_URI below (https:// in prod).
// Leave MS_CLIENT_ID blank to hide the Microsoft sign-in button entirely.
// ---------------------------------------------------------------
define('MS_CLIENT_ID', getenv('CRM_MS_CLIENT_ID') ?: '');
define('MS_CLIENT_SECRET', getenv('CRM_MS_CLIENT_SECRET') ?: '');
// 'common' allows any Microsoft/organisational account; use a specific
// tenant GUID to restrict sign-in to only your organisation's directory.
define('MS_TENANT_ID', getenv('CRM_MS_TENANT_ID') ?: 'common');
define('MS_REDIRECT_URI', getenv('CRM_MS_REDIRECT_URI') ?: 'https://YOUR-DOMAIN/elevatesjc-crm/auth/ms_callback.php');

// ---------------------------------------------------------------
// Microsoft 365 calendar sync (two-way, per-user connected mailboxes)
// Register a second redirect URI on the same Azure app registration:
//   https://YOUR-DOMAIN/elevatesjc-crm/auth/ms_calendar_callback.php
// CRM_TOKEN_ENC_KEY must be a random base64-encoded 32-byte key
// (generate with `openssl rand -base64 32`) — leave it blank to hide
// the "Connect Microsoft Calendar" option entirely. Losing/rotating
// this key permanently invalidates all stored connections; users
// simply reconnect.
// ---------------------------------------------------------------
define('MS_CALENDAR_REDIRECT_URI', getenv('CRM_MS_CALENDAR_REDIRECT_URI') ?: 'https://YOUR-DOMAIN/elevatesjc-crm/auth/ms_calendar_callback.php');
define('CRM_TOKEN_ENC_KEY', getenv('CRM_TOKEN_ENC_KEY') ?: '');
// How many days of events to pull on each sync, centred on today.
define('CRM_CALENDAR_SYNC_PAST_DAYS', (int)(getenv('CRM_CALENDAR_SYNC_PAST_DAYS') ?: 30));
define('CRM_CALENDAR_SYNC_FUTURE_DAYS', (int)(getenv('CRM_CALENDAR_SYNC_FUTURE_DAYS') ?: 180));
// Shared secret for the cron/sync_calendars.php endpoint (background sync
// without anyone having the Calendar tab open). Leave blank to disable it.
define('CRM_CRON_SECRET', getenv('CRM_CRON_SECRET') ?: '');

// ---------------------------------------------------------------
// App
// ---------------------------------------------------------------
define('APP_NAME', 'Elevate SJC CRM');
// Force HTTPS-only session cookies once the site is served over HTTPS.
define('APP_FORCE_SECURE_COOKIES', filter_var(getenv('CRM_FORCE_SECURE_COOKIES') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('APP_DEBUG', filter_var(getenv('CRM_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
