<?php
/**
 * Session, authentication and CSRF helpers shared by page scripts and the
 * JSON API. Two login paths feed into the same session mechanism:
 *   - local username/password (see api/auth.php)
 *   - Microsoft Entra ID OAuth (see auth/ms_login.php + auth/ms_callback.php)
 */
require_once __DIR__ . '/db.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = defined('APP_FORCE_SECURE_COOKIES') ? APP_FORCE_SECURE_COOKIES : true;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('elevatesjc_crm_sess');
    session_start();
}

/** Fetch the logged-in user's row fresh from the DB, or null. */
function current_user(): ?array
{
    start_secure_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, name, username, email, role, active, auth_provider FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['active']) {
        return null;
    }
    return $user;
}

/** Establish a logged-in session for the given user id. Regenerates the
 *  session id to prevent session fixation across the auth boundary. */
function login_user_id(int $userId): void
{
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function logout_user(): void
{
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** For plain page scripts (login.php, index.php): redirect to login if
 *  not authenticated. Returns the current user on success. */
function require_login_page(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

/** For api/*.php endpoints: 401 JSON error if not authenticated. */
function require_login_api(): array
{
    require_once __DIR__ . '/response.php';
    $user = current_user();
    if (!$user) {
        json_error('Not authenticated.', 401);
    }
    return $user;
}

/**
 * Resolve a Microsoft sign-in to a local CRM account.
 *
 * By design this never auto-creates a brand new account from an arbitrary
 * Microsoft login — with MS_TENANT_ID left as "common" that would let
 * anyone with a Microsoft/Outlook account onto the CRM. Instead:
 *   1. If this Microsoft identity (oid) is already linked, log in.
 *   2. Else, if a CRM user exists with a matching email, link it (one-time)
 *      and log in — this is how an admin-provisioned staff member's first
 *      Microsoft sign-in gets connected.
 *   3. Otherwise, refuse — an admin must create the user first
 *      (Settings > Users).
 *
 * Returns the user row on success, or null if no matching account exists.
 */
function find_or_link_ms_user(array $claims): ?array
{
    $oid = (string)($claims['oid'] ?? '');
    $email = strtolower((string)($claims['email'] ?? $claims['preferred_username'] ?? ''));
    if ($oid === '') {
        return null;
    }

    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, name, username, email, role, active, auth_provider FROM users WHERE ms_oid = ? LIMIT 1');
    $stmt->execute([$oid]);
    $user = $stmt->fetch();
    if ($user) {
        return (int)$user['active'] ? $user : null;
    }

    if ($email === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, username, email, role, active, auth_provider FROM users WHERE LOWER(email) = ? AND ms_oid IS NULL LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['active']) {
        return null;
    }

    $link = $pdo->prepare('UPDATE users SET ms_oid = ? WHERE id = ?');
    $link->execute([$oid, $user['id']]);
    return $user;
}

/** For api/*.php endpoints that are admin-only: 403 JSON error otherwise. */
function require_admin_api(): array
{
    require_once __DIR__ . '/response.php';
    $user = require_login_api();
    if ($user['role'] !== 'admin') {
        json_error('Administrator access required.', 403);
    }
    return $user;
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Require a valid CSRF token on state-changing API requests. Checks the
 *  X-CSRF-Token header first, falling back to a csrf_token body field. */
function require_csrf(): void
{
    require_once __DIR__ . '/response.php';
    start_secure_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (json_body()['csrf_token'] ?? '');
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        json_error('Invalid or missing CSRF token.', 403);
    }
}
