<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/calendar_sync.php';

$user = require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const EVENT_SELECT = '
    SELECT e.*, c.name AS contact_name, d.title AS deal_title,
           mc.color AS calendar_color, mc.display_name AS calendar_label, mc.ms_email AS calendar_email
    FROM calendar_events e
    LEFT JOIN contacts c ON c.id = e.contact_id
    LEFT JOIN deals d ON d.id = e.deal_id
    LEFT JOIN ms_calendar_connections mc ON mc.id = e.connection_id
';

/** Load a connection the current user owns (you can only file events into your own connected calendar). */
function load_own_connection_for_event(PDO $pdo, $connectionId, array $user): ?array
{
    if (empty($connectionId)) return null;
    $stmt = $pdo->prepare('SELECT * FROM ms_calendar_connections WHERE id = ?');
    $stmt->execute([(int)$connectionId]);
    $connection = $stmt->fetch();
    if (!$connection) json_error('Calendar connection not found.', 404);
    if ((int)$connection['user_id'] !== (int)$user['id']) {
        json_error('You can only create events on a Microsoft calendar you connected yourself.', 403);
    }
    return $connection;
}

/** Push a create/update to Graph for a linked event. Returns [msEventId, msLastModified] or null on failure (caller marks sync_pending). */
function push_event_to_graph(PDO $pdo, array $connection, ?string $existingMsEventId, array $row): ?array
{
    try {
        $accessToken = graph_valid_access_token($pdo, $connection);
        $payload = graph_event_payload($row['title'], $row['description'], $row['start_datetime'], $row['end_datetime'], $row['all_day'], $row['location']);
        $g = $existingMsEventId
            ? graph_update_event($accessToken, $existingMsEventId, $payload)
            : graph_create_event($accessToken, $payload);
        $fields = graph_event_to_crm_fields($g);
        return [$fields['ms_event_id'], $fields['ms_last_modified']];
    } catch (Exception $e) {
        error_log('Calendar push failed: ' . $e->getMessage());
        return null;
    }
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(EVENT_SELECT . ' WHERE e.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Event not found.', 404);
        json_out($row);
    }

    // Range query for the visible calendar grid, e.g. ?start=2026-07-01&end=2026-08-11.
    // Matches on overlap, not just start_datetime, so a multi-day booking that
    // started before the visible range but runs into it still shows up — the
    // front end then places it on every day it spans (see eventDayRange() in app.js).
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;
    if ($start && $end) {
        $stmt = $pdo->prepare(EVENT_SELECT . ' WHERE e.start_datetime <= ? AND COALESCE(e.end_datetime, e.start_datetime) >= ? ORDER BY e.start_datetime ASC');
        $stmt->execute([$end . ' 23:59:59', $start . ' 00:00:00']);
    } else {
        $stmt = $pdo->query(EVENT_SELECT . ' ORDER BY e.start_datetime ASC LIMIT 500');
    }
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $title = trim((string)($b['title'] ?? ''));
    $start = trim((string)($b['start_datetime'] ?? ''));
    if ($title === '' || $start === '') json_error('Title and start date/time are required.', 422);

    $connection = load_own_connection_for_event($pdo, $b['connection_id'] ?? null, $user);
    $row = [
        'title' => $title,
        'description' => $b['description'] ?? null,
        'start_datetime' => $start,
        'end_datetime' => ($b['end_datetime'] ?? '') ?: null,
        'all_day' => !empty($b['all_day']),
        'location' => $b['location'] ?? null,
    ];

    $syncPending = 0;
    $msEventId = null;
    $msLastModified = null;
    if ($connection) {
        $pushed = push_event_to_graph($pdo, $connection, null, $row);
        if ($pushed) [$msEventId, $msLastModified] = $pushed;
        else $syncPending = 1;
    }

    $stmt = $pdo->prepare('INSERT INTO calendar_events (title, description, start_datetime, end_datetime, all_day, location, contact_id, deal_id, connection_id, ms_event_id, ms_last_modified, sync_pending, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $title,
        $row['description'],
        $start,
        $row['end_datetime'],
        $row['all_day'] ? 1 : 0,
        $row['location'],
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        $connection['id'] ?? null,
        $msEventId,
        $msLastModified,
        $syncPending,
        (int)$user['id'],
    ]);
    $out = ['id' => (int)$pdo->lastInsertId()];
    if ($syncPending) $out['warning'] = 'Saved locally, but could not sync to Outlook yet — it will retry automatically.';
    json_out($out, 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();
    $title = trim((string)($b['title'] ?? ''));
    $start = trim((string)($b['start_datetime'] ?? ''));
    if ($title === '' || $start === '') json_error('Title and start date/time are required.', 422);

    $existingStmt = $pdo->prepare('SELECT * FROM calendar_events WHERE id = ?');
    $existingStmt->execute([$id]);
    $existing = $existingStmt->fetch();
    if (!$existing) json_error('Event not found.', 404);

    $row = [
        'title' => $title,
        'description' => $b['description'] ?? null,
        'start_datetime' => $start,
        'end_datetime' => ($b['end_datetime'] ?? '') ?: null,
        'all_day' => !empty($b['all_day']),
        'location' => $b['location'] ?? null,
    ];

    $syncPending = 0;
    $msEventId = $existing['ms_event_id'];
    $msLastModified = $existing['ms_last_modified'];
    if ($existing['connection_id']) {
        // The connected calendar is fixed at creation time — a linked event stays on that calendar.
        $connStmt = $pdo->prepare('SELECT * FROM ms_calendar_connections WHERE id = ?');
        $connStmt->execute([$existing['connection_id']]);
        $connection = $connStmt->fetch();
        $pushed = $connection ? push_event_to_graph($pdo, $connection, $existing['ms_event_id'], $row) : null;
        if ($pushed) [$msEventId, $msLastModified] = $pushed;
        else $syncPending = 1;
    }

    $stmt = $pdo->prepare('UPDATE calendar_events SET title=?, description=?, start_datetime=?, end_datetime=?, all_day=?, location=?, contact_id=?, deal_id=?, ms_event_id=?, ms_last_modified=?, sync_pending=? WHERE id=?');
    $stmt->execute([
        $title,
        $row['description'],
        $start,
        $row['end_datetime'],
        $row['all_day'] ? 1 : 0,
        $row['location'],
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        $msEventId,
        $msLastModified,
        $syncPending,
        $id,
    ]);
    $out = ['ok' => true];
    if ($syncPending) $out['warning'] = 'Saved locally, but could not sync to Outlook yet — it will retry automatically.';
    json_out($out);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);

    $stmt = $pdo->prepare('SELECT * FROM calendar_events WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if ($existing && $existing['connection_id'] && $existing['ms_event_id']) {
        $connStmt = $pdo->prepare('SELECT * FROM ms_calendar_connections WHERE id = ?');
        $connStmt->execute([$existing['connection_id']]);
        $connection = $connStmt->fetch();
        if ($connection) {
            try {
                $accessToken = graph_valid_access_token($pdo, $connection);
                graph_delete_event($accessToken, $existing['ms_event_id']);
            } catch (Exception $e) {
                error_log('Calendar delete push failed: ' . $e->getMessage());
            }
        }
    }

    $pdo->prepare('DELETE FROM calendar_events WHERE id=?')->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
