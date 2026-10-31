<?php
/**
 * api/stream.php — Server-Sent Events (SSE) real-time event stream.
 *
 * Reads from log/events.ndjson and streams new events to the client.
 * Replaces polling for real-time credential/session notifications.
 */

require_once __DIR__ . '/../auth.php';
auth_boot();
auth_require();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$log_file = __DIR__ . '/../log/events.ndjson';

// Start from end of file (or from Last-Event-ID)
$last_id = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int) $_SERVER['HTTP_LAST_EVENT_ID'] : 0;

// If log doesn't exist, wait for it
if (!is_file($log_file)) {
    @mkdir(dirname($log_file), 0755, true);
    touch($log_file);
}

$fp = fopen($log_file, 'r');
if ($fp === false) {
    echo "event: error\ndata: {\"error\":\"Cannot open log\"}\n\n";
    exit;
}

// If no Last-Event-ID, seek to end
if ($last_id <= 0) {
    fseek($fp, 0, SEEK_END);
}

$event_id = $last_id;
$idle_count = 0;
$max_idle = 300; // 5 minutes of idle → close, client reconnects

while (true) {
    $line = fgets($fp);

    if ($line !== false && trim($line) !== '') {
        $event_id++;
        $data = json_decode(trim($line), true);
        if ($data === null) continue;

        $type = $data['type'] ?? 'event';
        echo "id: {$event_id}\n";
        echo "event: {$type}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

        if (ob_get_level()) ob_flush();
        flush();

        $idle_count = 0;
        continue;
    }

    // No new data — send keepalive every 15 seconds
    $idle_count++;
    if ($idle_count % 15 === 0) {
        echo ": keepalive\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }

    // Check if client disconnected
    if (connection_aborted()) break;

    // Max idle timeout
    if ($idle_count >= $max_idle) {
        echo "event: timeout\ndata: {\"reason\":\"idle\"}\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        break;
    }

    sleep(1);
    clearstatcache(false, $log_file);
}

fclose($fp);
