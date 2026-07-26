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
$accentColor2 = $settings['accent_color_2'] ?? '#F4A300';
$logoPath = $settings['company_logo'] ?? null;
$initial = strtoupper(substr($companyName, 0, 1));
$touchIcon = $settings['company_logo_icon'] ?? 'assets/icons/icon-180.png';

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover"/>
<title><?= htmlspecialchars($companyName) ?> CRM</title>
<link rel="stylesheet" href="css/styles.css"/>
<link rel="manifest" href="manifest.php"/>
<link rel="apple-touch-icon" href="<?= htmlspecialchars($touchIcon) ?>"/>
<link rel="icon" href="assets/icons/icon-32.png" sizes="32x32"/>
<meta name="theme-color" content="<?= htmlspecialchars($primaryColor) ?>"/>
<meta name="mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($companyName) ?>"/>
<style>:root{--brand-primary:<?= htmlspecialchars($primaryColor) ?>;--brand-accent:<?= htmlspecialchars($accentColor) ?>;--brand-gold:<?= htmlspecialchars($accentColor2) ?>;}</style>
</head>
<body>
<div id="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <?php if ($logoPath): ?>
      <img class="brand-mark brand-logo" src="<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars($companyName) ?> logo"/>
      <?php else: ?>
      <div class="brand-mark"><?= htmlspecialchars($initial) ?></div>
      <?php endif; ?>
      <div class="brand-text"><strong><?= htmlspecialchars($companyName) ?></strong><span>CRM</span></div>
    </div>
    <nav class="nav" id="nav">
      <a class="nav-item" data-route="dashboard" href="#/dashboard"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="10" rx="1.5"/><rect x="13" y="3" width="8" height="6" rx="1.5"/><rect x="13" y="11" width="8" height="10" rx="1.5"/><rect x="3" y="15" width="8" height="6" rx="1.5"/></svg>Dashboard</a>
      <a class="nav-item" data-route="contacts" href="#/contacts"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c0-4.1 3.4-6.5 7.5-6.5s7.5 2.4 7.5 6.5"/></svg>Contacts</a>
      <a class="nav-item" data-route="deals" href="#/deals"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 9v6l-4 2v-8L3 4z"/></svg>Pipeline</a>
      <a class="nav-item" data-route="calendar" href="#/calendar"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M8 14h1M12 14h1M16 14h1M8 17h1M12 17h1"/></svg>Calendar</a>
      <a class="nav-item" data-route="tasks" href="#/tasks"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M8 12.5l2.5 2.5L16 9"/></svg>Tasks</a>
      <a class="nav-item" data-route="proposals" href="#/proposals"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.5h9l4 4V21a1 1 0 01-1 1H6a1 1 0 01-1-1V3.5a1 1 0 011-1z"/><path d="M14.5 2.5V7h4.5"/><path d="M8 12h8M8 15.5h8M8 19h5"/></svg>Proposals</a>
      <a class="nav-item" data-route="invoices" href="#/invoices"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.5h12v18.2l-2.2-1.4-2 1.4-1.8-1.4-2 1.4-1.8-1.4-2 1.4V2.5z"/><path d="M9 8h6M9 11.5h6M9 15h4"/></svg>Invoicing</a>
      <a class="nav-item" data-route="expenses" href="#/expenses"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="6" width="19" height="13" rx="2"/><path d="M2.5 10h19"/><circle cx="17" cy="14.5" r="1.6"/></svg>Expenses</a>
      <a class="nav-item" data-route="programs" href="#/programs"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8l10-4.5L22 8l-10 4.5L2 8z"/><path d="M6 10.3V16c0 1.4 2.7 3 6 3s6-1.6 6-3v-5.7"/><path d="M22 8v6"/></svg>Programs</a>
      <?php if ($user['role'] === 'admin'): ?>
      <a class="nav-item" data-route="users" href="#/users"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5l7 3.2v5.3c0 4.7-3 8.7-7 10.5-4-1.8-7-5.8-7-10.5V5.7l7-3.2z"/><path d="M9.2 12.2l2 2 3.6-4"/></svg>Users</a>
      <?php endif; ?>
      <a class="nav-item" data-route="settings" href="#/settings"><svg class="nav-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.4M12 18.1v2.4M20.5 12h-2.4M5.9 12H3.5M17.7 6.3l-1.7 1.7M8 16l-1.7 1.7M17.7 17.7L16 16M8 8L6.3 6.3"/></svg>Settings</a>
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
        <span class="signed-in-as" style="font-size:.78rem;color:var(--text-muted)">Signed in as <strong style="color:var(--text)"><?= htmlspecialchars($user['name']) ?></strong></span>
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
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}
</script>
<script src="js/app.js"></script>
</body>
</html>
