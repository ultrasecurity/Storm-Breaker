# AGENTS.md — Storm-Breaker

> **Read this before touching anything.** It is the project map. Most questions
> below are answered here — don't re-grep the tree to rediscover them. When you
> need a deeper call/relationship answer, query the knowledge graph (see §5)
> instead of searching by hand. This project runs under the **ponytail**
> discipline (§8): write the smallest change that fully solves the task.

---

## 0. What this project is

An **open-source browser security assessment tool for red teams**. It clones a
target page and injects capture modules (camera, mic, geolocation, device-info,
form credentials) that run **in the visitor's browser**, collecting data back
to the operator's panel.

> **This is a lab tool for authorized security testing. Use responsibly and
> within legal boundaries.**

---

## 1. Request lifecycle (how it actually runs)

**A. Operator (authenticated):**
`/` → `index.php` → `auth.php` → `panel.php`. The panel is a single page that
talks to `api/*.php` (all auth-required except `capture.php`).

**B. Building a clone:**
panel → `POST api/targets.php?action=create` → `cloner_clone()` in `lib/cloner.php`:
1. render the URL in **headless Chrome** (`--dump-dom`) so SPAs materialize;
   fall back to plain cURL if Chrome is absent.
2. strip **all** original `<script>` tags (DOM is already rendered).
3. inject `<base href>` (original site) so un-downloaded assets still resolve.
4. download + rewrite `<img>/<link>` assets to absolute URLs on our origin.
5. inject the payload block (see §3) before `</body>`.
   → written to `targets/<slug>/index.html`.

**C. Visitor:**
`GET /<slug>` → `router.php` → `targets/<slug>/index.html` → payloads run →
`POST /api/capture.php?slug=&kind=` → stored under `targets/<slug>/`
(`captures/`, `audio/`, `result.txt`) → shows up in the panel.

---

## 2. File map (don't re-discover this)

| Path | Role |
|------|------|
| `run-local.php` | Dev server launcher: `php -S host:port -t storm-web router.php`. Default `localhost:2525`. |
| `deploy.sh` | Server deploy helper (checks PHP ≥ 7.4, perms). |
| `storm-web/router.php` | Front controller. Serves `/<slug>` and `/<slug>/assets/*`; **hides** `captures/`, `audio/`, `result.txt`, `meta.json`. |
| `storm-web/index.php` | `/` → panel or login. Also a defer-guard when PHP built-in server runs without the router arg. |
| `storm-web/auth.php` | Auth: bcrypt, session hardening, legacy-plaintext auto-upgrade. The single source of truth — use its functions, don't touch `$_SESSION`/`$CONFIG` directly. |
| `storm-web/config.php` | Credentials store (managed by `auth.php`). **Ships `admin/admin` — change it.** |
| `storm-web/panel.php` | Operator panel (single page; routes rendered from `assets/components/route-*.php`). |
| `storm-web/login.php` · `logout.php` · `settings.php` · `receiver.php` | Panel auth + settings + legacy receiver. |
| `storm-web/lib/cloner.php` | **Clone engine.** fetch/render/strip/rewrite/inject + slug rules. The heart of the project. |
| `storm-web/lib/store.php` | **SQLite storage layer.** All data access goes through `store_*()` functions. DB at `data/storm.db` (WAL mode, FK on). |
| `storm-web/data/storm.db` | **SQLite database.** Tables: `targets`, `events`, `media`, `kv`. Protected from web access by `router.php` + `.htaccess`. |
| `storm-web/api/capture.php` | **PUBLIC ingestion (no auth, by design).** Kinds: `device,geo,cred,camera,mic`. Writes events to DB + media files to disk. Slug-validated, path-traversal-guarded, size/rate-capped. |
| `storm-web/api/targets.php` | CRUD over clones (create/rename/update/delete). Auth-required. Reads/writes `targets` table. |
| `storm-web/api/{captures,audio,geo,credentials,devices,notifications}.php` | Panel data endpoints (auth-required) that read from `events` + `media` tables. |
| `storm-web/tools/migrate-to-sqlite.php` | One-shot migration: reads old flat files (result.txt, meta.json, captures/, audio/) into SQLite. Safe to re-run. |
| `storm-web/assets/payloads/consent.js` | Consent gate (optional). Defines `window.__SB_CONSENT`. |
| `storm-web/assets/payloads/{camera,microphone,geolocation,device-info,credentials}.js` | Capture modules. |
| `storm-web/assets/components/*.php` | Panel UI partials: `header`, `sidebar`, `icons`, `route-{targets,captures,geo,audio,credentials,devices}`. |
| `storm-web/assets/js/{recorder,loc,location}.js` | Panel/capture helpers. `*.min.js` (jquery, feather, particles, warpspeed, client) = **vendor**, don't edit. |
| `storm-web/assets/css/*` · `tokens.css` · `design-system.html` | Panel styling + design tokens + a static design-system reference. |
| `storm-web/targets/<slug>/` | **Generated clones** — `index.html`, `meta.json`, `captures/`, `audio/`, `result.txt`, `.render-mode`. OUTPUT, not source. Don't hand-edit. |
| `storm-web/tools/rerender-targets.php` | Re-injects a fresh payload block into old clones. |

---

## 3. Injected payload block (order matters)

Built by `cloner_build_payload_block()`, inlined before `</body>`:

1. **bootstrap** — sets `__SB_TARGET` (slug), `__SB_ORIGINAL_URL`, and
   `__SB_API(kind)` (an **absolute** capture-endpoint URL builder).
2. **`credentials.js`** — always included.
3. **opt-in modules** — camera / mic / geolocation / device-info.
4. **operator custom JS** (optional, last — can override; it's the operator's own code).

---

## 4. Hard rules & gotchas (break these and things silently fail)

1. **Payloads must reach the panel via `window.__SB_API(kind)`** — an absolute
   URL on our origin. Never a relative `/api/...`: the clone's injected
   `<base href>` points at the *original* site and would misroute it.
2. **All payloads are INLINED, not `<script src>`** — same
   `<base href>` reason (a `src` would 404 on the original host).
4. **In PHP, never put `?>` inside a `//` comment** — PHP treats it as the
   closing tag even inside a line comment and drops out of PHP mode. (This bit us
   once already; see §6.)
5. **`capture.php` is intentionally public** (visitor browsers must POST to it).
   Keep its slug validation, path-traversal guard, and size/rate caps.
6. **User-facing strings are Persian / RTL** (panel). Keep code
   **comments and identifiers in English** (matches the whole codebase).
7. **`targets/` is generated** — change clones via `tools/rerender-targets.php`
   or the update API, not by editing `index.html` directly.
8. **Reserved slugs** (`panel,login,api,assets,targets,…`, full list in
   `cloner.php` `CLONER_RESERVED_SLUGS`) can't be target names.
9. **SSRF is intentional**: the cloner fetches any URL, incl. `localhost` (lab
   pages are cloned from localhost). Operator == attacker here, so it isn't
   gated. Keep this in mind before "fixing" it — it would break the lab.
10. **`cloner.php` strips every original `<script>`** after the Chrome render,
    then injects ours. Don't expect the page's own JS to run in a clone.

---

## 5. Knowledge graph (query before you grep)

A graphify knowledge graph of the **source code** lives in `graphify-out/`:

- `graph.json` — raw graph (147 nodes, 200 edges, AST-only — **0 LLM tokens**).
- `graph.html` — open in a browser to explore visually.
- `GRAPH_REPORT.md` — god nodes, community hubs, suggested questions.

**Use it to answer "what calls X / where does Y live" instead of searching:**

```bash
graphify query "how does a capture reach the panel"
graphify path "cloner_clone" "capture.php"
graphify explain "capture"
```

God nodes (core abstractions): `Recorder()`, `cloner_clone()`, `init()`
(credentials). Community hubs = the file map in §2.

Rebuild after large changes:
```bash
cd /Users/qadir/Desktop/Claude/Storm-Breaker
graphify /Users/qadir/Desktop/Claude/Storm-Breaker --update
```

---

## 6. Changes — 2026-10-30

Bug fixes + UI improvements:

- **`api/capture.php`** — added real per-target storage caps
  (`CAPTURE_MAX_FRAMES` / `CAPTURE_MAX_CLIPS` / `CAPTURE_MAX_LOG_BYTES` → 429).
  Replaced a comment that *claimed* a rate cap which was never implemented.
- **`assets/payloads/camera.js`** — the snapshot `setInterval` was never
  cleared; it kept POSTing black frames after the camera was revoked. Now stops
  on track `ended` / `pagehide` and tears the video element down.
- **`lib/cloner.php`** — strip the stray `<?xml …>` processing-instruction the
  UTF-8 parse trick leaves behind, so clones don't start with it. (Fixing this
  is also where rule §4.4 came from — a `?>` in a comment broke the parse.)
- **`assets/payloads/credentials.js`** — coalesce `MutationObserver` bursts into
  one hook pass per frame (was re-scanning every form on every DOM mutation).
- **SQLite migration** — replaced the entire flat-file storage model with
  `lib/store.php` + `data/storm.db`:
  - `result.txt` lines → `events` table (no more regex parsing or file rewriting).
  - `meta.json` per target → `targets` table (kept on disk during transition).
  - `captures/*.png` / `audio/*.webm` file *enumeration* → `media` table (binaries
    stay on disk; DB tracks filename + size + timestamp).
  - `log/notifications-state.json` → `kv` table.
  - WAL journal mode + `PRAGMA foreign_keys = ON`; `ON DELETE CASCADE` on events
    and media so deleting a target cleans up automatically.
  - `router.php` blocks `data/` from being served; `.htaccess` backup.
  - `tools/migrate-to-sqlite.php` imports existing data (safe to re-run).
  - All six API files (`capture`, `targets`, `geo`, `notifications`, `captures`,
    `audio`) rewritten to use `store_*()` functions.

---

## 7. Run & verify

```bash
php run-local.php                 # localhost:2525 (host/port are optional args)
php -l storm-web/<file>.php       # PHP lint
node --check storm-web/assets/payloads/<file>.js   # JS syntax
```

Preview a clone locally: create a target from `http://localhost:2525` or
any local page, then visit `http://localhost:2525/<slug>`.

---

## 8. Code discipline — ponytail (write less)

This project runs under **ponytail** — "lazy senior dev" mode, installed as a
skill at [`.claude/skills/ponytail/SKILL.md`](.claude/skills/ponytail/SKILL.md)
(MIT © DietrichGebert, <https://github.com/dietrichgebert/ponytail>). **The best
code is the code never written.** Before writing, read the code your change
touches, then take the first option that fully works:

1. **Does it need to exist?** Skip features/options/flexibility nobody asked for.
2. **Already in this codebase?** Reuse the helper/pattern the way neighbours do.
3. **Standard library / platform feature?** Use it (unless the project has its own).
4. **An installed dependency?** Use it — never add one for a few lines.
5. **Can it be one clear line?** One line.
6. **Otherwise:** the minimum code that works.

Deletion beats addition; keep the structure the codebase already has. End a
change by naming what you skipped and any risk the user must know. A deliberate
shortcut gets a `shortcut: <the limit>, <when to upgrade>` comment.

**Never cut** (ponytail's hard line, and ours): validation at trust boundaries,
error handling that prevents data loss, **security**, accessibility, or anything
the user asked for.

In Claude Code: `/ponytail [lite | full | ultra | off]` switches intensity
(default **full**). `/ponytail-review` reviews the current diff for what to cut.
