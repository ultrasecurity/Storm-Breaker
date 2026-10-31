<?php
// login.php — sign-in page. Verifies credentials via auth_login() and
// redirects to panel.php on success.

require_once __DIR__ . '/auth.php';
auth_boot();

if (auth_user() !== null) {
    header('Location: panel.php');
    exit;
}

$error_message = null;
$submitted_user = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_user = trim($_POST['username'] ?? '');
    $password       = $_POST['password'] ?? '';

    if (auth_login($submitted_user, $password)) {
        header('Location: panel.php');
        exit;
    }
    $error_message = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Storm Breaker — Sign in</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./assets/css/cypher-ui.css">
</head>
<body>

<?php include "./assets/components/icons.php"; ?>

<div class="login-shell">
  <div class="login-bg"></div>

  <div class="login-card">
    <div class="login-brand">
      <div class="login-brand-mark">
        <svg class="icon"><use href="#i-shield-check"/></svg>
      </div>
      <div>
        <div class="login-title">STORM BREAKER</div>
      </div>
    </div>
    <div class="login-sub">// authorized access only</div>

    <form method="POST" class="login-form" autocomplete="off">
      <div class="input-group">
        <label class="input-label" for="login-user">Username</label>
        <div class="input-wrap">
          <span class="input-icon-left"><svg class="icon icon-sm"><use href="#i-user"/></svg></span>
          <input id="login-user" class="input has-icon" type="text" name="username"
                 placeholder="admin" required autofocus
                 value="<?= htmlspecialchars($submitted_user) ?>">
        </div>
      </div>

      <div class="input-group">
        <label class="input-label" for="login-pass">Password</label>
        <div class="input-wrap">
          <span class="input-icon-left"><svg class="icon icon-sm"><use href="#i-lock"/></svg></span>
          <input id="login-pass" class="input has-icon" type="password" name="password"
                 placeholder="••••••••" required>
        </div>
      </div>

      <?php if ($error_message): ?>
        <div class="login-error">
          <svg class="icon icon-sm"><use href="#i-alert-octagon"/></svg>
          <?= htmlspecialchars($error_message) ?>
        </div>
      <?php endif; ?>

      <button class="btn btn-primary btn-lg" type="submit" style="margin-top:var(--space-2)">
        <svg class="icon icon-sm"><use href="#i-key"/></svg>
        Sign in
      </button>
    </form>

    <div class="login-footer">
      <div>v3.0</div>
      <div class="status"><span class="dot"></span> Ready</div>
    </div>
  </div>
</div>

</body>
</html>
