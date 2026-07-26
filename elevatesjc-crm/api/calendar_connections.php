<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/calendar_sync.php';

$user = require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const CONNECTION_SELECT = '
    SELECT c.id, c.user_id, u.name AS owner_name, c.ms_email, c.display_name, c.color,
           c.last_synced_at, c.last_sync_error, c.created_at
    FROM ms_calendar_connections c
    JOIN users u ON u.id = c.user_id
';

if ($method === 'GET') {
    // Every connected calendar is returned (not just the caller's own) so the
    // shared Calendar view can render a legend/colour for the whole team.
    $rows = $pdo->query(CONNECTION_SELECT . ' ORDER BY c.created_at ASC')->fetchAll();
    foreach ($rows as &$row) {
        $row['is_mine'] = (int)$row['user_id'] === (int)$user['id'];
    }
    json_out([
        'connections' => $rows,
        'sync_enabled' => ms_calendar_sync_enabled(),
    ]);
}

function load_own_connection(PDO $pdo, int $id, array $user): array
{
    $stmt = $pdo->prepare('SELECT * FROM ms_calendar_connections WHERE id = ?');
    $stmt->execute([$id]);
    $connection = $stmt->fetch();
    if (!$connection) json_error('Connection not found.', 404);
    if ((int)$connection['user_id'] !== (int)$user['id']) {
        json_error('You can only manage calendars you connected yourself.', 403);
    }
    return $connection;
}

if ($method === 'PATCH') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    load_own_connection($pdo, $id, $user);
    $b = json_body();
    $color = trim((string)($b['color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) json_error('Color must be a hex value like #2563eb.', 422);
    $pdo->prepare('UPDATE ms_calendar_connections SET color = ? WHERE id = ?')->execute([$color, $id]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    load_own_connection($pdo, $id, $user);
    // ON DELETE SET NULL detaches any synced events rather than deleting them.
    $pdo->prepare('DELETE FROM ms_calendar_connections WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

if ($method === 'POST') {
    require_csrf();
    $action = $_GET['action'] ?? '';
    if ($action !== 'sync') json_error('Unknown action.', 422);
    if (!ms_calendar_sync_enabled()) json_error('Microsoft calendar sync is not configured.', 400);

    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $connections = [load_own_connection($pdo, $id, $user)];
    } else {
        $stmt = $pdo->prepare('SELECT * FROM ms_calendar_connections WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $connections = $stmt->fetchAll();
    }

    $summary = ['pulled' => 0, 'pushed' => 0, 'errors' => []];
    foreach ($connections as $connection) {
        $r = sync_connection($pdo, $connection);
        $summary['pulled'] += $r['pulled'];
        $summary['pushed'] += $r['pushed'];
        $summary['errors'] = array_merge($summary['errors'], $r['errors']);
    }
    json_out($summary);
}

json_error('Method not allowed.', 405);
