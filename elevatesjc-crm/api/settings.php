<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const ALLOWED_SETTINGS = [
    'company_name', 'tagline', 'primary_color', 'accent_color', 'accent_color_2',
    'company_address', 'company_phone', 'company_email', 'vat_number',
    'default_tax_rate', 'bank_name', 'bank_account_holder', 'bank_account_number', 'bank_branch_code',
    'template_style', 'proposal_footer_note', 'invoice_footer_note',
];
// company_logo is intentionally excluded — it's only ever written by
// api/settings_logo.php (a real file upload, not a JSON string field).

if ($method === 'GET') {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    json_out($out);
}

if ($method === 'PUT' || $method === 'POST') {
    require_admin_api();
    require_csrf();
    $b = json_body();
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach (ALLOWED_SETTINGS as $key) {
        if (array_key_exists($key, $b)) {
            $stmt->execute([$key, (string)$b[$key]]);
        }
    }
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
