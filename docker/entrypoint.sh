#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Prefer DATABASE_URL scheme (Render) over any leftover DB_CONNECTION value.
# This avoids accidentally booting with MySQL config when using Render Postgres.
if [ -n "${DATABASE_URL:-}" ]; then
  case "${DATABASE_URL}" in
    postgres://*|postgresql://*)
      export DB_CONNECTION="pgsql"
      ;;
    mysql://*)
      export DB_CONNECTION="mysql"
      ;;
    sqlite://*)
      export DB_CONNECTION="sqlite"
      ;;
  esac
fi

# Render sets $PORT. Make Apache listen on it.
if [ -n "${PORT:-}" ]; then
  sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
  sed -ri "s/<VirtualHost \\*:80>/<VirtualHost \\*:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache || true
chown -R www-data:www-data storage bootstrap/cache || true

if [ "${APP_ENV:-}" = "production" ] && [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is not set (required in production)." >&2
  exit 1
fi

php artisan config:clear || true
php artisan view:clear || true

php artisan package:discover --ansi

echo "DB_CONNECTION=${DB_CONNECTION:-<unset>}"
echo "DB_HOST=${DB_HOST:-<unset>}"
echo "DB_PORT=${DB_PORT:-<unset>}"
echo "DB_DATABASE=${DB_DATABASE:-<unset>}"
echo "DB_USERNAME=${DB_USERNAME:-<unset>}"
echo "DATABASE_URL=${DATABASE_URL:+<set>}"

php -r 'echo "PDO_DRIVERS=" . implode(",", PDO::getAvailableDrivers()) . PHP_EOL;' || true
php -r 'foreach (["pdo","pdo_pgsql","pgsql","pdo_mysql","mysqlnd","pdo_sqlite","sqlite3"] as $e) { echo $e . "=" . (extension_loaded($e) ? "1" : "0") . PHP_EOL; }' || true

if [ "${RUN_STORAGE_LINK:-1}" = "1" ]; then
  php artisan storage:link || true
fi

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force
fi

# Avoid route:cache because web.php has a closure route.
php artisan config:cache

exec "$@"
