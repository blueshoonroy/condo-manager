#!/usr/bin/env bash
set -euo pipefail

# Run from the Forge site's deployment directory after checkout.
# With Forge zero-downtime deployments, keep its release/activation wrappers.
${FORGE_COMPOSER:-composer} install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --ignore-scripts
npm run build
${FORGE_PHP:-php} artisan migrate --force
${FORGE_PHP:-php} artisan optimize
${FORGE_PHP:-php} artisan queue:restart
