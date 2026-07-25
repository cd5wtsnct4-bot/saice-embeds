<?php
/**
 * Kicks off the "Connect Microsoft Calendar" consent redirect for the
 * currently logged-in CRM user. Distinct from ms_login.php: this requires
 * an existing CRM session (it links a calendar to *this* account, it does
 * not authenticate) and requests Calendars.ReadWrite + offline_access.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graph_calendar.php';

$user = require_login_page();

if (!ms_calendar_sync_enabled()) {
    http_response_code(404);
    exit('Microsoft calendar sync is not configured.');
}

start_secure_session();
$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));
$_SESSION['ms_cal_oauth_state'] = $state;
$_SESSION['ms_cal_oauth_nonce'] = $nonce;
$_SESSION['ms_cal_oauth_user_id'] = $user['id'];

header('Location: ' . graph_calendar_authorize_url($state, $nonce));
exit;
