#!/usr/bin/env bash
set -euo pipefail

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --ignore-scripts
npm run build
php artisan optimize
