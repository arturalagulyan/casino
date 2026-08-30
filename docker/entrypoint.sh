#!/bin/sh
set -e

cd /var/www/html

ROLE="${CONTAINER_ROLE:-app}"

# ---------------------------------------------------------------------------
# Writable directories (storage is a bind mount from the host)
# ---------------------------------------------------------------------------
mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
# Framework caches.
# This script runs as root, so it can always read the (mode 600) .env file.
# The php-fpm workers / queue / scheduler processes are dropped to www-data
# below and rely on the cached config produced here.
# ---------------------------------------------------------------------------
if [ -f .env ]; then
  php artisan config:clear >/dev/null 2>&1 || true
  php artisan package:discover --ansi || true
  php artisan config:cache

  if [ "$ROLE" = "app" ]; then
    php artisan view:cache
    # route:cache fails while any route is a Closure - keep the boot green
    # until the routes are moved into controllers.
    php artisan route:cache || echo "entrypoint: route:cache skipped (closure routes?)"
    php artisan storage:link 2>/dev/null || true
  fi

  chown -R www-data:www-data bootstrap/cache 2>/dev/null || true
else
  echo "entrypoint: WARNING - .env file is missing at /var/www/html/.env"
fi

# ---------------------------------------------------------------------------
# Drop privileges for everything except the php-fpm master (which drops to
# www-data for its worker processes itself).
# ---------------------------------------------------------------------------
if [ "$1" = "php-fpm" ]; then
  exec "$@"
else
  exec su-exec www-data "$@"
fi
