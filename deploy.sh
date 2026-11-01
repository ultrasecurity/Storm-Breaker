#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Storm-Breaker — one-shot Linux deployment
#
# Installs PHP 7.4+ with the extensions we need, stands up nginx as a TLS-
# terminating reverse proxy, writes a systemd unit for the PHP built-in
# server, opens the firewall, and prints the final URL.
#
# Supports Debian/Ubuntu (apt) and RHEL/Rocky/Alma/CentOS Stream/Fedora (dnf).
# Idempotent — rerun to upgrade or change port/IP/domain. Safe to re-run.
#
# Usage:
#   sudo bash deploy.sh                  # guided setup (recommended)
#   sudo bash deploy.sh --uninstall      # remove service + config
#
# Advanced (skip the wizard):
#   sudo bash deploy.sh --ip 1.2.3.4 --domain panel.example.com --email you@example.com
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# ── Pretty output ────────────────────────────────────────────────────────────
C_RESET=$'\e[0m'; C_DIM=$'\e[2m'; C_BOLD=$'\e[1m'
C_CYAN=$'\e[36m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_RED=$'\e[31m'

say()  { printf '%s[storm]%s %s\n'           "$C_CYAN"   "$C_RESET" "$*"; }
ok()   { printf '%s[ ok  ]%s %s\n'           "$C_GREEN"  "$C_RESET" "$*"; }
warn() { printf '%s[ warn]%s %s\n'           "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()  { printf '%s[fatal]%s %s\n'           "$C_RED"    "$C_RESET" "$*" >&2; exit 1; }
step() { printf '\n%s── %s ──%s\n'           "$C_BOLD"   "$*" "$C_RESET"; }

# ── Arg parsing ──────────────────────────────────────────────────────────────
BIND_IP=""
DOMAIN=""
EMAIL=""
HTTPS_PORT=443
HTTP_PORT=80
PHP_PORT=9874
MODE="install"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_DIR="$APP_DIR/storm-web"
SERVICE_USER="stormbreaker"
SERVICE_NAME="storm-breaker"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --ip)        BIND_IP="$2";        shift 2 ;;
    --domain)    DOMAIN="$2";         shift 2 ;;
    --email)     EMAIL="$2";          shift 2 ;;
    --https)     HTTPS_PORT="$2";     shift 2 ;;
    --http)      HTTP_PORT="$2";      shift 2 ;;
    --php-port)  PHP_PORT="$2";       shift 2 ;;
    --app-dir)   APP_DIR="$2"; WEB_DIR="$APP_DIR/storm-web"; shift 2 ;;
    --user)      SERVICE_USER="$2";   shift 2 ;;
    --uninstall) MODE="uninstall";    shift ;;
    -h|--help)
      sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "unknown flag: $1 (use --help)" ;;
  esac
done

# Sanity check — storm-web/ must exist
[[ -d "$WEB_DIR" ]] || die "storm-web/ not found at $WEB_DIR"

# ── Root check ───────────────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "run as root (sudo bash deploy.sh)"

# ── Distro detection ─────────────────────────────────────────────────────────
step "detecting OS"
if [[ -r /etc/os-release ]]; then
  . /etc/os-release
  DISTRO_ID="${ID:-unknown}"
  DISTRO_LIKE="${ID_LIKE:-}"
else
  die "cannot read /etc/os-release — unsupported system"
fi

if [[ "$DISTRO_ID" == "debian" || "$DISTRO_ID" == "ubuntu" || "$DISTRO_LIKE" == *debian* ]]; then
  PM="apt";   PM_INSTALL="apt-get install -y"; PM_UPDATE="apt-get update -y"
elif [[ "$DISTRO_ID" == "rhel" || "$DISTRO_ID" == "rocky" || "$DISTRO_ID" == "almalinux" \
     || "$DISTRO_ID" == "centos" || "$DISTRO_ID" == "fedora" || "$DISTRO_LIKE" == *rhel* ]]; then
  PM="dnf";   PM_INSTALL="dnf install -y";     PM_UPDATE="dnf makecache -y"
  command -v dnf >/dev/null || { PM="yum"; PM_INSTALL="yum install -y"; PM_UPDATE="yum makecache -y"; }
else
  die "unsupported distro: $DISTRO_ID (supported: Debian/Ubuntu, RHEL/Rocky/Alma/CentOS/Fedora)"
fi
ok "detected: $PRETTY_NAME (package manager: $PM)"

# ── Uninstall path ───────────────────────────────────────────────────────────
if [[ "$MODE" == "uninstall" ]]; then
  step "uninstalling"
  systemctl stop    "$SERVICE_NAME.service" 2>/dev/null || true
  systemctl disable "$SERVICE_NAME.service" 2>/dev/null || true
  rm -f "/etc/systemd/system/$SERVICE_NAME.service"
  rm -f "/etc/nginx/conf.d/$SERVICE_NAME.conf" "/etc/nginx/sites-available/$SERVICE_NAME" "/etc/nginx/sites-enabled/$SERVICE_NAME"
  systemctl daemon-reload
  systemctl reload nginx 2>/dev/null || true
  ok "removed service + nginx config (app files and data/ left in place)"
  exit 0
fi

# ── Interactive prompts (only if flags missing) ──────────────────────────────

# Detect server IP once (used by prompts and config)
DETECTED_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '/src/{for (i=1;i<=NF;i++) if ($i=="src") print $(i+1); exit}')"
[[ -z "$DETECTED_IP" ]] && DETECTED_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[[ -z "$DETECTED_IP" ]] && DETECTED_IP="0.0.0.0"

# Only run interactive mode if no flags were passed on the command line
INTERACTIVE=false
[[ -z "$BIND_IP" && -z "$DOMAIN" && -z "$EMAIL" ]] && INTERACTIVE=true

if $INTERACTIVE; then
  printf '\n'
  printf '%s╭──────────────────────────────────────────────────────────────╮%s\n' "$C_BOLD$C_CYAN" "$C_RESET"
  printf '%s│              ⚡  Storm Breaker — Setup Wizard               │%s\n' "$C_BOLD$C_CYAN" "$C_RESET"
  printf '%s╰──────────────────────────────────────────────────────────────╯%s\n' "$C_BOLD$C_CYAN" "$C_RESET"
  printf '\n'

  # ─── Step 1: Server IP ───────────────────────────────────────────────
  printf '  %s1)%s Server IP address\n' "$C_BOLD" "$C_RESET"
  printf '     %sDetected: %s%s\n' "$C_DIM" "$DETECTED_IP" "$C_RESET"
  read -r -p "     Use this IP? [Y/n] " USE_IP || true
  USE_IP="${USE_IP:-Y}"
  if [[ "$USE_IP" =~ ^[Yy]$ ]]; then
    BIND_IP="$DETECTED_IP"
  else
    read -r -p "     Enter IP: " BIND_IP || true
    [[ -z "$BIND_IP" ]] && BIND_IP="$DETECTED_IP"
  fi
  printf '     %s✓ %s%s\n\n' "$C_GREEN" "$BIND_IP" "$C_RESET"

  # ─── Step 2: HTTPS ──────────────────────────────────────────────────
  printf '  %s2)%s HTTPS (secure connection)\n' "$C_BOLD" "$C_RESET"
  printf '     %sHTTPS is required for camera, mic, and geolocation to work.%s\n' "$C_DIM" "$C_RESET"
  printf '     %sYou can get a free certificate from Let'\''s Encrypt.%s\n' "$C_DIM" "$C_RESET"
  printf '\n'
  printf '     %sa)%s I have a domain → get a real certificate (recommended)\n' "$C_BOLD" "$C_RESET"
  printf '     %sb)%s No domain yet → use self-signed certificate\n' "$C_BOLD" "$C_RESET"
  read -r -p "     Choose [a/b]: " TLS_CHOICE || true
  TLS_CHOICE="${TLS_CHOICE:-a}"

  if [[ "$TLS_CHOICE" =~ ^[Aa]$ ]]; then
    printf '\n'
    read -r -p "     Domain name (e.g. panel.example.com): " DOMAIN || true
    if [[ -n "$DOMAIN" ]]; then
      printf '\n'
      printf '     %sLet'\''s Encrypt needs an email to send certificate expiry reminders.%s\n' "$C_DIM" "$C_RESET"
      read -r -p "     Your email: " EMAIL || true
      if [[ -z "$EMAIL" ]]; then
        warn "no email given — will use self-signed certificate instead"
      else
        printf '     %s✓ HTTPS via Let'\''s Encrypt for %s%s\n' "$C_GREEN" "$DOMAIN" "$C_RESET"
      fi
    else
      warn "no domain given — will use self-signed certificate"
    fi
  else
    printf '     %s✓ Using self-signed certificate%s\n' "$C_GREEN" "$C_RESET"
  fi
  printf '\n'

else
  # Non-interactive: fill in defaults for missing values
  [[ -z "$BIND_IP" ]] && BIND_IP="$DETECTED_IP"
fi

# ─── Summary ──────────────────────────────────────────────────────────
step "ready to deploy"
printf '\n'
printf '     Server IP : %s%s%s\n' "$C_BOLD" "$BIND_IP" "$C_RESET"
if [[ -n "$DOMAIN" ]]; then
  printf '     Domain    : %s%s%s\n' "$C_BOLD" "$DOMAIN" "$C_RESET"
fi
if [[ -n "$DOMAIN" && -n "$EMAIL" ]]; then
  printf '     HTTPS     : %sLet'\''s Encrypt%s (%s)\n' "$C_GREEN" "$C_RESET" "$EMAIL"
else
  printf '     HTTPS     : %sSelf-signed certificate%s\n' "$C_YELLOW" "$C_RESET"
fi
printf '     App dir   : %s\n' "$APP_DIR"
printf '\n'

read -r -p "  Start deployment? [Y/n] " PROCEED || true
PROCEED="${PROCEED:-Y}"
[[ "$PROCEED" =~ ^[Yy]$ ]] || die "aborted"

# ── Install packages ─────────────────────────────────────────────────────────
step "installing dependencies"
$PM_UPDATE

if [[ "$PM" == "apt" ]]; then
  DEBIAN_FRONTEND=noninteractive $PM_INSTALL \
    php-cli php-sqlite3 php-curl php-json \
    nginx openssl curl coreutils
  [[ -n "$DOMAIN" && -n "$EMAIL" ]] && DEBIAN_FRONTEND=noninteractive $PM_INSTALL certbot python3-certbot-nginx || true
else
  $PM_INSTALL \
    php-cli php-pdo php-sqlite3 php-curl php-json \
    nginx openssl curl coreutils policycoreutils-python-utils || \
  $PM_INSTALL \
    php-cli php-pdo php-sqlite3 php-curl \
    nginx openssl curl coreutils
  [[ -n "$DOMAIN" && -n "$EMAIL" ]] && $PM_INSTALL certbot python3-certbot-nginx || true
fi
ok "packages installed"

# ── Verify PHP version + extensions ──────────────────────────────────────────
step "verifying PHP"
PHP_BIN="$(command -v php)"
[[ -z "$PHP_BIN" ]] && die "php not on PATH after install"

PHP_VER="$($PHP_BIN -r 'echo PHP_VERSION;')"
PHP_MAJOR="$(printf '%s' "$PHP_VER" | cut -d. -f1)"
PHP_MINOR="$(printf '%s' "$PHP_VER" | cut -d. -f2)"
if (( PHP_MAJOR < 7 || (PHP_MAJOR == 7 && PHP_MINOR < 4) )); then
  die "PHP $PHP_VER is too old — Storm-Breaker requires PHP 7.4+"
fi
ok "PHP $PHP_VER"

for ext in sqlite3 curl json session; do
  if $PHP_BIN -r "exit(extension_loaded('$ext')?0:1);"; then
    ok "ext: $ext"
  else
    die "missing required PHP extension: $ext"
  fi
done

# ── Optional: headless Chrome ────────────────────────────────────────────────
step "checking headless Chrome"
CHROME_FOUND=false
if command -v google-chrome >/dev/null 2>&1 || command -v chromium-browser >/dev/null 2>&1 || command -v chromium >/dev/null 2>&1; then
  CHROME_FOUND=true
fi

if $CHROME_FOUND; then
  ok "headless Chrome found — clones will use full DOM rendering"
else
  warn "headless Chrome not found — clone engine will fall back to cURL (SPAs won't render fully)"
fi

# ── Service user ─────────────────────────────────────────────────────────────
step "service user"
if ! id "$SERVICE_USER" >/dev/null 2>&1; then
  useradd --system --home-dir "$APP_DIR" --shell /usr/sbin/nologin "$SERVICE_USER" 2>/dev/null \
  || useradd --system --home-dir "$APP_DIR" --shell /sbin/nologin  "$SERVICE_USER"
  ok "created user: $SERVICE_USER"
else
  ok "user exists: $SERVICE_USER"
fi

# ── Storage permissions ──────────────────────────────────────────────────────
# data/ holds storm.db (credentials, sessions, geo data, captures metadata).
# targets/ holds generated clone HTML. log/ holds notification state.
# None are ever served directly — router.php gates all access. Permissions:
#
#   owner  = $SERVICE_USER  (the PHP server) — read+write
#   group  = $SERVICE_USER  (self)           — no access
#   other                                    — no access
#
# Result: dirs 0700, files 0600.
step "storage permissions"
WRITABLE=("$WEB_DIR/data" "$WEB_DIR/targets" "$WEB_DIR/log")
for d in "${WRITABLE[@]}"; do
  mkdir -p "$d"
done
chown -R "$SERVICE_USER:$SERVICE_USER" "${WRITABLE[@]}"
for d in "${WRITABLE[@]}"; do
  find "$d" -type d -exec chmod 0700 {} +
  find "$d" -type f -exec chmod 0600 {} +
done

# .gitkeep for targets (owned by service user to match the 0700 model)
touch "$WEB_DIR/targets/.gitkeep"
chown "$SERVICE_USER:$SERVICE_USER" "$WEB_DIR/targets/.gitkeep"
chmod 0600 "$WEB_DIR/targets/.gitkeep"

# Protect data/ from web access (belt + suspenders with router.php)
if [[ ! -f "$WEB_DIR/data/.htaccess" ]]; then
  printf 'Deny from all\n' > "$WEB_DIR/data/.htaccess"
fi
chown "$SERVICE_USER:$SERVICE_USER" "$WEB_DIR/data/.htaccess"
chmod 0600 "$WEB_DIR/data/.htaccess"

# App code is read-only to the service user (root owns, service_user can read)
find "$WEB_DIR" -maxdepth 1 \( -name api -o -name lib -o -name assets \) -print0 \
  | xargs -0 -I{} chown -R "root:$SERVICE_USER" "{}" 2>/dev/null || true
find "$WEB_DIR" -maxdepth 1 \( -name api -o -name lib -o -name assets \) -print0 \
  | xargs -0 -I{} chmod -R u=rwX,g=rX,o= "{}" 2>/dev/null || true

# Keep deploy scripts + version manifest readable
[[ -f "$APP_DIR/deploy.sh" ]]     && chmod 0750 "$APP_DIR/deploy.sh" && chown "root:root" "$APP_DIR/deploy.sh"
[[ -f "$APP_DIR/run-local.php" ]] && chmod 0750 "$APP_DIR/run-local.php" && chown "root:root" "$APP_DIR/run-local.php"
[[ -f "$APP_DIR/Settings.json" ]] && chmod 0644 "$APP_DIR/Settings.json" && chown "root:$SERVICE_USER" "$APP_DIR/Settings.json"

ok "storage is 0700/0600 (owner-only), code is r-x to service user"

# ── Admin credentials ────────────────────────────────────────────────────────
step "admin credentials"
CONFIG_FILE="$WEB_DIR/config.php"
DEFAULT_HASH='$2y$10$rWRM/86rX63rwTadZAu1AeGyhH6ddlkKjaHMRRTiu8S78XDjg6aq2'

if grep -q "$DEFAULT_HASH" "$CONFIG_FILE" 2>/dev/null; then
  warn "default credentials detected (admin/admin). Let's set a new username and password."
  echo ""

  read -rp "  Enter admin username [admin]: " NEW_USER
  NEW_USER="${NEW_USER:-admin}"

  while true; do
    read -rsp "  Enter password (min 8 chars): " NEW_PASS
    echo ""
    if [[ ${#NEW_PASS} -lt 8 ]]; then
      warn "  Password must be at least 8 characters."
      continue
    fi
    read -rsp "  Confirm password: " CONFIRM_PASS
    echo ""
    if [[ "$NEW_PASS" != "$CONFIRM_PASS" ]]; then
      warn "  Passwords do not match. Try again."
      continue
    fi
    break
  done

  NEW_HASH=$($PHP_BIN -r "echo password_hash(\$argv[1], PASSWORD_BCRYPT);" "$NEW_PASS")

  # Write config.php via PHP so special chars in username/hash are properly escaped
  $PHP_BIN -r '
    $u = $argv[1]; $h = $argv[2]; $f = $argv[3];
    file_put_contents($f,
      "<?php\n// config.php — credentials store. Managed by auth.php.\n"
      ."// hash is a bcrypt hash; never store plaintext.\n"
      ."\$CONFIG = [\n"
      ."    ".var_export($u, true)." => [\n"
      ."        \"hash\" => ".var_export($h, true).",\n"
      ."    ],\n"
      ."];\n"
    );
  ' "$NEW_USER" "$NEW_HASH" "$CONFIG_FILE"

  ok "credentials saved for user '${NEW_USER}'"
else
  ok "custom credentials already set — skipping"
fi

# ── systemd unit ─────────────────────────────────────────────────────────────
step "systemd service"
cat > "/etc/systemd/system/$SERVICE_NAME.service" <<UNIT
[Unit]
Description=Storm-Breaker (PHP built-in server, reverse-proxied by nginx)
After=network.target

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_USER
WorkingDirectory=$APP_DIR
Environment=PHP_CLI_SERVER_WORKERS=6
ExecStart=$PHP_BIN -S 127.0.0.1:$PHP_PORT -t "$WEB_DIR" "$WEB_DIR/router.php"
Restart=on-failure
RestartSec=2
# Hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=read-only
ReadWritePaths="$WEB_DIR/data" "$WEB_DIR/targets" "$WEB_DIR/log"
UMask=0077

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable "$SERVICE_NAME.service" >/dev/null
systemctl restart "$SERVICE_NAME.service"
sleep 1
if systemctl is-active --quiet "$SERVICE_NAME.service"; then
  ok "service active on 127.0.0.1:$PHP_PORT"
else
  journalctl -u "$SERVICE_NAME.service" --no-pager -n 30 >&2 || true
  die "$SERVICE_NAME.service failed to start"
fi

# ── TLS cert ─────────────────────────────────────────────────────────────────
step "TLS certificate"
CERT_DIR="/etc/ssl/$SERVICE_NAME"
mkdir -p "$CERT_DIR"

if [[ -n "$DOMAIN" && -n "$EMAIL" ]] && command -v certbot >/dev/null 2>&1; then
  say "using Let's Encrypt for $DOMAIN"
  cat > "/etc/nginx/conf.d/${SERVICE_NAME}-acme.conf" <<NGINX
server {
    listen $HTTP_PORT;
    server_name $DOMAIN;
    location /.well-known/acme-challenge/ { root /var/www/html; }
    location / { return 404; }
}
NGINX
  mkdir -p /var/www/html
  nginx -t && systemctl reload nginx
  certbot certonly --webroot -w /var/www/html -d "$DOMAIN" \
    --non-interactive --agree-tos --email "$EMAIL" \
    || die "certbot failed — check domain DNS points at this host"
  CERT_FILE="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
  KEY_FILE="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
  rm -f "/etc/nginx/conf.d/${SERVICE_NAME}-acme.conf"
  ok "issued certificate for $DOMAIN"
else
  if [[ ! -f "$CERT_DIR/fullchain.pem" ]]; then
    warn "generating self-signed cert (no domain or no email given)"
    openssl req -x509 -newkey rsa:4096 -sha256 -days 825 -nodes \
      -keyout "$CERT_DIR/privkey.pem" \
      -out    "$CERT_DIR/fullchain.pem" \
      -subj   "/CN=${DOMAIN:-$BIND_IP}" \
      -addext "subjectAltName=DNS:${DOMAIN:-localhost},IP:$BIND_IP"
    chmod 600 "$CERT_DIR/privkey.pem"
  fi
  CERT_FILE="$CERT_DIR/fullchain.pem"
  KEY_FILE="$CERT_DIR/privkey.pem"
  ok "self-signed cert at $CERT_FILE"
fi

# ── nginx site ───────────────────────────────────────────────────────────────
step "nginx reverse proxy"
SERVER_NAME="${DOMAIN:-$BIND_IP}"
NGINX_CONF_DIR="/etc/nginx/conf.d"
[[ -d /etc/nginx/sites-available ]] && NGINX_CONF_DIR="/etc/nginx/sites-available"

cat > "$NGINX_CONF_DIR/$SERVICE_NAME.conf" <<NGINX
# Storm-Breaker — TLS-terminating reverse proxy → PHP built-in server
#
# router.php already gates every admin surface and normalises paths.
# These nginx rules are belt-and-braces defense-in-depth.

server_tokens off;

server {
    listen      $BIND_IP:$HTTP_PORT;
    server_name $SERVER_NAME;
    return 301 https://\$host\$request_uri;
}

server {
    listen      $BIND_IP:$HTTPS_PORT ssl http2;
    server_name $SERVER_NAME;

    ssl_certificate     $CERT_FILE;
    ssl_certificate_key $KEY_FILE;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5:!3DES;
    ssl_prefer_server_ciphers on;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    autoindex off;

    # ── Security headers ──────────────────────────────────────────────
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options    "nosniff" always;
    add_header X-Frame-Options           "SAMEORIGIN" always;
    add_header Referrer-Policy           "same-origin" always;
    add_header X-Robots-Tag              "noindex, nofollow, noarchive" always;

    client_max_body_size 100m;
    proxy_buffering      off;
    proxy_request_buffering off;

    # ── Defensive path blocks ─────────────────────────────────────────
    # Scoped to admin URL space — cloned pages under /<slug> may include
    # paths like /_next/ or /manifest.json from legitimate upstream sites.

    # Block writable + internal directories
    location ~* ^/(data|log|lib|tools)(/|\$) {
        return 404;
    }

    # Dotfiles (.env, .git, .htaccess, .DS_Store, …)
    location ~ /\.(ht|env|git|svn|hg|bzr|DS_Store) {
        return 404;
    }

    # Internal PHP files that should never be requested directly
    location = /router.php { return 404; }
    location = /auth.php   { return 404; }
    location = /config.php { return 404; }

    # Block root-level data-ish extensions (top-level segment only so
    # /<slug>/whatever.json from cloned upstream still passes through)
    location ~* ^/[^/]+\.(json|ndjson|env|sqlite|sqlite3|db|bak|backup|orig|old|swp|swo|log)\$ {
        return 404;
    }

    location / {
        proxy_pass         http://127.0.0.1:$PHP_PORT;
        proxy_http_version 1.1;
        proxy_set_header   Host              \$host;
        proxy_set_header   X-Real-IP         \$remote_addr;
        proxy_set_header   X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        proxy_set_header   X-Forwarded-Host  \$host;
        proxy_set_header   Upgrade           \$http_upgrade;
        proxy_set_header   Connection        "upgrade";
        proxy_read_timeout 120s;
    }
}
NGINX

# Debian-style: symlink into sites-enabled
if [[ -d /etc/nginx/sites-enabled ]]; then
  ln -sf "$NGINX_CONF_DIR/$SERVICE_NAME.conf" "/etc/nginx/sites-enabled/$SERVICE_NAME"
  rm -f /etc/nginx/sites-enabled/default
fi

nginx -t
systemctl enable nginx >/dev/null 2>&1 || true
systemctl restart nginx
ok "nginx listening on $BIND_IP:$HTTP_PORT, $BIND_IP:$HTTPS_PORT"

# ── Firewall ─────────────────────────────────────────────────────────────────
step "firewall"
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "$HTTP_PORT/tcp"  >/dev/null || true
  ufw allow "$HTTPS_PORT/tcp" >/dev/null || true
  ok "ufw: opened $HTTP_PORT and $HTTPS_PORT"
elif command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-port="$HTTP_PORT/tcp"  >/dev/null || true
  firewall-cmd --permanent --add-port="$HTTPS_PORT/tcp" >/dev/null || true
  firewall-cmd --reload >/dev/null
  ok "firewalld: opened $HTTP_PORT and $HTTPS_PORT"
else
  warn "no active firewall detected — ensure $HTTP_PORT/$HTTPS_PORT are reachable"
fi

# ── SELinux (RHEL) ───────────────────────────────────────────────────────────
if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce)" != "Disabled" ]]; then
  step "SELinux"
  if command -v setsebool >/dev/null 2>&1; then
    setsebool -P httpd_can_network_connect 1 2>/dev/null || true
    ok "setsebool httpd_can_network_connect=1"
  fi
  if command -v semanage >/dev/null 2>&1; then
    semanage port -a -t http_port_t -p tcp "$PHP_PORT" 2>/dev/null \
      || semanage port -m -t http_port_t -p tcp "$PHP_PORT" 2>/dev/null || true
    semanage fcontext -a -t httpd_sys_content_t "$APP_DIR(/.*)?" 2>/dev/null || true
    restorecon -R "$APP_DIR" 2>/dev/null || true
    ok "SELinux contexts applied"
  fi
fi

# ── Health check ─────────────────────────────────────────────────────────────
step "health check"
sleep 1
HEALTH_URL="https://${DOMAIN:-$BIND_IP}:${HTTPS_PORT}/"
if curl -ks --max-time 5 "https://127.0.0.1:$HTTPS_PORT/" | grep -qi 'storm\|login'; then
  ok "panel responds on $HEALTH_URL"
else
  warn "couldn't verify response on $HEALTH_URL — check: journalctl -u $SERVICE_NAME -n 50"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
PANEL_URL="https://${DOMAIN:-$BIND_IP}${HTTPS_PORT:+$([[ $HTTPS_PORT == 443 ]] && echo "" || echo ":$HTTPS_PORT")}"

cat <<SUM

${C_GREEN}${C_BOLD}╭──────────────────────────────────────────────────────────────╮
│                  ⚡ Storm Breaker is live!                   │
╰──────────────────────────────────────────────────────────────╯${C_RESET}

  ${C_BOLD}Open your panel:${C_RESET}  $PANEL_URL

  ${C_DIM}Useful commands:${C_RESET}
    systemctl status $SERVICE_NAME     # check service
    journalctl -u $SERVICE_NAME -f     # live logs
    sudo bash $0 --uninstall           # remove everything
SUM

if [[ -z "$DOMAIN" || -z "$EMAIL" ]]; then
  cat <<WARN

  ${C_YELLOW}Note:${C_RESET} You're using a self-signed certificate.
  Browsers will show a warning on first visit.
  To get a real certificate, rerun the setup with a domain:

    sudo bash deploy.sh
WARN
fi
