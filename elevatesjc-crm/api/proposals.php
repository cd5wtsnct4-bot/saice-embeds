<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login_api();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

const PROPOSAL_STATUSES = ['draft', 'sent', 'accepted', 'declined'];

const PROPOSAL_SELECT = '
    SELECT p.*, c.name AS contact_name, c.company AS contact_company, d.title AS deal_title,
           COALESCE((SELECT SUM(quantity * unit_price) FROM proposal_items WHERE proposal_id = p.id), 0) AS total
    FROM proposals p
    LEFT JOIN contacts c ON c.id = p.contact_id
    LEFT JOIN deals d ON d.id = p.deal_id
';

function fetch_proposal_items(PDO $pdo, int $proposalId): array
{
    $stmt = $pdo->prepare('SELECT id, description, quantity, unit_price, sort_order FROM proposal_items WHERE proposal_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$proposalId]);
    return $stmt->fetchAll();
}

function save_proposal_items(PDO $pdo, int $proposalId, array $items): void
{
    $pdo->prepare('DELETE FROM proposal_items WHERE proposal_id = ?')->execute([$proposalId]);
    $stmt = $pdo->prepare('INSERT INTO proposal_items (proposal_id, description, quantity, unit_price, sort_order) VALUES (?,?,?,?,?)');
    $order = 0;
    foreach ($items as $item) {
        $desc = trim((string)($item['description'] ?? ''));
        if ($desc === '') continue;
        $stmt->execute([$proposalId, $desc, (float)($item['quantity'] ?? 1), (float)($item['unit_price'] ?? 0), $order++]);
    }
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(PROPOSAL_SELECT . ' WHERE p.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Proposal not found.', 404);
        $row['items'] = fetch_proposal_items($pdo, (int)$row['id']);
        json_out($row);
    }
    $stmt = $pdo->query(PROPOSAL_SELECT . ' ORDER BY p.created_at DESC LIMIT 500');
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    require_csrf();
    $b = json_body();
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') json_error('Title is required.', 422);
    $items = is_array($b['items'] ?? null) ? $b['items'] : [];

    $number = next_document_number('proposal', 'PRO');
    $pdo->beginTransaction();
    try {
        $statusIn = $b['status'] ?? 'draft';
        $stmt = $pdo->prepare('INSERT INTO proposals (proposal_number, deal_id, contact_id, title, status, intro_text, valid_until) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $number,
            !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
            !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
            $title,
            in_array($statusIn, PROPOSAL_STATUSES, true) ? $statusIn : 'draft',
            $b['intro_text'] ?? null,
            $b['valid_until'] ?: null,
        ]);
        $id = (int)$pdo->lastInsertId();
        save_proposal_items($pdo, $id, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Could not save the proposal.', 500);
    }
    json_out(['id' => $id, 'proposal_number' => $number], 201);
}

if ($method === 'PUT') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $b = json_body();

    // Status-only transition (Send / Accept / Decline buttons).
    if (isset($b['status']) && count($b) === 1) {
        if (!in_array($b['status'], PROPOSAL_STATUSES, true)) json_error('Invalid status.', 422);
        $sentAt = $b['status'] === 'sent' ? ', sent_at = NOW()' : '';
        $stmt = $pdo->prepare("UPDATE proposals SET status = ?{$sentAt} WHERE id = ?");
        $stmt->execute([$b['status'], $id]);
        json_out(['ok' => true]);
    }

    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') json_error('Title is required.', 422);
    $items = is_array($b['items'] ?? null) ? $b['items'] : [];

    $pdo->beginTransaction();
    try {
        $statusIn = $b['status'] ?? 'draft';
        $stmt = $pdo->prepare('UPDATE proposals SET deal_id=?, contact_id=?, title=?, status=?, intro_text=?, valid_until=? WHERE id=?');
        $stmt->execute([
            !empty($b['deal_id']) ? (int)$b['deal_id'] : null,
            !empty($b['contact_id']) ? (int)$b['contact_id'] : null,
            $title,
            in_array($statusIn, PROPOSAL_STATUSES, true) ? $statusIn : 'draft',
            $b['intro_text'] ?? null,
            $b['valid_until'] ?: null,
            $id,
        ]);
        save_proposal_items($pdo, $id, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Could not save the proposal.', 500);
    }
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id.', 422);
    $stmt = $pdo->prepare('DELETE FROM proposals WHERE id=?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}

json_error('Method not allowed.', 405);
