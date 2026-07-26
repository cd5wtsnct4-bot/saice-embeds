<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const INVOICE_STATUSES = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];

const INVOICE_SELECT = '
    SELECT i.*, c.name AS contact_name, c.company AS contact_company, d.title AS deal_title,
           COALESCE((SELECT SUM(quantity * unit_price) FROM invoice_items WHERE invoice_id = i.id), 0) AS subtotal
    FROM invoices i
    LEFT JOIN contacts c ON c.id = i.contact_id
    LEFT JOIN deals d ON d.id = i.deal_id
';

function fetch_invoice_items(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare('SELECT id, description, quantity, unit_price, sort_order FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

function save_invoice_items(PDO $pdo, int $invoiceId, array $items): void
{
    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
    $stmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, sort_order) VALUES (?,?,?,?,?)');
    $order = 0;
    foreach ($items as $item) {
        $desc = trim((string)($item['description'] ?? ''));
        if ($desc === '') continue;
        $stmt->execute([$invoiceId, $desc, (float)($item['quantity'] ?? 1), (float)($item['unit_price'] ?? 0), $order++]);
    }
}

function decorate_with_totals(array $row): array
{
    $row['subtotal'] = (float)$row['subtotal'];
    $row['tax_amount'] = round($row['subtotal'] * ((float)$row['tax_rate'] / 100), 2);
    $row['total'] = round($row['subtotal'] + $row['tax_amount'], 2);
    $row['is_overdue'] = $row['status'] === 'sent' && $row['due_date'] && $row['due_date'] < date('Y-m-d');
    return $row;
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(INVOICE_SELECT . ' WHERE i.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Invoice not found.', 404);
        $row = decorate_with_totals($row);
        $row['items'] = fetch_invoice_items($pdo, (int)$row['id']);
        json_out($row);
    }
    $stmt = $pdo->query(INVOICE_SELECT . ' ORDER BY i.created_at DESC LIMIT 500');
    $rows = array_map('decorate_with_totals', $stmt->fetchAll());
    json_out($rows);
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();

    // "Convert to invoice" from an accepted proposal.
    if (!empty($b['from_proposal_id'])) {
        $pStmt = $pdo->prepare('SELECT * FROM proposals WHERE id = ?');
        $pStmt->execute([(int)$b['from_proposal_id']]);
        $proposal = $pStmt->fetch();
        if (!$proposal) json_error('Source proposal not found.', 404);
        $sourceItems = fetch_invoice_items_from_proposal($pdo, (int)$proposal['id']);

        $taxRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'default_tax_rate'")->fetch();
        $taxRate = $taxRow ? (float)$taxRow['setting_value'] : 15.00;

        $number = next_document_number('invoice', 'INV');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO invoices (invoice_number, proposal_id, deal_id, contact_id, status, issue_date, due_date, tax_rate, notes) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $number, $proposal['id'], $proposal['deal_id'], $proposal['contact_id'], 'draft',
                date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), $taxRate,
                'Generated from proposal ' . $proposal['proposal_number'],
            ]);
            $id = (int)$pdo->lastInsertId();
            save_invoice_items($pdo, $id, $sourceItems);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Could not convert the proposal.', 500);
        }
        json_out(['id' => $id, 'invoice_number' => $number], 201);
    }

    $items = is_array($b['items'] ?? null) ? $b['items'] : [];
    $issueDate = $b['issue_date'] ?: date('Y-m-d');

    $number = next_document_number('invoice', 'INV');
    $pdo->beginTransaction();
    try {
        $statusIn = $b['status'] ?? 'draft';
        $stmt = $pdo->prepare('INSERT INTO invoices (invoice_number, deal_id, contact_id, status, issue_date, due_date, tax_rate, notes) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $number,
            !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
            !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
            in_array($statusIn, INVOICE_STATUSES, true) ? $statusIn : 'draft',
            $issueDate,
            $b['due_date'] ?: null,
            (float)($b['tax_rate'] ?? 15),
            $b['notes'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();
        save_invoice_items($pdo, $id, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Could not save the invoice.', 500);
    }
    json_out(['id' => $id, 'invoice_number' => $number], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();

    // Mark-paid / status-only transition.
    if (isset($b['status']) && count($b) === 1) {
        if (!in_array($b['status'], INVOICE_STATUSES, true)) json_error('Invalid status.', 422);
        $paidAt = $b['status'] === 'paid' ? ', paid_at = CURDATE()' : '';
        $stmt = $pdo->prepare("UPDATE invoices SET status = ?{$paidAt} WHERE id = ?");
        $stmt->execute([$b['status'], $id]);
        json_out(['ok' => true]);
    }

    $items = is_array($b['items'] ?? null) ? $b['items'] : [];
    $pdo->beginTransaction();
    try {
        $statusIn = $b['status'] ?? 'draft';
        $stmt = $pdo->prepare('UPDATE invoices SET deal_id=?, contact_id=?, status=?, issue_date=?, due_date=?, tax_rate=?, notes=? WHERE id=?');
        $stmt->execute([
            !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
            !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
            in_array($statusIn, INVOICE_STATUSES, true) ? $statusIn : 'draft',
            $b['issue_date'] ?: date('Y-m-d'),
            $b['due_date'] ?: null,
            (float)($b['tax_rate'] ?? 15),
            $b['notes'] ?? null,
            $id,
        ]);
        save_invoice_items($pdo, $id, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Could not save the invoice.', 500);
    }
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $stmt = $pdo->prepare('DELETE FROM invoices WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);

function fetch_invoice_items_from_proposal(PDO $pdo, int $proposalId): array
{
    $stmt = $pdo->prepare('SELECT description, quantity, unit_price FROM proposal_items WHERE proposal_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$proposalId]);
    return $stmt->fetchAll();
}
