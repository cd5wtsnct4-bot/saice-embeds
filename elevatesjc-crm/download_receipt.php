<?php
/**
 * Gated receipt image viewer. uploads/receipts/ itself denies direct web
 * access (see uploads/.htaccess) — this is the only way to view a scanned
 * slip, and it requires a logged-in session plus a valid expense id (never
 * a raw filesystem path from the client).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/uploads.php';

if (!current_user()) {
    http_response_code(401);
    exit('Not authenticated.');
}

$expenseId = (int)($_GET['expense_id'] ?? 0);
if (!$expenseId) {
    http_response_code(400);
    exit('Missing expense_id.');
}

$stmt = db()->prepare('SELECT receipt_path FROM expenses WHERE id = ?');
$stmt->execute([$expenseId]);
$row = $stmt->fetch();
if (!$row || !$row['receipt_path']) {
    http_response_code(404);
    exit('No receipt on file for this expense.');
}

$abs = resolve_receipt_path($row['receipt_path']);
if (!$abs || !is_file($abs)) {
    http_response_code(404);
    exit('Receipt file is missing.');
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($abs);
