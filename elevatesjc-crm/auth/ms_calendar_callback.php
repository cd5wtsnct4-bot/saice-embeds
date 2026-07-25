<?php
/**
 * Handles the redirect back from Microsoft after granting calendar
 * consent, and stores the encrypted tokens against the CRM user who
 * started the flow (see ms_calendar_connect.php).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graph_calendar.php';

start_secure_session();

$PALETTE = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2'];

function ms_cal_fail(string $reason): void
{
    header('Location: ../index.php?ms_cal_error=' . rawurlencode($reason) . '#/settings');
    exit;
}

if (!ms_calendar_sync_enabled()) {
    ms_cal_fail('Microsoft calendar sync is not configured.');
}

$sessionUserId = $_SESSION['ms_cal_oauth_user_id'] ?? null;
$user = current_user();
if (!$user || !$sessionUserId || (int)$user['id'] !== (int)$sessionUserId) {
    ms_cal_fail('Your session expired before Microsoft calendar sign-in completed. Please try again.');
}

if (!empty($_GET['error'])) {
    ms_cal_fail((string)($_GET['error_description'] ?? $_GET['error']));
}

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$expectedState = $_SESSION['ms_cal_oauth_state'] ?? null;
$expectedNonce = $_SESSION['ms_cal_oauth_nonce'] ?? null;
unset($_SESSION['ms_cal_oauth_state'], $_SESSION['ms_cal_oauth_nonce'], $_SESSION['ms_cal_oauth_user_id']);

if (!$code || !$state || !$expectedState || !hash_equals($expectedState, $state)) {
    ms_cal_fail('Invalid or expired sign-in request. Please try again.');
}

try {
    $tokens = graph_exchange_calendar_code((string)$code);
    if (!empty($tokens['id_token'])) {
        ms_verify_id_token($tokens['id_token'], (string)$expectedNonce);
    }
    $profile = graph_fetch_profile($tokens['access_token']);
} catch (MsAuthException | GraphException $e) {
    ms_cal_fail($e->getMessage());
}

$msOid = (string)($profile['id'] ?? '');
$msEmail = (string)($profile['mail'] ?? $profile['userPrincipalName'] ?? '');
$displayName = (string)($profile['displayName'] ?? '');
if ($msOid === '' || empty($tokens['refresh_token'])) {
    ms_cal_fail('Microsoft did not grant offline calendar access. Please try connecting again and accept all requested permissions.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM ms_calendar_connections WHERE user_id = ? AND ms_oid = ? LIMIT 1');
$stmt->execute([$user['id'], $msOid]);
$existing = $stmt->fetch();

$expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? 3600));
$accessEnc = token_encrypt($tokens['access_token']);
$refreshEnc = token_encrypt($tokens['refresh_token']);

if ($existing) {
    $upd = $pdo->prepare('UPDATE ms_calendar_connections SET ms_email=?, display_name=?, access_token_enc=?, refresh_token_enc=?, token_expires_at=?, last_sync_error=NULL WHERE id=?');
    $upd->execute([$msEmail, $displayName, $accessEnc, $refreshEnc, $expiresAt, $existing['id']]);
} else {
    $countStmt = $pdo->query('SELECT COUNT(*) FROM ms_calendar_connections');
    $color = $PALETTE[$countStmt->fetchColumn() % count($PALETTE)];
    $ins = $pdo->prepare('INSERT INTO ms_calendar_connections (user_id, ms_oid, ms_email, display_name, color, access_token_enc, refresh_token_enc, token_expires_at) VALUES (?,?,?,?,?,?,?,?)');
    $ins->execute([$user['id'], $msOid, $msEmail, $displayName, $color, $accessEnc, $refreshEnc, $expiresAt]);
}

header('Location: ../index.php?ms_cal_connected=1#/settings');
exit;
