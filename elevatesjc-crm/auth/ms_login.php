<?php
/**
 * Kicks off the Microsoft Entra ID sign-in redirect.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/msal_lite.php';

if (!ms_login_enabled()) {
    http_response_code(404);
    exit('Microsoft sign-in is not configured.');
}

start_secure_session();
$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));
$_SESSION['ms_oauth_state'] = $state;
$_SESSION['ms_oauth_nonce'] = $nonce;

header('Location: ' . ms_authorize_url($state, $nonce));
exit;
