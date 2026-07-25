<?php
require_once __DIR__ . '/includes/auth.php';

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

function money_ih($n) { return 'R ' . number_format((float)$n, 2); }
$isOverdue = $invoice['status'] === 'sent' && $invoice['due_date'] && $invoice['due_date'] < date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title><?= htmlspecialchars($invoice['invoice_number']) ?></title>
<style>
  :root{--brand-primary:<?= htmlspecialchars($s['primary_color'] ?? '#142850') ?>;--brand-accent:<?= htmlspecialchars($s['accent_color'] ?? '#16C79A') ?>;}
  *{box-sizing:border-box}
  body{font-family:'Segoe UI',system-ui,sans-serif;color:#1A2540;max-width:760px;margin:0 auto;padding:40px 30px}
  .letterhead{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:4px solid var(--brand-accent);padding-bottom:18px;margin-bottom:26px}
  .letterhead h1{color:var(--brand-primary);font-size:1.4rem;margin-bottom:2px}
  .letterhead .meta{text-align:right;font-size:.8rem;color:#5A6B87;line-height:1.5}
  .doc-title{font-size:1.1rem;font-weight:800;color:var(--brand-primary);margin-bottom:4px}
  .pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:capitalize;background:<?= $isOverdue ? '#FDECEA' : '#E9EEF7' ?>;color:<?= $isOverdue ? '#E85C4A' : 'var(--brand-primary)' ?>}
  .grid2{display:flex;justify-content:space-between;gap:24px;margin:20px 0;font-size:.84rem}
  table{width:100%;border-collapse:collapse;margin-top:10px;font-size:.86rem}
  th{text-align:left;background:#F4F6FA;padding:10px;font-size:.72rem;text-transform:uppercase;color:#5A6B87}
  td{padding:10px;border-bottom:1px solid #E2E8F0}
  .totals{width:280px;margin-left:auto;margin-top:12px;font-size:.88rem}
  .totals div{display:flex;justify-content:space-between;padding:6px 0}
  .totals .grand{font-weight:800;color:var(--brand-primary);border-top:2px solid var(--brand-primary);font-size:1rem;padding-top:10px}
  .bank-box{margin-top:30px;background:#F4F6FA;border-radius:10px;padding:16px 18px;font-size:.82rem}
  .bank-box strong{color:var(--brand-primary)}
  .print-bar{margin-top:30px;text-align:center}
  .print-bar button{padding:10px 20px;border-radius:8px;border:none;background:var(--brand-accent);font-weight:700;cursor:pointer}
  @media print{.print-bar{display:none}}
</style>
</head>
<body>
  <div class="letterhead">
    <div>
      <h1><?= htmlspecialchars($s['company_name'] ?? 'Elevate SJC') ?></h1>
      <div style="font-size:.78rem;color:#5A6B87"><?= htmlspecialchars($s['tagline'] ?? '') ?></div>
    </div>
    <div class="meta">
      <?= htmlspecialchars($s['company_address'] ?? '') ?><br/>
      <?= htmlspecialchars($s['company_phone'] ?? '') ?> · <?= htmlspecialchars($s['company_email'] ?? '') ?>
      <?php if (!empty($s['vat_number'])): ?><br/>VAT No: <?= htmlspecialchars($s['vat_number']) ?><?php endif; ?>
    </div>
  </div>

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
        <td><?= htmlspecialchars($item['description']) ?></td>
        <td><?= rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') ?></td>
        <td><?= money_ih($item['unit_price']) ?></td>
        <td style="text-align:right"><?= money_ih($item['quantity'] * $item['unit_price']) ?></td>
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

  <div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button></div>
</body>
</html>
