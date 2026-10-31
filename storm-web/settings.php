<?php
// settings.php — POST endpoint to update the signed-in user's credentials.
// New username + password (no confirm field). Password is bcrypt-hashed
// before being persisted to config.php via auth_save_credentials().

require_once __DIR__ . '/auth.php';
auth_boot();

header('Content-Type: application/json');

function reply(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (auth_user() === null) {
    reply(401, ['ok' => false, 'error' => 'Not authenticated.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || strlen($username) < 3 || strlen($username) > 32) {
    reply(400, ['ok' => false, 'error' => 'Username must be 3–32 characters.']);
}
if (!preg_match('/^[A-Za-z0-9_-]+$/', $username)) {
    reply(400, ['ok' => false, 'error' => 'Username may only contain letters, digits, "_" or "-".']);
}
if (strlen($password) < 8) {
    reply(400, ['ok' => false, 'error' => 'Password must be at least 8 characters.']);
}
if (strlen($password) > 256) {
    reply(400, ['ok' => false, 'error' => 'Password too long.']);
}

if (!auth_save_credentials($username, $password)) {
    reply(500, ['ok' => false, 'error' => 'Failed to write config file. Check permissions on storm-web/config.php.']);
}

// Reflect the (possibly new) username in the active session.
$_SESSION[AUTH_SESSION_KEY] = $username;

reply(200, ['ok' => true, 'username' => $username]);
