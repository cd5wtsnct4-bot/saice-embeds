<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        require_method('POST');
        require_csrf();
        $body = json_body();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($username === '' || $password === '') {
            json_error('Username and password are required.', 422);
        }

        $stmt = db()->prepare('SELECT id, name, username, password_hash, role, active FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash']) || !(int)$user['active']) {
            json_error('Invalid username or password.', 401);
        }

        login_user_id((int)$user['id']);
        json_out(['user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'],
        ], 'csrf_token' => csrf_token()]);
        break;

    case 'logout':
        require_method('POST');
        logout_user();
        json_out(['ok' => true]);
        break;

    case 'me':
        $user = current_user();
        if (!$user) {
            json_error('Not authenticated.', 401);
        }
        json_out(['user' => $user, 'csrf_token' => csrf_token()]);
        break;

    default:
        json_error('Unknown action.', 404);
}
