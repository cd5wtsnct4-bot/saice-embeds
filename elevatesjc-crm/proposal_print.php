<?php
require_once __DIR__ . '/includes/auth.php';

require_login_page();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT p.*, c.name AS contact_name, c.company AS contact_company, c.email AS contact_email
    FROM proposals p LEFT JOIN contacts c ON c.id = p.contact_id
    WHERE p.id = ?
');
$stmt->execute([$id]);
$proposal = $stmt->fetch();
if (!$proposal) {
    http_response_code(404);
    exit('Proposal not found.');
}

$itemsStmt = $pdo->prepare('SELECT * FROM proposal_items WHERE proposal_id = ? ORDER BY sort_order ASC, id ASC');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$total = array_sum(array_map(fn($i) => (float)$i['quantity'] * (float)$i['unit_price'], $items));

$settingsRows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
$s = [];
foreach ($settingsRows as $row) { $s[$row['setting_key']] = $row['setting_value']; }

function money_ph($n) { return 'R ' . number_format((float)$n, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title><?= htmlspecialchars($proposal['proposal_number']) ?> — <?= htmlspecialchars($proposal['title']) ?></title>
<style>
  :root{--brand-primary:<?= htmlspecialchars($s['primary_color'] ?? '#142850') ?>;--brand-accent:<?= htmlspecialchars($s['accent_color'] ?? '#16C79A') ?>;}
  *{box-sizing:border-box}
  body{font-family:'Segoe UI',system-ui,sans-serif;color:#1A2540;max-width:760px;margin:0 auto;padding:40px 30px}
  .letterhead{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:4px solid var(--brand-accent);padding-bottom:18px;margin-bottom:26px}
  .letterhead h1{color:var(--brand-primary);font-size:1.4rem;margin-bottom:2px}
  .letterhead .meta{text-align:right;font-size:.8rem;color:#5A6B87;line-height:1.5}
  .doc-title{font-size:1.1rem;font-weight:800;color:var(--brand-primary);margin-bottom:4px}
  .pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;background:#E9EEF7;color:var(--brand-primary);text-transform:capitalize}
  .grid2{display:flex;justify-content:space-between;gap:24px;margin:20px 0;font-size:.84rem}
  table{width:100%;border-collapse:collapse;margin-top:10px;font-size:.86rem}
  th{text-align:left;background:#F4F6FA;padding:10px;font-size:.72rem;text-transform:uppercase;color:#5A6B87}
  td{padding:10px;border-bottom:1px solid #E2E8F0}
  tfoot td{font-weight:800;color:var(--brand-primary);border-top:2px solid var(--brand-primary);border-bottom:none}
  .intro{margin:16px 0;line-height:1.6;font-size:.88rem;white-space:pre-wrap}
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

  <div class="doc-title">Proposal <?= htmlspecialchars($proposal['proposal_number']) ?></div>
  <span class="pill"><?= htmlspecialchars($proposal['status']) ?></span>

  <div class="grid2">
    <div>
      <strong>Prepared for</strong><br/>
      <?= htmlspecialchars($proposal['contact_name'] ?? '—') ?><br/>
      <?= htmlspecialchars($proposal['contact_company'] ?? '') ?><br/>
      <?= htmlspecialchars($proposal['contact_email'] ?? '') ?>
    </div>
    <div style="text-align:right">
      <strong>Details</strong><br/>
      Date: <?= htmlspecialchars(date('d M Y', strtotime($proposal['created_at']))) ?><br/>
      <?php if ($proposal['valid_until']): ?>Valid until: <?= htmlspecialchars(date('d M Y', strtotime($proposal['valid_until']))) ?><?php endif; ?>
    </div>
  </div>

  <h2 style="font-size:1rem;color:var(--brand-primary)"><?= htmlspecialchars($proposal['title']) ?></h2>
  <?php if ($proposal['intro_text']): ?><div class="intro"><?= nl2br(htmlspecialchars($proposal['intro_text'])) ?></div><?php endif; ?>

  <table>
    <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th style="text-align:right">Line Total</th></tr></thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td><?= htmlspecialchars($item['description']) ?></td>
        <td><?= rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') ?></td>
        <td><?= money_ph($item['unit_price']) ?></td>
        <td style="text-align:right"><?= money_ph($item['quantity'] * $item['unit_price']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="4" style="text-align:center;color:#5A6B87">No line items.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="3">Total</td><td style="text-align:right"><?= money_ph($total) ?></td></tr></tfoot>
  </table>

  <div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button></div>
</body>
</html>
