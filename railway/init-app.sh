#!/usr/bin/env sh
set -eu

php artisan config:clear
php artisan route:clear
php artisan view:clear

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
