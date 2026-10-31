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

echo "Storm-Breaker — local development server\n";
echo "URL:       http://{$host}:{$port}\n";
echo "Docroot:   {$docroot}\n";
echo "Stop:      Ctrl+C\n\n";

$router = $docroot . DIRECTORY_SEPARATOR . 'router.php';
if (!is_file($router)) {
    fwrite(STDERR, "router.php not found at: {$router}\n");
    exit(1);
}

$cmd = sprintf(
    'php -S %s:%d -t %s %s',
    escapeshellarg($host),
    $port,
    escapeshellarg($docroot),
    escapeshellarg($router)
);

passthru($cmd, $code);
exit($code);
