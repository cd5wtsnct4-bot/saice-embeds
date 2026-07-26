<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/branding.php';

require_admin_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

function current_branding_paths(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_logo', 'company_logo_icon')");
    $out = ['company_logo' => null, 'company_logo_icon' => null];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['setting_value'] !== '') $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

if ($method === 'POST') {
    require_csrf();
    if (empty($_FILES['logo'])) json_error('No file uploaded.', 422);
    $current = current_branding_paths($pdo);
    try {
        $paths = store_logo_upload($_FILES['logo'], $current['company_logo'], $current['company_logo_icon']);
    } catch (UploadException $e) {
        json_error($e->getMessage(), 422);
    }
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute(['company_logo', $paths['logo']]);
    // Icon generation is best-effort (e.g. GD lacking WebP support) — fall
    // back to the default bundled icon rather than storing an empty path.
    if ($paths['icon']) {
        $stmt->execute(['company_logo_icon', $paths['icon']]);
    } else {
        $pdo->prepare("DELETE FROM settings WHERE setting_key = 'company_logo_icon'")->execute();
    }
    json_out(['ok' => true, 'company_logo' => $paths['logo'], 'company_logo_icon' => $paths['icon']]);
}

if ($method === 'DELETE') {
    require_csrf();
    $current = current_branding_paths($pdo);
    remove_logo_file($current['company_logo']);
    remove_logo_file($current['company_logo_icon']);
    $pdo->prepare("DELETE FROM settings WHERE setting_key IN ('company_logo', 'company_logo_icon')")->execute();
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
