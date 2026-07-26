<?php
/**
 * Handles the redirect back from Microsoft after the user signs in.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/msal_lite.php';

start_secure_session();

function ms_fail(string $reason): void
{
    header('Location: ../login.php?ms_error=' . rawurlencode($reason));
    exit;
}

if (!ms_login_enabled()) {
    ms_fail('Microsoft sign-in is not configured.');
}

if (!empty($_GET['error'])) {
    ms_fail((string)($_GET['error_description'] ?? $_GET['error']));
}

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$expectedState = $_SESSION['ms_oauth_state'] ?? null;
$expectedNonce = $_SESSION['ms_oauth_nonce'] ?? null;
unset($_SESSION['ms_oauth_state'], $_SESSION['ms_oauth_nonce']);

if (!$code || !$state || !$expectedState || !hash_equals($expectedState, $state)) {
    ms_fail('Invalid or expired sign-in request. Please try again.');
}

try {
    $tokens = ms_exchange_code((string)$code);
    $claims = ms_verify_id_token($tokens['id_token'], (string)$expectedNonce);
} catch (MsAuthException $e) {
    ms_fail($e->getMessage());
}

$user = find_or_link_ms_user($claims);
if (!$user) {
    $email = $claims['email'] ?? $claims['preferred_username'] ?? 'your Microsoft account';
    ms_fail("No CRM account found for {$email}. Ask an administrator to add you under Settings > Users first.");
}

login_user_id((int)$user['id']);
header('Location: ../index.php');
exit;
