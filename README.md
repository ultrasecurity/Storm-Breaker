<p align="center">
  <img src="docs/st-cover.png" alt="Storm Breaker v3" width="600"/>
</p>

<h1 align="center">Storm Breaker v3</h1>

<p align="center">
  <b>Consent-first security-awareness & red-team lab</b><br>
  Clone any page, inject capture modules, collect browser data — with informed consent every time.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/version-3.0.0-00c8ff?style=flat-square" alt="Version 3.0.0"/>
  <img src="https://img.shields.io/badge/PHP-7.4+-777bb4?style=flat-square&logo=php" alt="PHP 7.4+"/>
  <img src="https://img.shields.io/badge/SQLite-3-003b57?style=flat-square&logo=sqlite" alt="SQLite 3"/>
  <img src="https://img.shields.io/badge/license-MIT-green?style=flat-square" alt="MIT License"/>
</p>

---

## What is Storm Breaker?

Storm Breaker is a security-awareness / red-team lab tool. It clones a target web page and injects capture modules (camera, microphone, geolocation, device-info, form credentials) that run **in the visitor's browser** — but only after **explicit, informed consent**.

This fork is **consent-first and fail-closed**:

- Every clone shows an **informed-consent notice** before anything runs. It states exactly what will be collected and offers Accept / Decline.
- Capture modules never self-execute. They register via `window.__SB_CONSENT.register()` and only run **after Accept + a second confirmation step**.
- A `CONSENT` line in the database is the audit trail that makes every captured row attributable to a real, informed choice.

> **This is a lab tool for authorized security testing and awareness training. Use responsibly and within legal boundaries.**

---

## Screenshots

### Login
<p align="center">
  <img src="docs/screenshots/login.png" alt="Login" width="100%"/>
</p>

### Dashboard
<p align="center">
  <img src="docs/screenshots/dashboard.png" alt="Dashboard" width="100%"/>
</p>

### Targets — Clone & Host
<p align="center">
  <img src="docs/screenshots/targets.png" alt="Targets" width="100%"/>
</p>

### Captures — Camera Frames
<p align="center">
  <img src="docs/screenshots/captures.png" alt="Captures" width="100%"/>
</p>

### Geo Data — Location Pings
<p align="center">
  <img src="docs/screenshots/geo.png" alt="Geo Data" width="100%"/>
</p>

### Audio — Mic Clips
<p align="center">
  <img src="docs/screenshots/audio.png" alt="Audio" width="100%"/>
</p>

### Credentials
<p align="center">
  <img src="docs/screenshots/credentials.png" alt="Credentials" width="100%"/>
</p>

### Devices
<p align="center">
  <img src="docs/screenshots/devices.png" alt="Devices" width="100%"/>
</p>

---

## What's New in v3.0.0

### Complete PHP Rewrite
The entire codebase has been rewritten from scratch in modern PHP:

- **SQLite database** — flat-file storage (`result.txt`, `meta.json`) replaced with a proper SQLite3 database (`data/storm.db`). WAL journal mode, foreign keys, cascade deletes.
- **Modern auth system** — bcrypt password hashing with session hardening and automatic legacy-plaintext upgrade.
- **Real-time notification system** — server-polled notification dropdown with unread count, badge, and mark-all-read.
- **Auto-update system** — checks GitHub for new versions, shows a modal with version diff, and can self-update with backup + rollback.
- **Consent gate v2** — rewritten with a11y (focus-trap, `aria-modal`, `Escape = decline`), CSS custom properties, entrance animations, and a second "are you sure?" confirmation step.
- **New panel UI** — dark theme with a design system (custom properties, tokens, responsive layout), sidebar navigation, stat cards, and modals.

### Capture Improvements
- **Camera** — fixed snapshot interval leak (kept POSTing after camera revoked); proper cleanup on track `ended` / `pagehide`.
- **Credentials** — coalesced `MutationObserver` bursts into one hook pass per frame.
- **Per-target storage caps** — `CAPTURE_MAX_FRAMES`, `CAPTURE_MAX_CLIPS`, `CAPTURE_MAX_LOG_BYTES` → 429 when exceeded.

### Clone Engine
- Headless Chrome (`--dump-dom`) for full SPA rendering; falls back to cURL.
- Strips all original `<script>` tags after render, injects payload block.
- `<base href>` injection so un-downloaded assets still resolve.
- Fixed stray `<?xml>` processing instruction left by UTF-8 parse.

---

## Requirements

| Dependency | Required | Notes |
|------------|----------|-------|
| **PHP** | 7.4+ | Core runtime |
| **ext-sqlite3** | Yes | Database storage |
| **ext-curl** | Yes | Clone engine + update checker |
| **ext-json** | Yes | API endpoints |
| **ext-session** | Yes | Authentication |
| **Headless Chrome** | Optional | Full SPA rendering; falls back to cURL without it |

---

## Installation

### Quick Start (local testing)

```bash
git clone https://github.com/ultrasecurity/Storm-Breaker.git
cd Storm-Breaker
php run-local.php
```

Open `http://localhost:2525` — default credentials: `admin` / `admin`.

### Server Deployment

```bash
git clone https://github.com/ultrasecurity/Storm-Breaker.git
cd Storm-Breaker
chmod +x deploy.sh
./deploy.sh
```

The deploy script will:

1. **Check PHP version** (7.4+ required)
2. **Verify extensions** (json, session, sqlite3, curl)
3. **Check for headless Chrome** (optional, warns if missing)
4. **Create directories** (`data/`, `targets/`, `log/`) with proper permissions
5. **Set up credentials** — if default `admin/admin` is detected, interactively prompts for a new username and password

Then point your web server's document root at `storm-web/`:

```nginx
# Nginx example
server {
    listen 443 ssl;
    server_name your-domain.com;

    root /path/to/Storm-Breaker/storm-web;
    index index.php;

    location / {
        try_files $uri $uri/ /router.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block direct access to data
    location ~ ^/(data|log)/ {
        deny all;
    }
}
```

```apache
# Apache — .htaccess (included in storm-web/)
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ router.php [QSA,L]
```

> **HTTPS is required** — camera, microphone, and geolocation APIs only work in secure contexts.

### Installing Headless Chrome (optional)

```bash
# Debian/Ubuntu
sudo apt install chromium-browser
# or
sudo apt install google-chrome-stable

# CentOS/RHEL
sudo yum install chromium

# macOS
brew install --cask google-chrome
```

---

## How It Works

```
Operator                          Visitor
   │                                 │
   ├── Login to panel                │
   ├── Enter target URL              │
   ├── Select payloads               │
   ├── "Clone & host"                │
   │    ├── Headless Chrome render    │
   │    ├── Strip original scripts   │
   │    ├── Inject consent gate      │
   │    └── Inject capture modules   │
   │                                 │
   │    /<slug> ─────────────────────>│
   │                                 ├── See consent notice
   │                                 ├── Accept → "Are you sure?" → Yes
   │                                 ├── Payloads run (camera/mic/geo/...)
   │                                 └── Data POSTed to /api/capture.php
   │                                 │
   ├── Dashboard shows live stats    │
   ├── View captures, audio, geo...  │
   └── Real-time notifications       │
```

---

## Project Structure

```
Storm-Breaker/
├── run-local.php              # Dev server launcher (localhost:2525)
├── deploy.sh                  # Server deployment helper
├── Settings.json              # Version metadata (semver)
│
└── storm-web/                 # Web root
    ├── router.php             # Front controller (slug routing + security)
    ├── index.php              # Entry point → panel or login
    ├── auth.php               # Auth: bcrypt, session hardening
    ├── config.php             # Credentials store (managed by auth.php)
    ├── panel.php              # Operator panel (single-page, routed)
    ├── login.php              # Login page
    ├── Settings.json          # Version metadata
    │
    ├── api/
    │   ├── capture.php        # PUBLIC ingestion endpoint (no auth)
    │   ├── targets.php        # Clone CRUD (auth-required)
    │   ├── captures.php       # Camera frame browser
    │   ├── audio.php          # Audio clip browser
    │   ├── geo.php            # Geolocation data
    │   ├── credentials.php    # Captured credentials
    │   ├── devices.php        # Device info
    │   ├── notifications.php  # Real-time notification feed
    │   └── update.php         # Auto-update from GitHub
    │
    ├── lib/
    │   ├── cloner.php         # Clone engine (Chrome/cURL + inject)
    │   └── store.php          # SQLite storage layer
    │
    ├── data/
    │   └── storm.db           # SQLite database (auto-created)
    │
    ├── assets/
    │   ├── payloads/          # Consent gate + capture modules
    │   │   ├── consent.js     # THE consent gate
    │   │   ├── camera.js      # Camera capture (gated)
    │   │   ├── microphone.js  # Mic recording (gated)
    │   │   ├── geolocation.js # Location tracking (gated)
    │   │   ├── device-info.js # Device fingerprint (gated)
    │   │   └── credentials.js # Form credential capture (gated)
    │   ├── components/        # Panel UI partials
    │   ├── css/               # Design system + panel styles
    │   └── js/                # Panel helpers + vendor libs
    │
    ├── targets/               # Generated clones (output, not source)
    ├── log/                   # Notification state
    └── tools/
        ├── migrate-to-sqlite.php  # Flat-file → SQLite migration
        └── rerender-targets.php   # Re-inject payload into old clones
```

---

## Usage

### Creating a Target

1. Go to **Targets** in the panel
2. Enter the **Source URL** (the page to clone)
3. Optionally set a custom **slug** (or leave blank for auto-generated)
4. Select **payloads** to inject:
   - `credentials` — always enabled (intercepts form submissions)
   - `device-info` — browser/OS fingerprint
   - `geolocation` — GPS location tracking
   - `camera` — camera snapshot capture
   - `microphone` — audio recording
5. Click **Clone & host**

The cloned page is now live at `https://your-domain.com/<slug>`.

### Viewing Captured Data

- **Dashboard** — overview stats (total captures, audio clips, geo pings, active targets)
- **Captures** — browse camera frames per target
- **Audio** — play back recorded mic clips
- **Geo Data** — view location pings on a map with coordinates + accuracy
- **Credentials** — see captured form submissions
- **Devices** — browser fingerprints and device info

### Auto-Update

Click the version number in the sidebar footer to check for updates. If a new version is available, a modal shows the version diff and an **Apply Update** button. The updater:

- Downloads the latest ZIP from GitHub
- Backs up current files (excluding `config.php`, `data/`, `targets/`, `log/`)
- Copies new files over
- Shows the result with file count and backup location

---

## Security Notes

- **Change default credentials** — the deploy script prompts for this; do it.
- **Use HTTPS** — camera/mic/geo APIs require a secure context.
- **`capture.php` is intentionally public** — visitor browsers POST data to it. It has slug validation, path-traversal guards, and size/rate caps.
- **`data/` is protected** — `router.php` blocks direct access; `.htaccess` as backup.
- **Session hardening** — `httponly`, `samesite=Strict`, secure flags, regeneration on login.

---

## Migrating from v2

If you have an existing Storm-Breaker v2 installation with flat-file data:

```bash
cd Storm-Breaker
php storm-web/tools/migrate-to-sqlite.php
```

This reads `result.txt`, `meta.json`, `captures/`, and `audio/` from each target directory and imports them into the SQLite database. Safe to re-run.

---

## Development

```bash
# Start the dev server
php run-local.php                 # localhost:2525

# Lint
php -l storm-web/<file>.php       # PHP syntax check
node --check storm-web/assets/payloads/<file>.js   # JS syntax check

# Rebuild knowledge graph (after large changes)
graphify . --update
```

---

## License

MIT License — see [LICENSE](LICENSE) for details.

---

<p align="center">
  <img src="docs/st-cover.png" alt="Storm Breaker" width="200"/>
  <br>
  <b>Storm Breaker v3</b> — Built for security awareness.
</p>
