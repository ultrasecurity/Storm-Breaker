<?php
/**
 * lib/cred-verifier.php — Determines if a login attempt succeeded or failed.
 *
 * Signal priority chain (matches Creature Login):
 *   401/403/422                                → failure (definite)
 *   3xx Location carries error query params    → failure
 *   3xx Location points back to login page     → failure
 *   3xx Location to a DIFFERENT path           → success
 *   204 + session cookie                       → success (htmx pattern)
 *   JSON ok:false / success:false / errors[]   → failure
 *   JSON ok:true / success:true / token        → success
 *   2xx + fresh session cookie                 → success
 *   HTML re-renders login form + error phrase  → failure
 *   nothing matched                             → unknown
 */

function credverifier_verdict(int $status, array $headers, string $body, string $url): array {
    $signals = [];

    $location   = null;
    $set_cookies = [];
    foreach ($headers as $k => $v) {
        $lk = strtolower((string) $k);
        if ($lk === 'location')   $location = is_array($v) ? $v[0] : (string) $v;
        if ($lk === 'set-cookie') {
            $cookies = is_array($v) ? $v : [$v];
            foreach ($cookies as $c) $set_cookies[] = $c;
        }
    }

    $has_session = credverifier_has_session_cookie($set_cookies);
    $req_path = parse_url($url, PHP_URL_PATH) ?: '/';
    $ct = strtolower((string) ($headers['content-type'] ?? $headers['Content-Type'] ?? ''));

    // 1. Hard failure: auth-denied status codes
    if (in_array($status, [401, 403, 422], true)) {
        return credverifier_make('failure', "HTTP {$status}", ['status' => $status]);
    }

    // 2. Redirect analysis
    if ($status >= 300 && $status < 400 && $location !== null) {
        $loc_path  = parse_url($location, PHP_URL_PATH) ?? $location;
        $loc_query = parse_url($location, PHP_URL_QUERY) ?? '';
        $signals['redirect_to'] = $location;

        if ($loc_query !== '' && preg_match(
            '/(^|&)(error|err|failed|fail|invalid|reason|msg)[^&]*=[^&]+/i',
            $loc_query
        )) {
            return credverifier_make('failure', 'redirect carries error query', $signals);
        }

        if (credverifier_paths_same($loc_path, $req_path)) {
            return credverifier_make('failure', 'redirect back to login page', $signals);
        }

        if (preg_match('#(/login|/signin|/sign-in|/auth/login)(/|$|\?)#i', (string) $loc_path)) {
            return credverifier_make('failure', 'redirect target looks like login', $signals);
        }

        return credverifier_make('success', 'redirect to authenticated path', $signals);
    }

    // 3. 204 + session cookie → success (htmx)
    if ($status === 204 && $has_session) {
        $signals['set_cookie_names'] = credverifier_cookie_names($set_cookies);
        return credverifier_make('success', '204 with session cookie', $signals);
    }

    // 4. JSON body analysis
    $looks_json = str_contains($ct, 'json')
        || (strlen($body) > 0 && ($body[0] === '{' || $body[0] === '['));

    if ($looks_json) {
        $data = json_decode($body, true);
        if (is_array($data)) {
            $flags = credverifier_scan_json($data);
            $signals = array_merge($signals, $flags);

            if ($flags['saw_ok_false'] || $flags['saw_error']) {
                $why = $flags['saw_ok_false']
                    ? 'JSON ok/success=false'
                    : 'JSON error/errors present';
                return credverifier_make('failure', $why, $signals);
            }
            if ($flags['saw_ok_true']) {
                return credverifier_make('success', 'JSON ok/success=true', $signals);
            }
            if ($flags['saw_token']) {
                return credverifier_make('success', 'JSON contains auth token', $signals);
            }
        }
    }

    // 5. 2xx + fresh session cookie → success
    if ($status >= 200 && $status < 300 && $has_session) {
        $signals['set_cookie_names'] = credverifier_cookie_names($set_cookies);
        return credverifier_make('success', '2xx with fresh session cookie', $signals);
    }

    // 6. HTML: login form re-rendered with error → failure
    if (str_contains($ct, 'html') && $status >= 200 && $status < 300) {
        if (credverifier_html_looks_failed($body)) {
            $signals['html_failed_login'] = true;
            return credverifier_make('failure', 'HTML re-renders login with error', $signals);
        }
    }

    return credverifier_make('unknown', 'no conclusive signal', $signals);
}

function credverifier_make(string $verdict, string $why, array $signals): array {
    return ['verdict' => $verdict, 'why' => $why, 'signals' => $signals];
}

function credverifier_paths_same(string $a, string $b): bool {
    $na = rtrim($a, '/');
    $nb = rtrim($b, '/');
    return $na !== '' && $na === $nb;
}

function credverifier_has_session_cookie(array $set_cookies): bool {
    $hints = [
        'session', 'sessid', 'sid', 'auth', 'token', 'jwt',
        'remember', 'login', 'user', 'laravel_session', 'phpsessid',
        'connect.sid', 'express.sid', 'asp.net_sessionid',
    ];
    foreach ($set_cookies as $c) {
        $first = trim(explode(';', $c)[0] ?? '');
        if (!str_contains($first, '=')) continue;
        [$name, $value] = explode('=', $first, 2);
        $name  = strtolower(trim($name));
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'deleted') continue;
        if (preg_match('/expires=([^;]+)/i', $c, $m)) {
            $ts = strtotime(trim($m[1]));
            if ($ts !== false && $ts < time() - 60) continue;
        }
        foreach ($hints as $hint) {
            if (str_contains($name, $hint)) return true;
        }
    }
    return false;
}

function credverifier_cookie_names(array $set_cookies): array {
    $names = [];
    foreach ($set_cookies as $c) {
        $first = trim(explode(';', $c)[0] ?? '');
        if (!str_contains($first, '=')) continue;
        [$name] = explode('=', $first, 2);
        $names[] = trim($name);
    }
    return $names;
}

function credverifier_scan_json(array $data): array {
    $flags = ['saw_ok_true' => false, 'saw_ok_false' => false, 'saw_token' => false, 'saw_error' => false];
    credverifier_walk_json($data, $flags);
    return $flags;
}

function credverifier_walk_json(array $arr, array &$flags): void {
    $ok_keys    = ['ok', 'success', 'authenticated', 'logged_in', 'isauthenticated'];
    $token_keys = ['token', 'access_token', 'accesstoken', 'jwt', 'id_token', 'bearer'];
    $err_keys   = ['error', 'errors', 'err', 'message', 'detail', 'reason', 'fault'];

    foreach ($arr as $k => $v) {
        $lk = is_string($k) ? strtolower($k) : '';

        if (is_array($v)) {
            if ($lk === 'errors' && !empty($v)) $flags['saw_error'] = true;
            credverifier_walk_json($v, $flags);
            continue;
        }

        if (in_array($lk, $ok_keys, true)) {
            if ($v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true'))
                $flags['saw_ok_true'] = true;
            if ($v === false || $v === 0 || $v === '0' || (is_string($v) && strtolower($v) === 'false'))
                $flags['saw_ok_false'] = true;
        }
        if (in_array($lk, $token_keys, true)) {
            if (is_string($v) && strlen($v) >= 8) $flags['saw_token'] = true;
        }
        if (in_array($lk, $err_keys, true)) {
            if ($v === null || $v === '' || $v === false) continue;
            if (in_array($lk, ['message', 'detail', 'reason', 'fault'], true)) {
                if (is_string($v) && preg_match(
                    '/invalid|incorrect|wrong|failed|unauthorized|denied|forbidden|bad\s*credentials|not\s*found/i',
                    $v
                )) {
                    $flags['saw_error'] = true;
                }
                continue;
            }
            $flags['saw_error'] = true;
        }
    }
}

function credverifier_html_looks_failed(string $html): bool {
    if (!preg_match('/<input[^>]+type=[\"\']?password[\"\']?/i', $html)) return false;
    $err_rx = '/invalid|incorrect|wrong|failed|unauthorized|denied|forbidden|bad\s*credentials|'
            . 'اشتباه|نادرست|نامعتبر|خطا|غلط|مجاز\s*نیست/iu';
    return (bool) preg_match($err_rx, $html);
}
