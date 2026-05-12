#!/usr/bin/env bash
# =============================================================================
# Stream Proxy — Fully Automatic Installer for Ubuntu 22.04 / 24.04
# Usage:  bash install.sh <domain> [admin-prefix]
# Example: bash install.sh alpha.445566.org fdxx
#          bash install.sh myproxy.com proxy
# =============================================================================

set -eo pipefail

# ── Parse arguments ──────────────────────────────────────────────────────────
DOMAIN="${1:-}"
PREFIX="${2:-admin}"

if [[ -z "$DOMAIN" ]]; then
    echo "Usage: bash install.sh <domain> [admin-prefix]"
    echo "  domain       — your domain name (must already point to this server)"
    echo "  admin-prefix — URL prefix for admin panel (default: admin)"
    echo ""
    echo "Example: bash install.sh alpha.445566.org fdxx"
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1

WEBROOT="/var/www/streamproxy"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_VER="8.3"
PHP_FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
APP_URL="https://${DOMAIN}"

# ── Auto-generate all secrets ────────────────────────────────────────────────
DB_PASSWORD="$(openssl rand -hex 16)"
ADMIN_PASSWORD="$(openssl rand -hex 8)"
ADMIN_EMAIL="admin@streamproxy.local"
PROXY_KEY="$(openssl rand -hex 32)"
PROXY_IV="$(openssl rand -hex 16)"

echo ""
echo "========================================="
echo "  Stream Proxy Installer"
echo "========================================="
echo "  Domain:  ${DOMAIN}"
echo "  Prefix:  ${PREFIX}"
echo "  WebRoot: ${WEBROOT}"
echo "========================================="
echo ""

# ── 1. System packages ──────────────────────────────────────────────────────
echo "=== [1/9] Installing system packages ==="
apt-get update -qq

apt-get install -y -qq software-properties-common 2>/dev/null
if ! apt-cache show "php${PHP_VER}-fpm" &>/dev/null; then
    echo "  Adding PHP PPA..."
    add-apt-repository -y ppa:ondrej/php 2>/dev/null
    apt-get update -qq
fi

apt-get install -y \
    nginx \
    mysql-server \
    php${PHP_VER}-fpm \
    php${PHP_VER}-cli \
    php${PHP_VER}-mysql \
    php${PHP_VER}-xml \
    php${PHP_VER}-mbstring \
    php${PHP_VER}-curl \
    php${PHP_VER}-zip \
    php${PHP_VER}-gd \
    php${PHP_VER}-bcmath \
    php${PHP_VER}-intl \
    php${PHP_VER}-readline \
    unzip \
    curl \
    git \
    certbot \
    python3-certbot-nginx

if ! command -v composer &>/dev/null; then
    echo "  Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

systemctl enable --now mysql nginx "php${PHP_VER}-fpm" --quiet 2>/dev/null || true
echo "  Done."

# ── 2. MySQL database ───────────────────────────────────────────────────────
echo "=== [2/9] Setting up MySQL ==="
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS streamproxy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'streamproxy'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'streamproxy'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON streamproxy.* TO 'streamproxy'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "  Done."

# ── 3. Laravel project ──────────────────────────────────────────────────────
if [[ ! -f "${WEBROOT}/vendor/autoload.php" ]]; then
    echo "=== [3/9] Installing Laravel 12 ==="
    rm -rf "${WEBROOT}"
    mkdir -p "${WEBROOT}"
    cd /tmp && rm -rf laravel-tmp
    composer create-project "laravel/laravel:^12.0" laravel-tmp --no-interaction --prefer-dist --quiet
    cp -a /tmp/laravel-tmp/. "${WEBROOT}/"
    rm -rf /tmp/laravel-tmp
else
    echo "=== [3/9] Laravel already installed — skipping ==="
fi

cd "${WEBROOT}"

# ── 4. Orchid platform ──────────────────────────────────────────────────────
if ! grep -q 'orchid/platform' composer.json 2>/dev/null; then
    echo "=== [4/9] Installing Orchid Platform ==="
    composer require orchid/platform --no-interaction --quiet
    echo "no" | php artisan orchid:install --quiet
else
    echo "=== [4/9] Orchid already installed — skipping ==="
fi

# ── 5. Copy custom proxy files ──────────────────────────────────────────────
echo "=== [5/9] Installing proxy files ==="

copy_if_exists() {
    local src="$1" dst="$2"
    if [[ -f "${SCRIPT_DIR}/${src}" ]]; then
        mkdir -p "$(dirname "${dst}")"
        cp "${SCRIPT_DIR}/${src}" "${dst}"
    fi
}

copy_dir_if_exists() {
    local src="$1" dst="$2"
    if [[ -d "${SCRIPT_DIR}/${src}" ]]; then
        mkdir -p "${dst}"
        cp -r "${SCRIPT_DIR}/${src}/." "${dst}/"
    fi
}

copy_if_exists "app/Http/Controllers/StreamController.php"       "${WEBROOT}/app/Http/Controllers/StreamController.php"
copy_if_exists "app/Http/Controllers/BackupController.php"       "${WEBROOT}/app/Http/Controllers/BackupController.php"
copy_if_exists "app/Services/LinkCrypt.php"                      "${WEBROOT}/app/Services/LinkCrypt.php"
copy_if_exists "app/Models/Link.php"                             "${WEBROOT}/app/Models/Link.php"
copy_if_exists "app/Providers/ProxyServiceProvider.php"          "${WEBROOT}/app/Providers/ProxyServiceProvider.php"
copy_if_exists "config/proxy.php"                                "${WEBROOT}/config/proxy.php"
copy_if_exists "routes/web.php"                                  "${WEBROOT}/routes/web.php"
copy_if_exists "routes/platform.php"                             "${WEBROOT}/routes/platform.php"
copy_if_exists "routes/stream.php"                               "${WEBROOT}/routes/stream.php"
copy_if_exists "nginx/site.conf"                                 "${WEBROOT}/nginx/site.conf"
copy_if_exists "database/migrations/2026_05_02_000000_create_links_table.php" \
    "${WEBROOT}/database/migrations/2026_05_02_000000_create_links_table.php"

copy_dir_if_exists "app/Orchid" "${WEBROOT}/app/Orchid"
copy_dir_if_exists "resources/views/orchid" "${WEBROOT}/resources/views/orchid"

echo "  Done."

# ── 6. Register ProxyServiceProvider + Stream Routes ─────────────────────────
echo "=== [6/9] Registering providers + routes ==="
PROVIDERS_FILE="${WEBROOT}/bootstrap/providers.php"
if ! grep -q 'ProxyServiceProvider' "${PROVIDERS_FILE}"; then
    sed -i "s|App\\\\Providers\\\\AppServiceProvider::class,|App\\\\Providers\\\\AppServiceProvider::class,\n    App\\\\Providers\\\\ProxyServiceProvider::class,|" "${PROVIDERS_FILE}"
    echo "  ProxyServiceProvider registered."
else
    echo "  Already registered."
fi

APP_BOOT="${WEBROOT}/bootstrap/app.php"
if ! grep -q 'stream.php' "${APP_BOOT}"; then
    # Add Route facade import
    if ! grep -q 'use Illuminate\\Support\\Facades\\Route;' "${APP_BOOT}"; then
        sed -i "s|use Illuminate\\\\Foundation\\\\Application;|use Illuminate\\\\Foundation\\\\Application;\nuse Illuminate\\\\Support\\\\Facades\\\\Route;|" "${APP_BOOT}"
    fi
    # Add stream routes via then callback
    sed -i "s|health: '/up',|health: '/up',\n        then: function () {\n            Route::middleware('web')->group(base_path('routes/stream.php'));\n        },|" "${APP_BOOT}"
    echo "  Stream routes registered."
else
    echo "  Stream routes already registered."
fi

# ── 7. Configure .env ────────────────────────────────────────────────────────
echo "=== [7/9] Configuring .env ==="
[[ ! -f "${WEBROOT}/.env" ]] && cp "${WEBROOT}/.env.example" "${WEBROOT}/.env"

sed -i "s|APP_NAME=.*|APP_NAME=StreamProxy|"          "${WEBROOT}/.env"
sed -i "s|APP_ENV=.*|APP_ENV=production|"              "${WEBROOT}/.env"
sed -i "s|APP_DEBUG=.*|APP_DEBUG=false|"               "${WEBROOT}/.env"
sed -i "s|APP_URL=.*|APP_URL=${APP_URL}|"              "${WEBROOT}/.env"
sed -i "s|DB_CONNECTION=.*|DB_CONNECTION=mysql|"        "${WEBROOT}/.env"
sed -i "s|.*DB_HOST=.*|DB_HOST=127.0.0.1|"            "${WEBROOT}/.env"
sed -i "s|.*DB_PORT=.*|DB_PORT=3306|"                  "${WEBROOT}/.env"
sed -i "s|.*DB_DATABASE=.*|DB_DATABASE=streamproxy|"   "${WEBROOT}/.env"
sed -i "s|.*DB_USERNAME=.*|DB_USERNAME=streamproxy|"   "${WEBROOT}/.env"
sed -i "s|.*DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" "${WEBROOT}/.env"

php artisan key:generate --force --quiet

if ! grep -q 'PROXY_ENCRYPTION_KEY' "${WEBROOT}/.env"; then
    echo "" >> "${WEBROOT}/.env"
    echo "PROXY_ENCRYPTION_KEY=${PROXY_KEY}" >> "${WEBROOT}/.env"
    echo "PROXY_IV=${PROXY_IV}" >> "${WEBROOT}/.env"
else
    sed -i "s|PROXY_ENCRYPTION_KEY=.*|PROXY_ENCRYPTION_KEY=${PROXY_KEY}|" "${WEBROOT}/.env"
    sed -i "s|PROXY_IV=.*|PROXY_IV=${PROXY_IV}|"                          "${WEBROOT}/.env"
fi

if ! grep -q 'PLATFORM_PREFIX' "${WEBROOT}/.env"; then
    echo "PLATFORM_PREFIX=${PREFIX}" >> "${WEBROOT}/.env"
else
    sed -i "s|PLATFORM_PREFIX=.*|PLATFORM_PREFIX=${PREFIX}|" "${WEBROOT}/.env"
fi

echo "  Done."

# ── 8. Migrations + Admin user ───────────────────────────────────────────────
echo "=== [8/9] Running migrations + creating admin ==="
php artisan migrate --force --quiet

php artisan tinker --execute="
\$user = \Orchid\Platform\Models\User::where('email', '${ADMIN_EMAIL}')->first();
if (!\$user) {
    \$user = new \App\Models\User();
    \$user->name = 'Admin';
    \$user->email = '${ADMIN_EMAIL}';
    \$user->password = bcrypt('${ADMIN_PASSWORD}');
    \$user->permissions = [
        'platform.index' => true,
        'platform.systems.roles' => true,
        'platform.systems.users' => true,
        'platform.systems.attachment' => true,
    ];
    \$user->save();
    echo 'Admin user created.';
} else {
    \$user->password = bcrypt('${ADMIN_PASSWORD}');
    \$user->permissions = [
        'platform.index' => true,
        'platform.systems.roles' => true,
        'platform.systems.users' => true,
        'platform.systems.attachment' => true,
    ];
    \$user->save();
    echo 'Admin user updated.';
}
"

echo "  Done."

# ── 9. Nginx + SSL + Permissions ─────────────────────────────────────────────
echo "=== [9/9] Configuring Nginx + SSL ==="

sed -e "s|unix:/run/php/php8.3-fpm.sock|unix:${PHP_FPM_SOCK}|g" \
    -e "s|server_name _;|server_name ${DOMAIN};|g" \
    "${WEBROOT}/nginx/site.conf" > /etc/nginx/sites-available/streamproxy

ln -sf /etc/nginx/sites-available/streamproxy /etc/nginx/sites-enabled/streamproxy
rm -f /etc/nginx/sites-enabled/default

nginx -t && systemctl reload nginx

chown -R www-data:www-data "${WEBROOT}/storage" "${WEBROOT}/bootstrap/cache"
chmod -R 775 "${WEBROOT}/storage" "${WEBROOT}/bootstrap/cache"
mkdir -p "${WEBROOT}/storage/app/temp"
chown www-data:www-data "${WEBROOT}/storage/app/temp"

certbot --nginx --non-interactive --agree-tos --register-unsafely-without-email --redirect -d "${DOMAIN}" || {
    echo "  WARNING: SSL setup failed. You can run it manually later:"
    echo "  certbot --nginx -d ${DOMAIN}"
}

systemctl enable --now certbot.timer 2>/dev/null || true
echo "  Done."

# ── Print credentials ────────────────────────────────────────────────────────
echo ""
echo "========================================="
echo "  Installation Complete!"
echo "========================================="
echo ""
echo "  Admin Panel: ${APP_URL}/${PREFIX}/login"
echo "  Email:       ${ADMIN_EMAIL}"
echo "  Password:    ${ADMIN_PASSWORD}"
echo ""
echo "  MySQL User:  streamproxy"
echo "  MySQL Pass:  ${DB_PASSWORD}"
echo "  MySQL DB:    streamproxy"
echo ""
echo "  SAVE THESE CREDENTIALS!"
echo "========================================="
echo ""
