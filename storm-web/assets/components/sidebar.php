<?php
// sidebar.php — left navigation partial: Home / Targets / Captures / Audio /
// Geo Data / Credentials + Log out at the bottom. Caller sets $active_route
// before include to highlight the current page, and $sidebar_badges (optional)
// for unread-count badges.
$active = $active_route ?? 'home';
$badges = $sidebar_badges ?? [];

$nav = [
    'home'        => ['label' => 'Home',        'icon' => 'i-home',    'url' => 'panel.php'],
    'targets'     => ['label' => 'Targets',     'icon' => 'i-target',  'url' => 'panel.php?route=targets'],
    'captures'    => ['label' => 'Captures',    'icon' => 'i-camera',  'url' => 'panel.php?route=captures'],
    'audio'       => ['label' => 'Audio',       'icon' => 'i-mic',     'url' => 'panel.php?route=audio'],
    'geo'         => ['label' => 'Geo Data',    'icon' => 'i-map-pin', 'url' => 'panel.php?route=geo'],
    'credentials' => ['label' => 'Credentials', 'icon' => 'i-key',    'url' => 'panel.php?route=credentials'],
    'devices'     => ['label' => 'Devices',     'icon' => 'i-cpu',    'url' => 'panel.php?route=devices'],
];
?>
<aside class="panel-sidebar" id="panelSidebar">
  <div class="panel-sidebar-section">Navigation</div>
  <?php foreach ($nav as $key => $item): ?>
    <a href="<?= htmlspecialchars($item['url']) ?>"
       class="panel-sidebar-link<?= $active === $key ? ' active' : '' ?>">
      <svg class="icon panel-sidebar-icon"><use href="#<?= htmlspecialchars($item['icon']) ?>"/></svg>
      <span><?= htmlspecialchars($item['label']) ?></span>
      <?php if (!empty($badges[$key]) && $badges[$key] > 0): ?>
        <span class="sidebar-badge"><?= $badges[$key] > 99 ? '99+' : $badges[$key] ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>

  <div class="panel-sidebar-bottom">
    <a href="logout.php" class="panel-sidebar-link danger">
      <svg class="icon panel-sidebar-icon"><use href="#i-log-out"/></svg>
      <span>Log out</span>
    </a>
    <div class="panel-sidebar-footer">Storm Breaker · v3.0</div>
  </div>
</aside>
<div class="panel-sidebar-scrim" id="panelSidebarScrim" onclick="toggleSidebar()"></div>
