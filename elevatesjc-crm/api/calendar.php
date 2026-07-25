<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

$user = require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const EVENT_SELECT = '
    SELECT e.*, c.name AS contact_name, d.title AS deal_title
    FROM calendar_events e
    LEFT JOIN contacts c ON c.id = e.contact_id
    LEFT JOIN deals d ON d.id = e.deal_id
';

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(EVENT_SELECT . ' WHERE e.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Event not found.', 404);
        json_out($row);
    }

    // Range query for the visible calendar grid, e.g. ?start=2026-07-01&end=2026-08-11
    // (events are placed on the grid by their start date only — no multi-day spanning bars)
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;
    if ($start && $end) {
        $stmt = $pdo->prepare(EVENT_SELECT . ' WHERE e.start_datetime BETWEEN ? AND ? ORDER BY e.start_datetime ASC');
        $stmt->execute([$start . ' 00:00:00', $end . ' 23:59:59']);
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

    $stmt = $pdo->prepare('INSERT INTO calendar_events (title, description, start_datetime, end_datetime, all_day, location, contact_id, deal_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $title,
        $b['description'] ?? null,
        $start,
        $b['end_datetime'] ?: null,
        !empty($b['all_day']) ? 1 : 0,
        $b['location'] ?? null,
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        (int)$user['id'],
    ]);
    json_out(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();
    $title = trim((string)($b['title'] ?? ''));
    $start = trim((string)($b['start_datetime'] ?? ''));
    if ($title === '' || $start === '') json_error('Title and start date/time are required.', 422);

    $stmt = $pdo->prepare('UPDATE calendar_events SET title=?, description=?, start_datetime=?, end_datetime=?, all_day=?, location=?, contact_id=?, deal_id=? WHERE id=?');
    $stmt->execute([
        $title,
        $b['description'] ?? null,
        $start,
        $b['end_datetime'] ?: null,
        !empty($b['all_day']) ? 1 : 0,
        $b['location'] ?? null,
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        $id,
    ]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $stmt = $pdo->prepare('DELETE FROM calendar_events WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
