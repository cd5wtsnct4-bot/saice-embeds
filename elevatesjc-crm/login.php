<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/msal_lite.php';

// already logged in? skip straight to the app
if (current_user()) {
    header('Location: index.php');
    exit;
}

$settingsRows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$companyName = $settings['company_name'] ?? 'Elevate SJC';
$tagline = $settings['tagline'] ?? 'Driving performance. Unlocking potential.';
$primaryColor = $settings['primary_color'] ?? '#142850';
$accentColor = $settings['accent_color'] ?? '#16C79A';
$logoPath = $settings['company_logo'] ?? null;
$initial = strtoupper(substr($companyName, 0, 1));
$touchIcon = $settings['company_logo_icon'] ?? 'assets/icons/icon-180.png';

$csrf = csrf_token();
$msEnabled = ms_login_enabled();
$msError = isset($_GET['ms_error']) ? (string)$_GET['ms_error'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover"/>
<title>Sign in — <?= htmlspecialchars($companyName) ?> CRM</title>
<link rel="stylesheet" href="css/styles.css"/>
<link rel="manifest" href="manifest.php"/>
<link rel="apple-touch-icon" href="<?= htmlspecialchars($touchIcon) ?>"/>
<link rel="icon" href="assets/icons/icon-32.png" sizes="32x32"/>
<meta name="theme-color" content="<?= htmlspecialchars($primaryColor) ?>"/>
<meta name="mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($companyName) ?>"/>
<style>:root{--brand-primary:<?= htmlspecialchars($primaryColor) ?>;--brand-accent:<?= htmlspecialchars($accentColor) ?>;}</style>
</head>
<body>
<div class="login-screen">
  <div class="login-card">
    <?php if ($logoPath): ?>
    <img class="brand-mark brand-logo" src="<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars($companyName) ?> logo"/>
    <?php else: ?>
    <div class="brand-mark"><?= htmlspecialchars($initial) ?></div>
    <?php endif; ?>
    <h1><?= htmlspecialchars($companyName) ?> CRM</h1>
    <p><?= htmlspecialchars($tagline) ?></p>

    <div class="login-err" id="loginErr"><?= $msError !== '' ? htmlspecialchars($msError) : '' ?></div>

    <form id="loginForm">
      <input type="text" name="username" id="username" placeholder="Username" autocomplete="username" required/>
      <input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" required/>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px">Sign in</button>
    </form>

    <?php if ($msEnabled): ?>
    <div style="display:flex;align-items:center;gap:10px;margin:16px 0;color:var(--text-muted);font-size:.7rem">
      <div style="flex:1;height:1px;background:var(--border)"></div>OR<div style="flex:1;height:1px;background:var(--border)"></div>
    </div>
    <a href="auth/ms_login.php" class="btn btn-outline" style="width:100%;justify-content:center;padding:11px;text-decoration:none">
      <svg width="16" height="16" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
      Sign in with Microsoft
    </a>
    <?php endif; ?>

    <p class="login-hint">Trouble signing in? Contact your CRM administrator.</p>
  </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($csrf) ?>;
document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const errEl = document.getElementById('loginErr');
  errEl.textContent = '';
  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value;
  try {
    const res = await fetch('api/auth.php?action=login', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN},
      body: JSON.stringify({username, password}),
    });
    const data = await res.json();
    if (!res.ok) { errEl.textContent = data.error || 'Sign in failed.'; return; }
    window.location.href = 'index.php';
  } catch (err) {
    errEl.textContent = 'Network error — please try again.';
  }
});
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}
</script>
</body>
</html>
