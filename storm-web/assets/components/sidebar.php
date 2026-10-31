<?php
// sidebar.php — left navigation with collapsible groups.
// Caller sets $active_route and $sidebar_badges before include.
$active = $active_route ?? 'home';
$badges = $sidebar_badges ?? [];

// Group definitions — each group has a label, icon, and child routes.
$groups = [
    'phishing' => [
        'label' => 'Phishing',
        'icon'  => 'i-target',
        'items' => [
            'targets'   => ['label' => 'Targets',   'icon' => 'i-target', 'url' => 'panel.php?route=targets'],
            'homograph' => ['label' => 'Homograph',  'icon' => 'i-globe',  'url' => 'panel.php?route=homograph'],
        ],
    ],
    'harvest' => [
        'label' => 'Harvest',
        'icon'  => 'i-key',
        'items' => [
            'credentials' => ['label' => 'Credentials', 'icon' => 'i-key',  'url' => 'panel.php?route=credentials'],
            'sessions'    => ['label' => 'Sessions',    'icon' => 'i-lock', 'url' => 'panel.php?route=sessions'],
        ],
    ],
    'recon' => [
        'label' => 'Recon',
        'icon'  => 'i-compass',
        'items' => [
            'captures' => ['label' => 'Captures', 'icon' => 'i-camera',  'url' => 'panel.php?route=captures'],
            'audio'    => ['label' => 'Audio',    'icon' => 'i-mic',     'url' => 'panel.php?route=audio'],
            'geo'      => ['label' => 'Geo Data', 'icon' => 'i-map-pin', 'url' => 'panel.php?route=geo'],
        ],
    ],
];

// Top-level standalone items
$standalone = [
    'home'    => ['label' => 'Home',    'icon' => 'i-home', 'url' => 'panel.php'],
    'devices' => ['label' => 'Devices', 'icon' => 'i-cpu',  'url' => 'panel.php?route=devices'],
];

// Helper: check if the active route lives inside a group.
function group_has_active(array $group, string $active): bool {
    return isset($group['items'][$active]);
}

// Helper: sum badges for a group's children.
function group_badge_total(array $group, array $badges): int {
    $total = 0;
    foreach ($group['items'] as $key => $_) {
        $total += $badges[$key] ?? 0;
    }
    return $total;
}
?>
<aside class="panel-sidebar" id="panelSidebar">
  <a href="panel.php" class="sidebar-brand">
    <span class="brand-mark"><svg class="icon icon-md"><use href="#i-shield-check"/></svg></span>
    <span class="brand-name">STORM<span>BREAKER</span></span>
  </a>

  <nav class="sidebar-nav">
    <!-- Home (standalone) -->
    <a href="<?= $standalone['home']['url'] ?>"
       class="panel-sidebar-link<?= $active === 'home' ? ' active' : '' ?>">
      <svg class="icon panel-sidebar-icon"><use href="#<?= $standalone['home']['icon'] ?>"/></svg>
      <span><?= $standalone['home']['label'] ?></span>
    </a>

    <!-- Collapsible groups -->
    <?php foreach ($groups as $gkey => $group):
      $is_active = group_has_active($group, $active);
      $group_badges = group_badge_total($group, $badges);
    ?>
    <div class="sidebar-group<?= $is_active ? ' open' : '' ?>" data-group="<?= $gkey ?>">
      <button type="button" class="sidebar-group-toggle<?= $is_active ? ' active' : '' ?>"
              onclick="toggleSidebarGroup('<?= $gkey ?>')" aria-expanded="<?= $is_active ? 'true' : 'false' ?>">
        <svg class="icon panel-sidebar-icon"><use href="#<?= $group['icon'] ?>"/></svg>
        <span><?= $group['label'] ?></span>
        <?php if ($group_badges > 0): ?>
          <span class="sidebar-badge sidebar-group-badge"><?= $group_badges > 99 ? '99+' : $group_badges ?></span>
        <?php endif; ?>
        <svg class="icon sidebar-chevron"><use href="#i-chevron-down"/></svg>
      </button>
      <div class="sidebar-group-items">
        <div class="sidebar-group-inner">
          <?php foreach ($group['items'] as $key => $item): ?>
          <a href="<?= htmlspecialchars($item['url']) ?>"
             class="panel-sidebar-link sidebar-sub-link<?= $active === $key ? ' active' : '' ?>">
            <svg class="icon panel-sidebar-icon"><use href="#<?= $item['icon'] ?>"/></svg>
            <span><?= htmlspecialchars($item['label']) ?></span>
            <?php if (!empty($badges[$key]) && $badges[$key] > 0): ?>
              <span class="sidebar-badge"><?= $badges[$key] > 99 ? '99+' : $badges[$key] ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php if ($gkey === 'phishing'): ?>
    <!-- Devices (after Phishing) -->
    <a href="<?= $standalone['devices']['url'] ?>"
       class="panel-sidebar-link<?= $active === 'devices' ? ' active' : '' ?>">
      <svg class="icon panel-sidebar-icon"><use href="#<?= $standalone['devices']['icon'] ?>"/></svg>
      <span><?= $standalone['devices']['label'] ?></span>
      <?php if (!empty($badges['devices']) && $badges['devices'] > 0): ?>
        <span class="sidebar-badge"><?= $badges['devices'] > 99 ? '99+' : $badges['devices'] ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="panel-sidebar-bottom">
    <a href="logout.php" class="panel-sidebar-link danger">
      <svg class="icon panel-sidebar-icon"><use href="#i-log-out"/></svg>
      <span>Log out</span>
    </a>
    <button type="button" class="panel-sidebar-footer sidebar-version-btn" id="btn-version" title="Click to check for updates">
      <span class="sidebar-version-dot" data-state="idle"></span>
      Storm Breaker · <span id="sidebar-version-v">v<?= json_decode(file_get_contents(__DIR__ . '/../../Settings.json'), true)['version'] ?? '?' ?></span>
    </button>
  </div>
</aside>
<div class="panel-sidebar-scrim" id="panelSidebarScrim" onclick="toggleSidebar()"></div>

<script>
function toggleSidebarGroup(key) {
  const group = document.querySelector(`.sidebar-group[data-group="${key}"]`);
  if (!group) return;
  const isOpen = group.classList.contains('open');
  group.classList.toggle('open');
  const btn = group.querySelector('.sidebar-group-toggle');
  if (btn) btn.setAttribute('aria-expanded', !isOpen);

  // Persist state
  try {
    const state = JSON.parse(localStorage.getItem('sb_sidebar') || '{}');
    state[key] = !isOpen;
    localStorage.setItem('sb_sidebar', JSON.stringify(state));
  } catch {}
}

// Restore saved expand state (active group always opens regardless).
(function() {
  try {
    const state = JSON.parse(localStorage.getItem('sb_sidebar') || '{}');
    document.querySelectorAll('.sidebar-group').forEach(g => {
      const key = g.dataset.group;
      if (g.classList.contains('open')) return; // active group, already open
      if (state[key] === true) {
        g.classList.add('open');
        const btn = g.querySelector('.sidebar-group-toggle');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      }
    });
  } catch {}
})();
</script>
