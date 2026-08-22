#!/usr/bin/env bash
#
# Server-side deploy tasks, run from the backend/ directory AFTER the code
# has been synced onto the server (by the GitHub Actions workflow, or by hand
# over SSH). Safe to re-run — every step is idempotent.
#
#   Manual use:  cd ~/domains/actech.co.in/public_html/erp_app/backend && bash scripts/deploy.sh
#
set -euo pipefail

# The app's composer.lock resolves to packages requiring PHP >= 8.4.1 (symfony
# 8.1, spatie/activitylog 5), while Hostinger's default CLI `php` is an older
# alt-php (8.2 here). Pick an 8.4+ binary explicitly so composer and artisan
# run under a compatible version. Override with PHP_BIN=... if needed.
# The search itself lives in scripts/pick-php.sh so the workflows can ask the
# same question instead of pinning a literal path (issue #167). The fallback
# keeps this script working on a server whose copy predates that file.
PHP="$(bash "$(dirname "$0")/pick-php.sh" 2>/dev/null || echo /opt/alt/php84/usr/bin/php)"

echo "==> Deploying from $(pwd) using $($PHP -v | head -1)"

# Rebuild PHP dependencies (vendor/ is excluded from rsync and lives only on
# the server). Bump memory in case the shared host caps composer.
$PHP -d memory_limit=-1 "$(command -v composer)" install --no-dev --optimize-autoloader

# --- Take a database backup BEFORE migrating -------------------------------
#
# A migration is not undoable on a live factory database. down() methods rot
# unnoticed because nothing exercises them, and MySQL cannot roll back DDL at
# all, so a migration that dies halfway leaves a schema that is neither the old
# one nor the new one. The only honest recovery is a dump taken minutes before.
#
# Therefore this is a HARD gate, not a courtesy: if a backup cannot be produced,
# the deploy STOPS here, with the previous release still serving and the schema
# untouched. Refusing to deploy costs an hour; discovering after the migration
# that no backup exists costs the factory its books.

# Read one value out of .env the way artisan does. Deliberately not `cut -d= -f2`
# (a password containing '=' would be silently truncated, and a truncated
# password produces a failed dump at best), and never `eval`/`export $(...)`
# (a value containing $(...) or backticks would EXECUTE during deploy).
# Everything after the first '=' is the value; one layer of matching outer
# quotes is stripped, as phpdotenv does. An unquoted '#' is kept rather than
# treated as a trailing comment — phpdotenv keeps it too, and a password is far
# more likely to contain '#' than a .env line is to carry an inline comment.
env_value() {
  local line
  line="$(grep -m1 -E "^[[:space:]]*$1=" .env || true)"
  if [ -z "$line" ]; then return 0; fi
  line="${line#*=}"
  # A .env edited through hPanel's file manager or uploaded from Windows ends
  # its lines with CRLF. A trailing \r inside a password turns this into a
  # credentials failure that reports itself as "wrong password", which is the
  # one wrong tree to bark up. Strip it before the quotes, so "secret"\r works.
  line="${line%$'\r'}"
  case "$line" in
    \"*\") line="${line#\"}"; line="${line%\"}" ;;
    \'*\') line="${line#\'}"; line="${line%\'}" ;;
  esac
  printf '%s' "$line"
}

DB_CONNECTION="$(env_value DB_CONNECTION)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"
# Defaults match config/database.php's mysql block, so an .env that omits these
# (Hostinger's does not, but a fresh one might) is backed up from the same place
# artisan is about to migrate.
DB_HOST="$(env_value DB_HOST)";  DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)";  DB_PORT="${DB_PORT:-3306}"

# Stop rather than skip. A "no MySQL here, carry on" branch would quietly hand
# back exactly the unbacked-up migration this block exists to prevent — and the
# one deploy where that branch fires wrongly is the one that needed the dump.
if [ "$DB_CONNECTION" != "mysql" ] && [ "$DB_CONNECTION" != "mariadb" ]; then
  echo "ERROR: DB_CONNECTION in .env is '${DB_CONNECTION:-(unset, artisan would use sqlite)}', not mysql/mariadb." >&2
  echo "       This deploy script only knows how to back up MySQL/MariaDB, and it will not run" >&2
  echo "       migrations against a database it could not back up. Fix DB_CONNECTION in" >&2
  echo "       $(pwd)/.env if it is wrong; if this instance genuinely is not MySQL, take your" >&2
  echo "       own dump and extend this block — do not delete it." >&2
  exit 1
fi

if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
  echo "ERROR: DB_DATABASE and/or DB_USERNAME are missing from $(pwd)/.env, so the database" >&2
  echo "       cannot be backed up and migrations have not been run. The SCHEMA is untouched," >&2
  echo "       but the app is CLOSED (artisan down ran before the rsync) and the new code is" >&2
  echo "       already on disk. The factory is at 503 until this is fixed and the workflow" >&2
  echo "       re-run — it is idempotent and takes a fresh backup. Do NOT 'artisan up' by hand." >&2
  exit 1
fi

# Hostinger ships MariaDB's client on some plans, where the tool is named
# mariadb-dump; both take the flags used below.
DUMP_BIN=""
for c in mysqldump mariadb-dump; do
  if command -v "$c" >/dev/null 2>&1; then DUMP_BIN="$c"; break; fi
done
if [ -z "$DUMP_BIN" ]; then
  echo "ERROR: neither mysqldump nor mariadb-dump is on PATH, so no pre-migration backup could" >&2
  echo "       be taken. The deploy has STOPPED before 'artisan migrate' — the code on the" >&2
  echo "       server is now newer than its schema and nothing has been migrated. The app is" >&2
  echo "       CLOSED and the factory is at 503 until this is fixed and the workflow re-run." >&2
  echo "       Do NOT 'artisan up' by hand. Install the MySQL client tools (hPanel →" >&2
  echo "       Advanced → SSH) or take a dump by hand from phpMyAdmin and re-run this script." >&2
  exit 1
fi

# WHERE the dump lands is a security decision, not a convenience one. This used
# to be a bare relative ../backups, which resolves to $DEPLOY_PATH/backups. That
# was harmless while the app lived OUTSIDE the web root — but since the
# 19-Aug-2026 migration the app sits INSIDE public_html of a live WordPress site,
# so ../backups put seven rolling FULL dumps (password hashes, customers, and
# purchase rates — Owner/Accounts only under FC-06) at a URL path on someone
# else's site. They were not actually reachable (403, from that site's .htaccess
# plus a host-level .sql denylist), but that is protection owned by an unrelated
# site and regenerated routinely by WP/caching plugins. $HOME is never web-served.
#
# THERE IS NO LONGER A FALLBACK TO ../backups. It was kept at first on the
# reasoning that a hardening improvement must not cause a 503 — but the thing it
# fell back TO is the exposure this block exists to end, and "only when something
# else already went wrong" is exactly when nobody is reading the WARN line. A
# path under another site's document root is not a safety net; it is the
# original defect, re-armed silently.
#
# Failing instead does not trade the 503 back in, because the workflow asks this
# same question BEFORE `artisan down` (deploy.yml's "Check the backup directory
# is writable"): a host that cannot write here fails the run with the floor still
# serving. Reaching the branch below therefore means the directory became
# unusable DURING the window — disk quota, most likely — which is a real
# failure and belongs at the same hard stop as every other backup failure here.
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/erp}"
if ! mkdir -p "$BACKUP_DIR" 2>/dev/null || ! : > "$BACKUP_DIR/.deploy-write-probe" 2>/dev/null; then
  rm -f "$BACKUP_DIR/.deploy-write-probe" 2>/dev/null || true
  echo "ERROR: '$BACKUP_DIR' could not be created or written, so no pre-migration backup can be" >&2
  echo "       taken. The deploy has STOPPED before 'artisan migrate' — the schema is untouched," >&2
  echo "       but the app is CLOSED and the factory is at 503 until this is fixed and the" >&2
  echo "       workflow re-run (it is idempotent and takes a fresh backup). Do NOT 'artisan up'" >&2
  echo "       by hand. This does NOT fall back to ../backups on purpose: on this host that path" >&2
  echo "       is inside a live WordPress site's document root, and a full dump carries password" >&2
  echo "       hashes, customers and purchase rates (FC-06). Usual cause is a full disk quota;" >&2
  echo "       check it, or set BACKUP_DIR to another directory OUTSIDE any web root." >&2
  exit 1
fi
rm -f "$BACKUP_DIR/.deploy-write-probe"
BACKUP_FILE="$BACKUP_DIR/erp-db-$(date +%Y%m%d-%H%M%S).sql"

echo "==> Backing up '$DB_DATABASE' to $BACKUP_FILE before migrating"
# --single-transaction: dump from one consistent snapshot without locking the
#   floor out of the app mid-shift.  --quick: stream rows instead of buffering a
#   whole table into RAM, which a shared host will not give us.
# The password goes through MYSQL_PWD, scoped to this one command, and never
# into argv: `-pSECRET` is visible in `ps` to every other user on a shared host.
# `if !` rather than a bare redirect because `set -e` (top of file) would
# otherwise kill the script before the message below could explain anything.
if ! MYSQL_PWD="$DB_PASSWORD" "$DUMP_BIN" \
      --single-transaction --quick \
      --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
      "$DB_DATABASE" > "$BACKUP_FILE"; then
  rm -f "$BACKUP_FILE"   # a partial dump must not sit there looking like a backup
  echo "ERROR: $DUMP_BIN failed (its own error is just above), so there is no pre-migration" >&2
  echo "       backup. The deploy has STOPPED before 'artisan migrate': the schema is" >&2
  echo "       untouched, but the app is CLOSED and the factory is at 503 until this is fixed" >&2
  echo "       and the workflow re-run. Do NOT 'artisan up' by hand. Usual causes, in order:" >&2
  echo "         * wrong DB credentials in $(pwd)/.env, or a full disk quota;" >&2
  echo "         * 'Access denied; you need the PROCESS privilege ... when trying to dump" >&2
  echo "           tablespaces' — MySQL 8.0.21+ wants a privilege shared-hosting DB users are" >&2
  echo "           rarely granted. The fix is to add --no-tablespaces to the $DUMP_BIN call in" >&2
  echo "           this script; it is not added pre-emptively because older clients reject the" >&2
  echo "           flag outright, which would break every deploy in the other direction." >&2
  exit 1
fi

# Exit status alone is not sufficient — a dump tool can exit 0 having written
# nothing, and a zero-byte file is not a backup, it is a false sense of one.
if [ ! -s "$BACKUP_FILE" ]; then
  rm -f "$BACKUP_FILE"
  echo "ERROR: $DUMP_BIN exited successfully but produced an empty file, so there is no" >&2
  echo "       usable backup. The deploy has STOPPED before 'artisan migrate' — nothing was" >&2
  echo "       migrated. Check that user '$DB_USERNAME' can actually read '$DB_DATABASE'." >&2
  exit 1
fi

# Apparent size via ls, not `du -h`: du reports allocated blocks, so it prints a
# reassuring "4.0K" for a dump that is actually 200 bytes of header — and a dump
# far smaller than last week's is the one thing worth noticing in this log line.
echo "==> Backup OK: $(basename "$BACKUP_FILE") ($(ls -lh "$BACKUP_FILE" | awk '{print $5}'), $(wc -c < "$BACKUP_FILE" | tr -d ' ') bytes)"
echo "    restore with: mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p $DB_DATABASE < $BACKUP_FILE"

# Keep the last 7, delete older. The glob is this script's own filename pattern
# and nothing else, so a hand-made dump someone parked in $BACKUP_DIR before a
# risky upgrade is never reaped by an unrelated deploy. Pruning happens only
# AFTER a dump succeeded — a failed backup must not also destroy the history.
OLD_BACKUPS="$(ls -1t "$BACKUP_DIR"/erp-db-*.sql 2>/dev/null | tail -n +8 || true)"
if [ -n "$OLD_BACKUPS" ]; then
  printf '%s\n' "$OLD_BACKUPS" | while IFS= read -r old; do
    rm -f "$old"
    echo "    pruned old backup: $(basename "$old")"
  done
fi

# Apply any new migrations. --force is required in production (no prompt).
$PHP artisan migrate --force

# Master data the app cannot function without, loaded by name on every deploy.
#
# A migration creates the downtime_reasons and factory_settings TABLES; only a
# seeder puts the factory's own rows in them. Because deploy.sh ran migrations
# and nothing else, the live instance had both tables empty for weeks — so the
# Shift Floor offered no downtime reason to pick and the System Config page was
# blank, while every developer machine looked fine because a full `db:seed` had
# been run there once. That asymmetry is the bug: "works locally" was true and
# meaningless.
#
# Named explicitly, never a bare `db:seed`. A bare seed would pull in
# DatabaseSeeder, which creates a test user and the demo factory
# (BottleManufacturingDemoSeeder) — fabricated products and stations in a live
# company's books. Any seeder added to this list must be idempotent AND carry no
# invented transactional data; all three below use firstOrCreate/findOrCreate
# and refuse to overwrite a value someone on site has since edited.
for seeder in PermissionSeeder ShiftSeeder ProductionConfigurationDefaultsSeeder; do
  $PHP artisan db:seed --class="$seeder" --force
done

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

# --- Reopen the app --------------------------------------------------------
#
# The workflow closed it (`artisan down`) BEFORE the rsync, so the floor never
# meets code whose migrations have not run. This is the ONLY place it reopens,
# and it is the last line for a reason: `set -e` means any failure above —
# composer, the backup gate, migrate, a seeder, a cache rebuild — exits before
# this runs and the app STAYS DOWN. A 503 the operator must clear beats an
# instance quietly serving writes against a schema that never arrived
# (11-Aug-2026: rsync succeeded, migrate died, nothing rolled back).
#
# Idempotent: `up` on an app that is already up is a no-op, so re-running
# deploy.sh by hand is still safe.
$PHP artisan up

echo "==> Deploy complete"
