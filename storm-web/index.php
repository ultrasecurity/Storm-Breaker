<?php
// index.php — entry point.
//
// Normally Apache / Nginx / `run-local.php` route every request through
// router.php, so this file is only ever hit for `/`. The conditional below
// covers the awkward case where the PHP built-in server is started WITHOUT
// router.php as its router argument (e.g. `php -S host:port -t storm-web`):
// for any URL whose file doesn't exist, PHP falls back to the nearest
// index.php. Without this defer, that fallback would silently break the
// slug routing for cloned targets and force victims through login.php.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = '/' . ltrim($path, '/');
if ($path !== '/' && $path !== '/index.php') {
    require __DIR__ . '/router.php';
    exit;
}

require_once __DIR__ . '/auth.php';
auth_boot();

header('Location: ' . (auth_user() !== null ? 'panel.php' : 'login.php'));
exit;
