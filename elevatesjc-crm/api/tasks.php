<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const TASK_SELECT = '
    SELECT t.*, c.name AS contact_name, d.title AS deal_title
    FROM tasks t
    LEFT JOIN contacts c ON c.id = t.contact_id
    LEFT JOIN deals d ON d.id = t.deal_id
';

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(TASK_SELECT . ' WHERE t.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Task not found.', 404);
        json_out($row);
    }
    $stmt = $pdo->query(TASK_SELECT . ' ORDER BY t.done ASC, t.due_date IS NULL, t.due_date ASC LIMIT 1000');
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') json_error('Title is required.', 422);
    $priorityIn = $b['priority'] ?? 'medium';
    $priority = in_array($priorityIn, ['low', 'medium', 'high'], true) ? $priorityIn : 'medium';

    $stmt = $pdo->prepare('INSERT INTO tasks (title, due_date, priority, contact_id, deal_id, notes) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $title,
        $b['due_date'] ?: null,
        $priority,
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        $b['notes'] ?? null,
    ]);
    json_out(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();

    // Toggle-done shortcut.
    if (isset($b['done']) && count($b) === 1) {
        $stmt = $pdo->prepare('UPDATE tasks SET done=? WHERE id=?');
        $stmt->execute([$b['done'] ? 1 : 0, $id]);
        json_out(['ok' => true]);
    }

    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') json_error('Title is required.', 422);
    $priorityIn = $b['priority'] ?? 'medium';
    $priority = in_array($priorityIn, ['low', 'medium', 'high'], true) ? $priorityIn : 'medium';

    $stmt = $pdo->prepare('UPDATE tasks SET title=?, due_date=?, priority=?, done=?, contact_id=?, deal_id=?, notes=? WHERE id=?');
    $stmt->execute([
        $title,
        $b['due_date'] ?: null,
        $priority,
        !empty($b['done']) ? 1 : 0,
        !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        $b['notes'] ?? null,
        $id,
    ]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $stmt = $pdo->prepare('DELETE FROM tasks WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
