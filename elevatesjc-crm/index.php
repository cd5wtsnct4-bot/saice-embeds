<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/response.php';

$user = require_login_page();
$pdo = db();

$settingsRows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$companyName = $settings['company_name'] ?? 'Elevate SJC';
$tagline = $settings['tagline'] ?? '';
$primaryColor = $settings['primary_color'] ?? '#142850';
$accentColor = $settings['accent_color'] ?? '#16C79A';
$initial = strtoupper(substr($companyName, 0, 1));

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= htmlspecialchars($companyName) ?> CRM</title>
<link rel="stylesheet" href="css/styles.css"/>
<style>:root{--brand-primary:<?= htmlspecialchars($primaryColor) ?>;--brand-accent:<?= htmlspecialchars($accentColor) ?>;}</style>
</head>
<body>
<div id="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark"><?= htmlspecialchars($initial) ?></div>
      <div class="brand-text"><strong><?= htmlspecialchars($companyName) ?></strong><span>CRM</span></div>
    </div>
    <nav class="nav" id="nav">
      <a class="nav-item" data-route="dashboard" href="#/dashboard"><span class="nav-ic">📊</span>Dashboard</a>
      <a class="nav-item" data-route="contacts" href="#/contacts"><span class="nav-ic">👤</span>Contacts</a>
      <a class="nav-item" data-route="deals" href="#/deals"><span class="nav-ic">💼</span>Pipeline</a>
      <a class="nav-item" data-route="calendar" href="#/calendar"><span class="nav-ic">📅</span>Calendar</a>
      <a class="nav-item" data-route="tasks" href="#/tasks"><span class="nav-ic">✅</span>Tasks</a>
      <a class="nav-item" data-route="proposals" href="#/proposals"><span class="nav-ic">📝</span>Proposals</a>
      <a class="nav-item" data-route="invoices" href="#/invoices"><span class="nav-ic">🧾</span>Invoicing</a>
      <a class="nav-item" data-route="expenses" href="#/expenses"><span class="nav-ic">🧮</span>Expenses</a>
      <a class="nav-item" data-route="programs" href="#/programs"><span class="nav-ic">🎓</span>Programs</a>
      <?php if ($user['role'] === 'admin'): ?>
      <a class="nav-item" data-route="users" href="#/users"><span class="nav-ic">🔐</span>Users</a>
      <?php endif; ?>
      <a class="nav-item" data-route="settings" href="#/settings"><span class="nav-ic">⚙️</span>Settings</a>
    </nav>
    <div class="sidebar-foot"><?= htmlspecialchars($tagline) ?></div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:10px">
        <button class="menu-btn" id="menuBtn">☰</button>
        <h1 id="pageTitle">Dashboard</h1>
      </div>
      <div class="topbar-actions">
        <span style="font-size:.78rem;color:var(--text-muted)">Signed in as <strong style="color:var(--text)"><?= htmlspecialchars($user['name']) ?></strong></span>
        <a href="logout.php" class="btn btn-outline btn-sm">Log out</a>
      </div>
    </div>
    <div class="content" id="content"><div class="empty">Loading…</div></div>
  </div>
</div>

<div class="overlay" id="modalOverlay"><div class="modal" id="modalBody"></div></div>
<div id="toast"></div>

<script>
window.CRM = {
  csrfToken: <?= json_encode($csrf) ?>,
  currentUser: <?= json_encode(['id' => (int)$user['id'], 'name' => $user['name'], 'role' => $user['role']]) ?>,
  apiBase: 'api/'
};
</script>
<script src="js/app.js"></script>
</body>
</html>
