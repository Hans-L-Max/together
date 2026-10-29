#!/bin/sh
# Prepares the Laravel app before handing over to the container command
# (php-fpm, queue worker or scheduler).
set -eu

cd /var/www/html

# Generating the very first APP_KEY must work without one.
if [ "$#" -ge 3 ] && [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "key:generate" ]; then
    exec "$@"
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Generate one with:" >&2
    echo "  docker compose run --rm --no-deps app php artisan key:generate --show" >&2
    echo "and put it into your .env file." >&2
    exit 1
fi

# The storage volume starts empty on first boot.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

php artisan optimize --ansi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --ansi
fi

# Signals the healthcheck that the app is ready to serve.
touch /tmp/ready

exec "$@"
