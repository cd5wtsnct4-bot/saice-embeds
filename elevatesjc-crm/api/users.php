<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

$currentUser = require_admin_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, name, username, email, role, active, auth_provider, ms_oid IS NOT NULL AS ms_linked, created_at FROM users ORDER BY name ASC');
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $name = trim((string)($b['name'] ?? ''));
    $email = trim((string)($b['email'] ?? ''));
    $username = trim((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $roleIn = $b['role'] ?? 'user';
    $role = in_array($roleIn, ['admin', 'user'], true) ? $roleIn : 'user';

    if ($name === '') json_error('Name is required.', 422);
    if ($email === '' && $username === '') json_error('Provide at least an email (for Microsoft sign-in) or a username (for local sign-in).', 422);
    if ($username !== '' && $password === '') json_error('A password is required when setting a username.', 422);

    $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;

    try {
        $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role, auth_provider) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $name,
            $username !== '' ? $username : null,
            $email !== '' ? $email : null,
            $hash,
            $role,
            $hash ? 'local' : 'microsoft',
        ]);
    } catch (PDOException $e) {
        json_error('A user with that username or email already exists.', 409);
    }
    json_out(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();

    $name = trim((string)($b['name'] ?? ''));
    $email = trim((string)($b['email'] ?? ''));
    $roleIn = $b['role'] ?? 'user';
    $role = in_array($roleIn, ['admin', 'user'], true) ? $roleIn : 'user';
    $active = !empty($b['active']) ? 1 : 0;
    if ($name === '') json_error('Name is required.', 422);

    if ($id === (int)$currentUser['id'] && $role !== 'admin') {
        json_error('You cannot demote your own account.', 422);
    }
    if ($id === (int)$currentUser['id'] && !$active) {
        json_error('You cannot deactivate your own account.', 422);
    }

    if (!empty($b['password'])) {
        $stmt = $pdo->prepare('UPDATE users SET name=?, email=?, role=?, active=?, password_hash=? WHERE id=?');
        $stmt->execute([$name, $email !== '' ? $email : null, $role, $active, password_hash($b['password'], PASSWORD_BCRYPT), $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET name=?, email=?, role=?, active=? WHERE id=?');
        $stmt->execute([$name, $email !== '' ? $email : null, $role, $active, $id]);
    }
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    if ($id === (int)$currentUser['id']) json_error('You cannot delete your own account.', 422);
    $stmt = $pdo->prepare('DELETE FROM users WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
