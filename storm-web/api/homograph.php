<?php
/**
 * api/homograph.php — Homograph domain generation + availability check.
 *
 * GET  ?action=generate&domain=example.com   → generate suggestions
 * POST ?action=check                          → check availability (body: {"domains": [...]})
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/homograph.php';
require_once __DIR__ . '/../lib/availability.php';
auth_boot();
auth_require();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'generate';

try {
    switch ($action) {
        case 'generate':
            $domain = trim($_GET['domain'] ?? '');
            if ($domain === '') throw new RuntimeException('Domain is required');
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z]{2,})+$/i', $domain)) {
                throw new RuntimeException('Invalid domain format');
            }
            $suggestions = homograph_generate($domain);
            echo json_encode([
                'ok' => true,
                'domain' => $domain,
                'suggestions' => $suggestions,
                'count' => count($suggestions),
            ]);
            break;

        case 'check':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required');
            $body = json_decode(file_get_contents('php://input'), true);
            $domains = $body['domains'] ?? [];
            if (!is_array($domains) || empty($domains)) {
                throw new RuntimeException('domains array is required');
            }
            // Limit to 20 per request
            $domains = array_slice($domains, 0, 20);
            $results = availability_check($domains);
            echo json_encode(['ok' => true, 'results' => $results]);
            break;

        default:
            throw new RuntimeException('Unknown action: ' . $action);
    }
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
