<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/print_template.php';

require_login_page();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT i.*, c.name AS contact_name, c.company AS contact_company, c.email AS contact_email
    FROM invoices i LEFT JOIN contacts c ON c.id = i.contact_id
    WHERE i.id = ?
');
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

$itemsStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$subtotal = array_sum(array_map(fn($i) => (float)$i['quantity'] * (float)$i['unit_price'], $items));
$taxAmount = round($subtotal * ((float)$invoice['tax_rate'] / 100), 2);
$total = round($subtotal + $taxAmount, 2);

$settingsRows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
$s = [];
foreach ($settingsRows as $row) { $s[$row['setting_key']] = $row['setting_value']; }
$templateStyle = print_template_style($s);

function money_ih($n) { return 'R ' . number_format((float)$n, 2); }
$isOverdue = $invoice['status'] === 'sent' && $invoice['due_date'] && $invoice['due_date'] < date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= htmlspecialchars($invoice['invoice_number']) ?></title>
<style>
  :root{--brand-primary:<?= htmlspecialchars($s['primary_color'] ?? '#142850') ?>;--brand-accent:<?= htmlspecialchars($s['accent_color'] ?? '#16C79A') ?>;}
  <?= print_template_css($templateStyle) ?>
  .pill{background:<?= $isOverdue ? '#FDECEA' : '#E9EEF7' ?>;color:<?= $isOverdue ? '#E85C4A' : 'var(--brand-primary)' ?>}
</style>
</head>
<body class="tpl-<?= htmlspecialchars($templateStyle) ?>">
  <?= render_letterhead($s) ?>

  <div class="doc-title">Invoice <?= htmlspecialchars($invoice['invoice_number']) ?></div>
  <span class="pill"><?= $isOverdue ? 'overdue' : htmlspecialchars($invoice['status']) ?></span>

  <div class="grid2">
    <div>
      <strong>Billed to</strong><br/>
      <?= htmlspecialchars($invoice['contact_name'] ?? '—') ?><br/>
      <?= htmlspecialchars($invoice['contact_company'] ?? '') ?><br/>
      <?= htmlspecialchars($invoice['contact_email'] ?? '') ?>
    </div>
    <div style="text-align:right">
      <strong>Details</strong><br/>
      Issue date: <?= htmlspecialchars(date('d M Y', strtotime($invoice['issue_date']))) ?><br/>
      <?php if ($invoice['due_date']): ?>Due date: <?= htmlspecialchars(date('d M Y', strtotime($invoice['due_date']))) ?><br/><?php endif; ?>
      <?php if ($invoice['paid_at']): ?>Paid: <?= htmlspecialchars(date('d M Y', strtotime($invoice['paid_at']))) ?><?php endif; ?>
    </div>
  </div>

  <table>
    <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th style="text-align:right">Line Total</th></tr></thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td data-label="Description"><?= htmlspecialchars($item['description']) ?></td>
        <td data-label="Qty"><?= rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') ?></td>
        <td data-label="Unit Price"><?= money_ih($item['unit_price']) ?></td>
        <td data-label="Line Total" style="text-align:right"><?= money_ih($item['quantity'] * $item['unit_price']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="4" style="text-align:center;color:#5A6B87">No line items.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div class="totals">
    <div><span>Subtotal</span><span><?= money_ih($subtotal) ?></span></div>
    <div><span>VAT (<?= htmlspecialchars(rtrim(rtrim(number_format((float)$invoice['tax_rate'], 2), '0'), '.')) ?>%)</span><span><?= money_ih($taxAmount) ?></span></div>
    <div class="grand"><span>Total Due</span><span><?= money_ih($total) ?></span></div>
  </div>

  <?php if (!empty($s['bank_name']) || !empty($s['bank_account_number'])): ?>
  <div class="bank-box">
    <strong>Banking Details</strong><br/>
    <?= htmlspecialchars($s['bank_account_holder'] ?? '') ?><br/>
    <?= htmlspecialchars($s['bank_name'] ?? '') ?> · Acc: <?= htmlspecialchars($s['bank_account_number'] ?? '') ?> · Branch Code: <?= htmlspecialchars($s['bank_branch_code'] ?? '') ?><br/>
    Reference: <?= htmlspecialchars($invoice['invoice_number']) ?>
  </div>
  <?php endif; ?>

  <?php if ($invoice['notes']): ?><p style="margin-top:20px;font-size:.82rem;color:#5A6B87"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p><?php endif; ?>

  <?= render_footer_note($s['invoice_footer_note'] ?? null) ?>

  <div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button></div>
</body>
</html>
