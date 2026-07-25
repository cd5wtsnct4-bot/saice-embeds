<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/branding.php';

require_admin_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

function current_logo_path(PDO $pdo): ?string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_logo'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== '' ? $val : null;
}

if ($method === 'POST') {
    require_csrf();
    if (empty($_FILES['logo'])) json_error('No file uploaded.', 422);
    try {
        $relativePath = store_logo_upload($_FILES['logo'], current_logo_path($pdo));
    } catch (UploadException $e) {
        json_error($e->getMessage(), 422);
    }
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_logo', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$relativePath]);
    json_out(['ok' => true, 'company_logo' => $relativePath]);
}

if ($method === 'DELETE') {
    require_csrf();
    remove_logo_file(current_logo_path($pdo));
    $pdo->prepare("DELETE FROM settings WHERE setting_key = 'company_logo'")->execute();
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
