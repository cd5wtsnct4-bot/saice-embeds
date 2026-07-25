<?php
/**
 * Background sync for every connected Microsoft calendar, independent of
 * anyone having the Calendar tab open. Run this on a schedule (e.g. cPanel
 * Cron Jobs, every 15 minutes) so "show all calendars" stays fresh for
 * teammates who haven't opened the CRM recently.
 *
 * CLI:  php cron/sync_calendars.php <CRM_CRON_SECRET>
 * HTTP: https://your-domain/elevatesjc-crm/cron/sync_calendars.php?secret=<CRM_CRON_SECRET>
 *       (HTTP access requires an https:// URL an attacker can't easily
 *       stumble onto; the secret is the actual protection — keep it long
 *       and out of source control, e.g. in the CRM_CRON_SECRET env var)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/calendar_sync.php';

$isCli = PHP_SAPI === 'cli';
$providedSecret = $isCli ? ($argv[1] ?? '') : (string)($_GET['secret'] ?? '');

if (CRM_CRON_SECRET === '' || !hash_equals(CRM_CRON_SECRET, $providedSecret)) {
    if (!$isCli) {
        http_response_code(403);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'Not authorized.']);
    exit(1);
}

if (!ms_calendar_sync_enabled()) {
    echo json_encode(['error' => 'Microsoft calendar sync is not configured.']);
    exit(1);
}

$pdo = db();
$connections = $pdo->query('SELECT * FROM ms_calendar_connections')->fetchAll();
$totals = ['connections' => count($connections), 'pulled' => 0, 'pushed' => 0, 'errors' => []];

foreach ($connections as $connection) {
    $r = sync_connection($pdo, $connection);
    $totals['pulled'] += $r['pulled'];
    $totals['pushed'] += $r['pushed'];
    foreach ($r['errors'] as $err) {
        $totals['errors'][] = "[{$connection['ms_email']}] {$err}";
    }
}

if (!$isCli) header('Content-Type: application/json');
echo json_encode($totals, JSON_PRETTY_PRINT);
