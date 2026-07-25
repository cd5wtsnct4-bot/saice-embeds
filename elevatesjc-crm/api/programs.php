<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const PROGRAM_CATEGORIES = ['Leadership Development', 'Technical Skills', 'Soft Skills', 'Data Analytics & Visualisation', 'E-Learning'];

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM programs WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Program not found.', 404);
        json_out($row);
    }
    $stmt = $pdo->query('SELECT * FROM programs ORDER BY active DESC, category ASC, name ASC');
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $name = trim((string)($b['name'] ?? ''));
    $category = (string)($b['category'] ?? '');
    if ($name === '') json_error('Name is required.', 422);
    if (!in_array($category, PROGRAM_CATEGORIES, true)) json_error('Invalid category.', 422);

    $stmt = $pdo->prepare('INSERT INTO programs (name, category, description, active) VALUES (?,?,?,?)');
    $stmt->execute([$name, $category, $b['description'] ?? null, !empty($b['active']) ? 1 : 0]);
    json_out(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();
    $name = trim((string)($b['name'] ?? ''));
    $category = (string)($b['category'] ?? '');
    if ($name === '') json_error('Name is required.', 422);
    if (!in_array($category, PROGRAM_CATEGORIES, true)) json_error('Invalid category.', 422);

    $stmt = $pdo->prepare('UPDATE programs SET name=?, category=?, description=?, active=? WHERE id=?');
    $stmt->execute([$name, $category, $b['description'] ?? null, isset($b['active']) ? (int)(bool)$b['active'] : 1, $id]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $stmt = $pdo->prepare('DELETE FROM programs WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
