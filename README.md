<p align="center">
  <img src="docs/st-cover.png" alt="Storm Breaker v3" width="800"/>
</p>

<h1 align="center">Storm Breaker v3</h1>

<p align="center">
  <b>Open-source social engineering tool for red team</b>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/version-3.0.0-00c8ff?style=flat-square" alt="Version 3.0.0"/>
  <img src="https://img.shields.io/badge/PHP-7.4+-777bb4?style=flat-square&logo=php" alt="PHP 7.4+"/>
  <img src="https://img.shields.io/badge/SQLite-3-003b57?style=flat-square&logo=sqlite" alt="SQLite 3"/>
  <img src="https://img.shields.io/badge/license-MIT-green?style=flat-square" alt="MIT License"/>
</p>

---

## What is Storm Breaker?

Storm Breaker clones a target web page and injects capture modules (camera, microphone, geolocation, device-info, form credentials) that run in the visitor's browser, collecting data back to the operator's panel.

> **This is a lab tool for authorized security testing. Use responsibly and within legal boundaries.**

---

## Features

- **Page Cloning** — Headless Chrome rendering with cURL fallback; strips original scripts, injects payloads, resolves assets via `<base href>`
- **MITM Proxy** — transparent reverse proxy that relays requests to the real target, rewrites all URLs, and patches fetch/XHR/WebSocket/history so the entire session stays routed through the operator
- **Credential Sniffing** — smart extraction from POST bodies: form-urlencoded, multipart, JSON, GraphQL, Livewire RPC, Inertia.js, and nested structures
- **Credential Verification** — auto-detects login success or failure from HTTP status codes, redirects, JSON responses, and HTML re-renders
- **Session Hijacking** — snapshots the victim's cookie jar on verified login and exports Cookie-Editor JSON for instant session replay
- **Camera Capture** — periodic snapshots from the visitor's webcam with proper cleanup on revoke/pagehide
- **Microphone Recording** — audio clip capture from the visitor's mic
- **Geolocation Tracking** — GPS location pings with coordinates and accuracy
- **Device Fingerprinting** — browser, OS, screen, and hardware info (always on)
- **Homograph Domain Generator** — 9 confusion categories: Cyrillic, Armenian, Greek, mixed-script, diacritics, digit swap, bigram swap, edit distance, and TLD swap with RDAP availability check
- **Real-time SSE Stream** — Server-Sent Events push for live credential and session notifications
- **Auto-Update** — checks GitHub for new versions, self-updates with backup + rollback

---

## Screenshots

### Targets — Clone & Host
<p align="center">
  <img src="docs/screenshots/targets.png" alt="Targets" width="100%"/>
</p>

### Credentials — Captured Logins
<p align="center">
  <img src="docs/screenshots/credentials.png" alt="Credentials" width="100%"/>
</p>

### Geo Data — Location Pings
<p align="center">
  <img src="docs/screenshots/geo.png" alt="Geo Data" width="100%"/>
</p>

---

## Quick Start

```bash
git clone https://github.com/ultrasecurity/Storm-Breaker.git
cd Storm-Breaker
php run-local.php
```

Open `http://localhost:2525` — default credentials: `admin` / `admin`.

**Requirements:** PHP 7.4+ with ext-sqlite3, ext-curl, ext-json, ext-session. Headless Chrome optional (enables full SPA cloning).

---

## Server Deployment

```bash
sudo bash deploy.sh --ip 1.2.3.4 --domain panel.example.com --email you@example.com
```

The script auto-detects Debian/Ubuntu and RHEL/CentOS/Fedora, installs dependencies, creates a hardened systemd service, configures nginx with TLS, opens firewall ports, and sets SELinux contexts. It prompts for new credentials if defaults are still in place.

| Flag | Description |
|------|-------------|
| `--ip` | Server IP address |
| `--domain` | Domain for Let's Encrypt TLS (uses self-signed without it) |
| `--email` | Email for Let's Encrypt registration |
| `--https` / `--http` | HTTPS (default) or HTTP-only mode |
| `--php-port` | Internal PHP port (default: 9874) |
| `--user` | Service user name (default: stormbreaker) |
| `--uninstall` | Remove service + nginx config |

> **HTTPS is required** — camera, microphone, and geolocation APIs only work in secure contexts.

---

## Usage

1. Go to **Targets** → enter a URL → select payloads → **Clone & host**
2. Send the generated `https://your-domain.com/<slug>` link to the target
3. View captured data in **Devices**, **Credentials**, **Captures**, **Audio**, **Geo Data**

---

## Security Notes

- **Change default credentials** — the deploy script prompts for this; do it.
- **`capture.php` is intentionally public** — it has slug validation, path-traversal guards, and size/rate caps.
- **`data/` is protected** — `router.php` blocks direct access; `.htaccess` as backup.
- **systemd hardening** — `NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=full`, `UMask=0077`.

---

## Migrating from v2

```bash
php storm-web/tools/migrate-to-sqlite.php
```

Imports `result.txt`, `meta.json`, `captures/`, and `audio/` from each target into SQLite. Safe to re-run.

---

## License

MIT License — see [LICENSE](LICENSE) for details.
