#!/usr/bin/env bash
#
# Server-side deploy tasks, run from the backend/ directory AFTER the code
# has been synced onto the server (by the GitHub Actions workflow, or by hand
# over SSH). Safe to re-run — every step is idempotent.
#
#   Manual use:  cd ~/domains/amrtech.in/erp/backend && bash scripts/deploy.sh
#
set -euo pipefail

echo "==> Deploying from $(pwd)"

# Rebuild PHP dependencies (vendor/ is excluded from rsync and lives only on
# the server). Bump memory in case the shared host caps composer.
php -d memory_limit=-1 "$(command -v composer)" install --no-dev --optimize-autoloader

# Apply any new migrations. --force is required in production (no prompt).
php artisan migrate --force

# Ensure the public storage symlink exists (no-op if already linked).
php artisan storage:link || true

# Rebuild the cached config/routes/views against the new code + current .env.
# clear first so stale caches from the previous release can't linger.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Deploy complete"
