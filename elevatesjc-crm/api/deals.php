<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const DEAL_STAGES = ['New Enquiry', 'Needs Assessment', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];

const DEAL_SELECT = '
    SELECT d.*, c.name AS contact_name, c.company AS contact_company, p.name AS program_name
    FROM deals d
    LEFT JOIN contacts c ON c.id = d.contact_id
    LEFT JOIN programs p ON p.id = d.program_id
';

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(DEAL_SELECT . ' WHERE d.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Deal not found.', 404);
        json_out($row);
    }
    $stmt = $pdo->query(DEAL_SELECT . ' ORDER BY d.updated_at DESC LIMIT 1000');
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $title = trim((string)($b['title'] ?? ''));
    $stage = (string)($b['stage'] ?? 'New Enquiry');
    if ($title === '') json_error('Title is required.', 422);
    if (!in_array($stage, DEAL_STAGES, true)) json_error('Invalid stage.', 422);

    $stmt = $pdo->prepare('INSERT INTO deals (title, contact_id, program_id, value, stage, expected_close, notes) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $title,
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['program_id']) ? (int)$b['program_id'] : null,
        (float)($b['value'] ?? 0),
        $stage,
        $b['expected_close'] ?: null,
        $b['notes'] ?? null,
    ]);
    json_out(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();

    // Stage-only drag/drop update.
    if (isset($b['stage']) && count($b) === 1) {
        if (!in_array($b['stage'], DEAL_STAGES, true)) json_error('Invalid stage.', 422);
        $stmt = $pdo->prepare('UPDATE deals SET stage=? WHERE id=?');
        $stmt->execute([$b['stage'], $id]);
        json_out(['ok' => true]);
    }

    $title = trim((string)($b['title'] ?? ''));
    $stage = (string)($b['stage'] ?? 'New Enquiry');
    if ($title === '') json_error('Title is required.', 422);
    if (!in_array($stage, DEAL_STAGES, true)) json_error('Invalid stage.', 422);

    $stmt = $pdo->prepare('UPDATE deals SET title=?, contact_id=?, program_id=?, value=?, stage=?, expected_close=?, notes=? WHERE id=?');
    $stmt->execute([
        $title,
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['program_id']) ? (int)$b['program_id'] : null,
        (float)($b['value'] ?? 0),
        $stage,
        $b['expected_close'] ?: null,
        $b['notes'] ?? null,
        $id,
    ]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $stmt = $pdo->prepare('DELETE FROM deals WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
