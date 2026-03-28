#!/bin/sh
set -e

echo "Running migrations..."
php artisan migrate --force

echo "Optimising..."
php artisan config:cache
php artisan route:cache

exec php-fpm
