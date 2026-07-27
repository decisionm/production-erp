#!/usr/bin/env bash
#
# Server-side deploy tasks, run from the backend/ directory AFTER the code
# has been synced onto the server (by the GitHub Actions workflow, or by hand
# over SSH). Safe to re-run — every step is idempotent.
#
#   Manual use:  cd ~/domains/erpdemo.amrtech.in/erp/backend && bash scripts/deploy.sh
#
set -euo pipefail

# The app's composer.lock resolves to packages requiring PHP >= 8.4.1 (symfony
# 8.1, spatie/activitylog 5), while Hostinger's default CLI `php` is an older
# alt-php (8.2 here). Pick an 8.4+ binary explicitly so composer and artisan
# run under a compatible version. Override with PHP_BIN=... if needed.
pick_php() {
  if [ -n "${PHP_BIN:-}" ]; then echo "$PHP_BIN"; return; fi
  for c in php8.4 php8.5 /opt/alt/php84/usr/bin/php /opt/alt/php85/usr/bin/php \
           php8.3 /opt/alt/php83/usr/bin/php; do
    if command -v "$c" >/dev/null 2>&1 || [ -x "$c" ]; then echo "$c"; return; fi
  done
  echo php   # fall back to default and let composer's platform check complain
}
PHP="$(pick_php)"

echo "==> Deploying from $(pwd) using $($PHP -v | head -1)"

# Rebuild PHP dependencies (vendor/ is excluded from rsync and lives only on
# the server). Bump memory in case the shared host caps composer.
$PHP -d memory_limit=-1 "$(command -v composer)" install --no-dev --optimize-autoloader

# Apply any new migrations. --force is required in production (no prompt).
$PHP artisan migrate --force

# Ensure the public storage symlink exists (no-op if already linked).
$PHP artisan storage:link || true

# Session lifetime: a factory shift plus margin (12 h). The production .env
# predates that decision with 120 and .env is never rsynced, so normalize it
# here (idempotent) before the config cache is rebuilt.
if grep -q '^SESSION_LIFETIME=' .env; then
  sed -i 's/^SESSION_LIFETIME=.*/SESSION_LIFETIME=720/' .env
else
  printf '\nSESSION_LIFETIME=720\n' >> .env
fi

# Rebuild the cached config/routes/views against the new code + current .env.
# clear first so stale caches from the previous release can't linger.
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo "==> Deploy complete"
