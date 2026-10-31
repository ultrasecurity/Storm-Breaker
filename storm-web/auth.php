<?php
/**
 * auth.php — central authentication for Storm-Breaker.
 *
 * Single source of truth for sign-in / sign-out / session checks.
 * Other PHP files should `require_once 'auth.php';` and use the
 * functions below instead of touching $_SESSION / $CONFIG directly.
 *
 *   auth_boot()                     — must be called once at the top of every page
 *   auth_user()           : ?string — username if signed in, otherwise null
 *   auth_require()                  — redirects to login.php when not signed in
 *   auth_login($u, $p)    : bool    — verifies credentials & opens a session
 *   auth_logout()                   — destroys the session and clears cookies
 *   auth_save_credentials($u, $p)   — persists new credentials to config.php (hashed)
 */

const AUTH_CONFIG_PATH  = __DIR__ . '/config.php';
const AUTH_SESSION_KEY  = 'sb_user';

/** Boot session with sane cookie params. Idempotent. */
function auth_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SBSESSID');
    session_start();
}

/** Returns the signed-in username, or null. */
function auth_user(): ?string {
    return isset($_SESSION[AUTH_SESSION_KEY]) ? (string) $_SESSION[AUTH_SESSION_KEY] : null;
}

/** Bail out to login.php if not signed in. */
function auth_require(): void {
    if (auth_user() === null) {
        header('Location: login.php');
        exit;
    }
}

/** Read credentials store. Returns ['user' => ['hash' => ...]] format. */
function auth_load_config(): array {
    if (!file_exists(AUTH_CONFIG_PATH)) return [];
    /** @var array $CONFIG */
    $CONFIG = [];
    include AUTH_CONFIG_PATH;
    return is_array($CONFIG) ? $CONFIG : [];
}

/**
 * Verify credentials and start an authenticated session.
 * Transparently upgrades any legacy plaintext password to a bcrypt hash on
 * first successful login, so the migration is invisible to the user.
 */
function auth_login(string $username, string $password): bool {
    $config = auth_load_config();
    if (!isset($config[$username])) return false;

    $entry = $config[$username];
    $stored = $entry['hash'] ?? $entry['password'] ?? null;
    if ($stored === null) return false;

    $is_hash = is_string($stored) && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon'));

    if ($is_hash) {
        if (!password_verify($password, $stored)) return false;
    } else {
        // Legacy plaintext entry — accept if it matches, then upgrade.
        if (!hash_equals((string) $stored, $password)) return false;
        auth_save_credentials($username, $password);
    }

    auth_boot();
    session_regenerate_id(true);
    $_SESSION[AUTH_SESSION_KEY] = $username;
    return true;
}

/** Destroy session + clear cookies. */
function auth_logout(): void {
    auth_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Persist new credentials to config.php as a single-user hashed entry.
 * Returns true on success.
 */
function auth_save_credentials(string $username, string $password): bool {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if ($hash === false) return false;

    $php  = "<?php\n";
    $php .= "// config.php — credentials store. Managed by auth.php.\n";
    $php .= "// One entry per user. 'hash' is a bcrypt hash; never store plaintext.\n";
    $php .= "\$CONFIG = [\n";
    $php .= "    " . var_export($username, true) . " => [\n";
    $php .= "        'hash' => " . var_export($hash, true) . ",\n";
    $php .= "    ],\n";
    $php .= "];\n";

    return @file_put_contents(AUTH_CONFIG_PATH, $php, LOCK_EX) !== false;
}
