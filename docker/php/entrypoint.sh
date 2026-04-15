#!/bin/sh
set -e

echo "==> Waiting for database..."
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 2
done
echo "==> Database ready."

echo "==> Running migrations..."
php artisan migrate --force --no-interaction

echo "==> Starting php-fpm..."
exec php-fpm
