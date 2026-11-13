#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "run-local.php must be run from the command line.\n");
    exit(1);
}

$host    = $argv[1] ?? 'localhost';
$port    = (int)($argv[2] ?? 2525);
$docroot = __DIR__ . DIRECTORY_SEPARATOR . 'storm-web';

if (!is_dir($docroot)) {
    fwrite(STDERR, "storm-web directory not found at: {$docroot}\n");
    exit(1);
}

if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "Invalid port: {$port}\n");
    exit(1);
}

// Preflight: verify writable directories exist and are accessible.
// After a full deploy.sh run these are owned by the service user
// (stormbreaker), so a regular user can't write to them.
$writable_dirs = ['data', 'targets', 'log'];
$issues = [];
foreach ($writable_dirs as $name) {
    $dir = $docroot . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        $issues[] = "  {$name}/  — cannot create directory";
    } elseif (!is_writable($dir)) {
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dir))['name'] ?? '?') : '?';
        $issues[] = "  {$name}/  — not writable (owned by {$owner})";
    }
}
if (!empty($issues)) {
    fwrite(STDERR, "WARNING: some directories are not writable by the current user:\n");
    foreach ($issues as $line) {
        fwrite(STDERR, $line . "\n");
    }
    fwrite(STDERR, "\nThis usually happens after running deploy.sh (which locks\n");
    fwrite(STDERR, "permissions for the systemd service user). Fix with:\n\n");
    fwrite(STDERR, "  sudo chown -R \$(whoami) storm-web/data storm-web/targets storm-web/log\n\n");
}

// Check that config.php exists (needed for login).
$config = $docroot . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($config)) {
    fwrite(STDERR, "NOTE: storm-web/config.php not found — you'll need to\n");
    fwrite(STDERR, "run deploy.sh first, or create it manually.\n\n");
} elseif (!is_readable($config)) {
    fwrite(STDERR, "WARNING: storm-web/config.php is not readable by the current user.\n");
    fwrite(STDERR, "Fix with:  sudo chmod o+r storm-web/config.php\n\n");
}

echo "Storm-Breaker — local development server\n";
echo "URL:       http://{$host}:{$port}\n";
echo "Docroot:   {$docroot}\n";
echo "Stop:      Ctrl+C\n\n";

$router = $docroot . DIRECTORY_SEPARATOR . 'router.php';
if (!is_file($router)) {
    fwrite(STDERR, "router.php not found at: {$router}\n");
    exit(1);
}

// -d display_errors=stderr  → PHP warnings go to the terminal, not into
//    HTTP response bodies (which would corrupt JSON API responses).
// -d log_errors=1           → errors are always logged.
$cmd = sprintf(
    'php -d display_errors=stderr -d log_errors=1 -S %s:%d -t %s %s',
    escapeshellarg($host),
    $port,
    escapeshellarg($docroot),
    escapeshellarg($router)
);

passthru($cmd, $code);
exit($code);
