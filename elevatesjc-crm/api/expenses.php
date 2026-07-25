<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/uploads.php';

$user = require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const EXPENSE_CATEGORIES = ['Travel', 'Venue & Catering', 'Materials', 'Software & Subscriptions', 'Subsistence', 'Other'];
const EXPENSE_PAYMENT_METHODS = ['Card', 'Cash', 'EFT', 'Other'];
const EXPENSE_STATUSES = ['pending', 'approved', 'reimbursed'];

const EXPENSE_SELECT = '
    SELECT e.*, d.title AS deal_title, u.name AS submitted_by_name
    FROM expenses e
    LEFT JOIN deals d ON d.id = e.deal_id
    LEFT JOIN users u ON u.id = e.submitted_by
';

function find_expense(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(EXPENSE_SELECT . ' WHERE e.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Expense not found.', 404);
        json_out($row);
    }

    $where = [];
    $params = [];
    if (!empty($_GET['status']) && in_array($_GET['status'], EXPENSE_STATUSES, true)) {
        $where[] = 'e.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['category']) && in_array($_GET['category'], EXPENSE_CATEGORIES, true)) {
        $where[] = 'e.category = ?';
        $params[] = $_GET['category'];
    }
    $sql = EXPENSE_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.expense_date DESC, e.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $description = trim((string)($b['description'] ?? ''));
    $amount = (float)($b['amount'] ?? 0);
    $expenseDate = trim((string)($b['expense_date'] ?? ''));
    $categoryIn = $b['category'] ?? 'Other';
    $category = in_array($categoryIn, EXPENSE_CATEGORIES, true) ? $categoryIn : 'Other';
    $paymentMethodIn = $b['payment_method'] ?? 'Card';
    $paymentMethod = in_array($paymentMethodIn, EXPENSE_PAYMENT_METHODS, true) ? $paymentMethodIn : 'Card';

    if ($description === '' || $amount <= 0 || $expenseDate === '') {
        json_error('Description, a positive amount, and an expense date are required.', 422);
    }

    // receipt_path must be one we generated via expenses_upload.php, not client-supplied.
    $receiptPath = null;
    if (!empty($b['receipt_path'])) {
        $receiptPath = resolve_receipt_path((string)$b['receipt_path']) ? $b['receipt_path'] : null;
    }

    $stmt = $pdo->prepare('INSERT INTO expenses (description, category, amount, expense_date, vendor, payment_method, notes, receipt_path, ocr_text, deal_id, submitted_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $description,
        $category,
        $amount,
        $expenseDate,
        $b['vendor'] ?? null,
        $paymentMethod,
        $b['notes'] ?? null,
        $receiptPath,
        $b['ocr_text'] ?? null,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        (int)$user['id'],
    ]);
    json_out(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $existing = find_expense($pdo, $id);
    if (!$existing) json_error('Expense not found.', 404);

    $isAdmin = $user['role'] === 'admin';
    $isOwner = (int)$existing['submitted_by'] === (int)$user['id'];
    if (!$isAdmin && !$isOwner) json_error('You can only edit your own expenses.', 403);

    $b = json_body();

    // Status-only transition — approving/reimbursing is an admin action.
    if (isset($b['status']) && count($b) === 1) {
        if (!$isAdmin) json_error('Only an administrator can change an expense status.', 403);
        if (!in_array($b['status'], EXPENSE_STATUSES, true)) json_error('Invalid status.', 422);
        $stmt = $pdo->prepare('UPDATE expenses SET status = ? WHERE id = ?');
        $stmt->execute([$b['status'], $id]);
        json_out(['ok' => true]);
    }

    if (!$isAdmin && $existing['status'] !== 'pending') {
        json_error('This expense has already been processed and can no longer be edited.', 403);
    }

    $description = trim((string)($b['description'] ?? ''));
    $amount = (float)($b['amount'] ?? 0);
    $expenseDate = trim((string)($b['expense_date'] ?? ''));
    if ($description === '' || $amount <= 0 || $expenseDate === '') {
        json_error('Description, a positive amount, and an expense date are required.', 422);
    }
    $categoryIn = $b['category'] ?? 'Other';
    $category = in_array($categoryIn, EXPENSE_CATEGORIES, true) ? $categoryIn : 'Other';
    $paymentMethodIn = $b['payment_method'] ?? 'Card';
    $paymentMethod = in_array($paymentMethodIn, EXPENSE_PAYMENT_METHODS, true) ? $paymentMethodIn : 'Card';

    $receiptPath = $existing['receipt_path'];
    if (array_key_exists('receipt_path', $b)) {
        $receiptPath = $b['receipt_path'] && resolve_receipt_path((string)$b['receipt_path']) ? $b['receipt_path'] : null;
    }

    $stmt = $pdo->prepare('UPDATE expenses SET description=?, category=?, amount=?, expense_date=?, vendor=?, payment_method=?, notes=?, receipt_path=?, deal_id=? WHERE id=?');
    $stmt->execute([
        $description, $category, $amount, $expenseDate,
        $b['vendor'] ?? null, $paymentMethod, $b['notes'] ?? null,
        $receiptPath,
        !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
        $id,
    ]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $existing = find_expense($pdo, $id);
    if (!$existing) json_error('Expense not found.', 404);

    $isAdmin = $user['role'] === 'admin';
    $isOwner = (int)$existing['submitted_by'] === (int)$user['id'];
    if (!$isAdmin && !($isOwner && $existing['status'] === 'pending')) {
        json_error('You can only delete your own pending expenses.', 403);
    }

    if ($existing['receipt_path']) {
        $abs = resolve_receipt_path($existing['receipt_path']);
        if ($abs) @unlink($abs);
    }
    $pdo->prepare('DELETE FROM expenses WHERE id=?')->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
