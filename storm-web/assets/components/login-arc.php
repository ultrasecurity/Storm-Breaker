<?php

define("CHECK_C_PATH", __DIR__ . "/../../check-c.json");

function read_token_data() {
    return json_decode(file_get_contents(CHECK_C_PATH), true);
}

function generate_token() {
    $uniqid = md5(uniqid());
    return implode("-", str_split($uniqid, 5));
}

function change_token($token) {
    $key = read_token_data();
    $key['token'] = $token;
    $key['expired'] = "no";
    file_put_contents(CHECK_C_PATH, json_encode($key));
}

// True if the request carries a valid "remember me" cookie or an active session.
function has_valid_session() {
    $key = read_token_data();

    if (isset($_COOKIE['logindata']) && $key['expired'] == "no"
        && hash_equals($key['token'], $_COOKIE['logindata'])) {
        return true;
    }

    return isset($_SESSION['IAm-logined']);
}

// Redirects to login.php and stops execution unless the request is authenticated.
function require_login() {
    if (!has_valid_session()) {
        header('location: login.php');
        exit;
    }

    if (!isset($_SESSION['IAm-logined'])) {
        $_SESSION['IAm-logined'] = 'yes';
    }
}

// Issues a fresh 30-day "remember me" cookie for a session-only login that
// doesn't already carry a valid one.
function refresh_remember_me_cookie() {
    $key = read_token_data();
    $cookie_valid = isset($_COOKIE['logindata']) && $key['expired'] == "no"
        && hash_equals($key['token'], $_COOKIE['logindata']);

    if (!$cookie_valid && isset($_SESSION['IAm-logined'])) {
        $client_token = generate_token();
        setcookie("logindata", $client_token, time() + (86400 * 30), "/"); // 86400 = 1 day
        change_token($client_token);
    }
}

// Clears the session and invalidates the remember-me cookie/token.
function log_out() {
    $_SESSION = array();
    session_destroy();

    setcookie("logindata", "", time() - 3600, "/");

    $key = read_token_data();
    $key['expired'] = "yes";
    file_put_contents(CHECK_C_PATH, json_encode($key));
}

?>
