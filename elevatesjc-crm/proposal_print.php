<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/print_template.php';

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
$templateStyle = print_template_style($s);

function money_ph($n) { return 'R ' . number_format((float)$n, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= htmlspecialchars($proposal['proposal_number']) ?> — <?= htmlspecialchars($proposal['title']) ?></title>
<style>
  :root{--brand-primary:<?= htmlspecialchars($s['primary_color'] ?? '#142850') ?>;--brand-accent:<?= htmlspecialchars($s['accent_color'] ?? '#16C79A') ?>;}
  <?= print_template_css($templateStyle) ?>
</style>
</head>
<body class="tpl-<?= htmlspecialchars($templateStyle) ?>">
  <?= render_letterhead($s) ?>

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
        <td data-label="Description"><?= htmlspecialchars($item['description']) ?></td>
        <td data-label="Qty"><?= rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') ?></td>
        <td data-label="Unit Price"><?= money_ph($item['unit_price']) ?></td>
        <td data-label="Line Total" style="text-align:right"><?= money_ph($item['quantity'] * $item['unit_price']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="4" style="text-align:center;color:#5A6B87">No line items.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="3">Total</td><td style="text-align:right"><?= money_ph($total) ?></td></tr></tfoot>
  </table>

  <?= render_footer_note($s['proposal_footer_note'] ?? null) ?>

  <div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button></div>
</body>
</html>
