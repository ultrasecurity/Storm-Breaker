<?php
/**
 * api/devices.php — operator-facing device-info index.
 *
 *   GET  api/devices.php                            → list every DEVICE event
 *   POST api/devices.php  action=delete  id=…       → drop one event by DB id
 *   POST api/devices.php  action=delete_all slug=…  → drop every DEVICE event for slug
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
    $rows = store_event_list(null, 'device');
    $items = [];
    foreach ($rows as $row) {
        $p = $row['payload'];
        $items[] = [
            'id'         => $row['id'],
            'target'     => $row['target_slug'],
            'ts'         => $row['captured_at'],
            'user_agent' => $p['user_agent'] ?? '',
            'platform'   => $p['platform'] ?? '',
            'languages'  => $p['languages'] ?? [],
            'cookies_on' => $p['cookies_on'] ?? null,
            'do_not_track'=> $p['do_not_track'] ?? null,
            'online'     => $p['online'] ?? null,
            'screen'     => $p['screen'] ?? null,
            'viewport'   => $p['viewport'] ?? null,
            'timezone'   => $p['timezone'] ?? null,
            'timezone_off'=> $p['timezone_off'] ?? null,
            'referrer'   => $p['referrer'] ?? null,
            'page_url'   => $p['page_url'] ?? null,
            'hw_cores'   => $p['hardware_cores'] ?? null,
            'device_mem' => $p['device_memory'] ?? null,
            'connection' => $p['connection'] ?? null,
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
        $deleted = store_event_delete_by_kind($slug, 'device');
        reply(200, ['ok' => true, 'deleted' => $deleted]);
    }

    reply(400, ['ok' => false, 'error' => 'Unknown action.']);
}

reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
