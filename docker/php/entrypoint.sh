#!/bin/sh
set -e

echo "==> Running migrations..."
php artisan migrate --force --no-interaction

echo "==> Starting php-fpm..."
exec php-fpm
