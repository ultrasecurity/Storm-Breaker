#!/usr/bin/env bash
# Storm-Breaker — server deployment helper.
# Run from the project root after cloning/uploading the repo to your server.

set -e

GRY='\033[1;30m'
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

info "Checking PHP availability"
if ! command -v php >/dev/null 2>&1; then
    err "php is not installed. Install PHP 7.4 or later before running this script."
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

info "Checking required PHP extensions"
REQUIRED_EXT=(json session)
for ext in "${REQUIRED_EXT[@]}"; do
    if ! php -m | grep -qi "^${ext}$"; then
        err "Missing PHP extension: ${ext}"
        exit 1
    fi
done
ok "Required extensions present"

info "Preparing writable paths"
WRITABLE=(
    "$WEB/log"
    "$WEB/images"
    "$WEB/sounds"
)
for d in "${WRITABLE[@]}"; do
    mkdir -p "$d"
    chmod 0755 "$d"
done

# result.txt files inside each template must be writable for handlers.
find "$WEB/templates" -name "result.txt" -type f -exec chmod 0664 {} \;

# Token file used for the "remember me" cookie.
if [ -f "$WEB/check-c.json" ]; then
    chmod 0664 "$WEB/check-c.json"
fi
ok "File permissions set"

info "Checking credentials"
if grep -q '"password" => "admin"' "$WEB/config.php"; then
    warn "Default admin password is still in storm-web/config.php — change it before going live."
fi

cat <<EOF

${GRN}Deployment preparation complete.${RST}

Next steps:
  1. Point your web server's document root at:
       ${WEB}
     (or upload the ${GRY}contents${RST} of storm-web/ to your public_html.)
  2. Make sure HTTPS is enabled — several capabilities require a secure context.
  3. Edit ${GRY}storm-web/config.php${RST} to set your own username and password.

EOF
