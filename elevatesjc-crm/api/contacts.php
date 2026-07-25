<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Contact not found.', 404);
        json_out($row);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare('SELECT * FROM contacts WHERE name LIKE ? OR company LIKE ? OR email LIKE ? OR tags LIKE ? ORDER BY name ASC LIMIT 500');
        $stmt->execute([$like, $like, $like, $like]);
    } else {
        $stmt = $pdo->query('SELECT * FROM contacts ORDER BY name ASC LIMIT 500');
    }
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') json_error('Name is required.', 422);

    $stmt = $pdo->prepare('INSERT INTO contacts (name, company, role, email, phone, tags, notes) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $name,
        $b['company'] ?? null,
        $b['role'] ?? null,
        $b['email'] ?? null,
        $b['phone'] ?? null,
        $b['tags'] ?? null,
        $b['notes'] ?? null,
    ]);
    json_out(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') json_error('Name is required.', 422);

    $stmt = $pdo->prepare('UPDATE contacts SET name=?, company=?, role=?, email=?, phone=?, tags=?, notes=? WHERE id=?');
    $stmt->execute([
        $name,
        $b['company'] ?? null,
        $b['role'] ?? null,
        $b['email'] ?? null,
        $b['phone'] ?? null,
        $b['tags'] ?? null,
        $b['notes'] ?? null,
        $id,
    ]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $stmt = $pdo->prepare('DELETE FROM contacts WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
