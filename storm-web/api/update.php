<?php
// api/update.php — version check + self-update from GitHub.
// Auth-required for update actions; version check is also auth-gated.

require_once __DIR__ . '/../auth.php';
auth_boot();
auth_require();

header('Content-Type: application/json; charset=utf-8');

$REPO_OWNER  = 'ultrasecurity';
$REPO_NAME   = 'Storm-Breaker';
$BRANCH      = 'main';
$SETTINGS_URL = "https://raw.githubusercontent.com/{$REPO_OWNER}/{$REPO_NAME}/{$BRANCH}/Settings.json";
$ZIP_URL      = "https://github.com/{$REPO_OWNER}/{$REPO_NAME}/archive/refs/heads/{$BRANCH}.zip";

$WEB_ROOT = realpath(__DIR__ . '/..');
$CACHE_FILE = $WEB_ROOT . '/data/.update-cache.json';

// Files and directories that must NEVER be overwritten during update.
$PROTECTED = [
    'config.php',
    'data',
    'targets',
    'log',
];

$action = $_GET['action'] ?? 'check';

if ($action === 'check') {
    // Return local version + remote version (cached 1h).
    $local = get_local_version();
    $remote = get_remote_version_cached();

    echo json_encode([
        'ok' => true,
        'local_version'  => $local,
        'remote_version' => $remote['version'],
        'update_available' => version_compare($remote['version'], $local, '>'),
        'checked_at' => $remote['checked_at'],
    ]);
    exit;
}

if ($action === 'apply') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }

    $local = get_local_version();
    $remote = get_remote_version_cached(true);

    if (!version_compare($remote['version'], $local, '>')) {
        echo json_encode(['ok' => false, 'error' => 'Already up to date']);
        exit;
    }

    $result = apply_update();
    echo json_encode($result);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action. Use ?action=check or ?action=apply']);
exit;

// ─── Helpers ────────────────────────────────────────────────

function get_local_version(): string {
    global $WEB_ROOT;
    $file = $WEB_ROOT . '/Settings.json';
    if (!file_exists($file)) return '0.0.0';
    $data = json_decode(file_get_contents($file), true);
    return $data['version'] ?? '0.0.0';
}

function get_remote_version_cached(bool $force = false): array {
    global $CACHE_FILE, $SETTINGS_URL;

    if (!$force && file_exists($CACHE_FILE)) {
        $cache = json_decode(file_get_contents($CACHE_FILE), true);
        if ($cache && (time() - ($cache['checked_at'] ?? 0)) < 3600) {
            return $cache;
        }
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'user_agent' => 'Storm-Breaker-Updater/1.0',
    ]]);

    $raw = @file_get_contents($SETTINGS_URL, false, $ctx);
    if ($raw === false) {
        // Can't reach GitHub — return cached or unknown.
        if (file_exists($CACHE_FILE)) {
            return json_decode(file_get_contents($CACHE_FILE), true) ?: ['version' => '0.0.0', 'checked_at' => 0];
        }
        return ['version' => '0.0.0', 'checked_at' => 0];
    }

    $data = json_decode($raw, true);
    $ver = (string) ($data['version'] ?? '0.0.0');
    // Normalize: "3" → "3.0.0", "3.0" → "3.0.0"
    $dots = substr_count($ver, '.');
    if ($dots === 0) $ver .= '.0.0';
    elseif ($dots === 1) $ver .= '.0';
    $result = [
        'version' => $ver,
        'checked_at' => time(),
    ];

    // Cache it.
    $dir = dirname($CACHE_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($CACHE_FILE, json_encode($result));

    return $result;
}

function apply_update(): array {
    global $ZIP_URL, $WEB_ROOT, $PROTECTED, $REPO_NAME, $BRANCH;

    $tmp_dir = sys_get_temp_dir() . '/sb-update-' . uniqid();
    $zip_path = $tmp_dir . '/update.zip';

    mkdir($tmp_dir, 0755, true);

    // 1. Backup current files (excluding protected dirs).
    $backup_dir = $WEB_ROOT . '/data/.update-backup-' . date('Ymd-His');
    mkdir($backup_dir, 0755, true);

    // 2. Download ZIP from GitHub.
    $ctx = stream_context_create(['http' => [
        'timeout' => 60,
        'user_agent' => 'Storm-Breaker-Updater/1.0',
    ]]);
    $zip_data = @file_get_contents($ZIP_URL, false, $ctx);
    if ($zip_data === false) {
        cleanup($tmp_dir);
        return ['ok' => false, 'error' => 'Failed to download update from GitHub'];
    }
    file_put_contents($zip_path, $zip_data);

    // 3. Extract ZIP.
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        cleanup($tmp_dir);
        return ['ok' => false, 'error' => 'Failed to open downloaded ZIP'];
    }
    $zip->extractTo($tmp_dir);
    $zip->close();

    // GitHub ZIPs extract to RepoName-branch/
    $extracted = $tmp_dir . '/' . $REPO_NAME . '-' . $BRANCH;
    $source = $extracted . '/storm-web';

    if (!is_dir($source)) {
        cleanup($tmp_dir);
        return ['ok' => false, 'error' => 'Invalid archive structure: storm-web/ not found'];
    }

    // 4. Backup current non-protected files.
    $backed_up = backup_files($WEB_ROOT, $backup_dir, $PROTECTED);

    // 5. Copy new files, skipping protected paths.
    $updated = 0;
    $errors = [];
    $copied = copy_update_files($source, $WEB_ROOT, $PROTECTED, $updated, $errors);

    // 6. Also copy root-level files (deploy.sh, run-local.php, Settings.json, README.md).
    $root_project = dirname($WEB_ROOT);
    $root_files = ['deploy.sh', 'run-local.php', 'Settings.json', 'README.md'];
    foreach ($root_files as $rf) {
        $src = $extracted . '/' . $rf;
        $dst = $root_project . '/' . $rf;
        if (file_exists($src)) {
            @copy($src, $dst);
            $updated++;
        }
    }

    // 7. Cleanup temp files.
    cleanup($tmp_dir);

    $new_version = get_local_version();

    // Clear version cache so next check picks up the new version.
    global $CACHE_FILE;
    if (file_exists($CACHE_FILE)) @unlink($CACHE_FILE);

    return [
        'ok' => count($errors) === 0,
        'updated_files' => $updated,
        'new_version' => $new_version,
        'backup_dir' => basename($backup_dir),
        'errors' => $errors,
    ];
}

function backup_files(string $source, string $backup_dir, array $protected): int {
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = ltrim(substr($item->getPathname(), strlen($source)), '/');
        if (is_protected($relative, $protected)) continue;

        $dest = $backup_dir . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest)) mkdir($dest, 0755, true);
        } else {
            $dir = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            copy($item->getPathname(), $dest);
            $count++;
        }
    }
    return $count;
}

function copy_update_files(string $source, string $dest, array $protected, int &$count, array &$errors): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = ltrim(substr($item->getPathname(), strlen($source)), '/');
        if (is_protected($relative, $protected)) continue;

        $target = $dest . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } else {
            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (@copy($item->getPathname(), $target)) {
                $count++;
            } else {
                $errors[] = "Failed to copy: {$relative}";
            }
        }
    }
}

function is_protected(string $path, array $protected): bool {
    foreach ($protected as $p) {
        if ($path === $p || strpos($path, $p . '/') === 0) {
            return true;
        }
    }
    return false;
}

function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
