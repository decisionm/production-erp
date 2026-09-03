#!/usr/bin/env bash
#
# A FULL DATABASE DUMP, ON DEMAND, BEFORE A MANUAL DATA OPERATION.
#
# scripts/deploy.sh already takes exactly this dump before every migration.
# This script is that same dump, callable on its own, for the manual
# master-data workflows that change live rows outside a deploy — the ones
# DEC-20260903-001 and DEC-20260903-002 require a backup before
# (.github/workflows/correct-consumption-item.yml and remove-wip-demo-rows.yml
# both run it, and both refuse to write if it fails).
#
# WHY IT IS A COPY OF deploy.sh's BLOCK AND NOT A CALL INTO IT. deploy.sh does
# one thing: it closes the app, rsyncs, migrates and reopens. Exposing a
# "just the backup part" entry point into it would mean a future edit to the
# deploy path could silently change what a data operation backs up, or worse,
# that calling it wrongly would put the factory into maintenance. The dump
# itself is thirty lines; the coupling would cost more than the duplication.
# The RULES below are deliberately identical, and any change to one belongs in
# both:
#
#   * MySQL/MariaDB only, and it STOPS rather than skipping — a "no MySQL
#     here, carry on" branch hands back the unbacked-up write it exists to
#     prevent;
#   * the dump lands in $HOME/backups/erp and NEVER in ../backups, which on
#     this host sits inside a live WordPress site's document root; a full dump
#     carries password hashes, customers and purchase rates (FC-06);
#   * the password goes through MYSQL_PWD, never argv, which `ps` shows to
#     every other user on a shared host;
#   * exit 0 is not proof: a zero-byte file is a false sense of a backup, so
#     the size is checked and a partial file is removed.
#
# Run from backend/. Prints the path it wrote on the last line.
set -euo pipefail

if [ ! -f .env ]; then
  echo "ERROR: no .env in $(pwd). Run this from the backend/ directory of the deployed app." >&2
  exit 1
fi

# Read one value from .env without sourcing it: never `source` (a value
# containing a command substitution would EXECUTE), never `export $(...)`.
# Everything after the first '=' is the value; one layer of matching outer
# quotes is stripped, as phpdotenv does; a trailing CR from a Windows-edited
# or hPanel-edited file is stripped before the quotes.
env_value() {
  local line
  line="$(grep -m1 -E "^[[:space:]]*$1=" .env || true)"
  if [ -z "$line" ]; then return 0; fi
  line="${line#*=}"
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
DB_HOST="$(env_value DB_HOST)";  DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)";  DB_PORT="${DB_PORT:-3306}"

if [ "$DB_CONNECTION" != "mysql" ] && [ "$DB_CONNECTION" != "mariadb" ]; then
  echo "ERROR: DB_CONNECTION in .env is '${DB_CONNECTION:-(unset)}', not mysql/mariadb. This script" >&2
  echo "       only knows how to back up MySQL/MariaDB, and the operation that called it must" >&2
  echo "       NOT proceed without a backup. Take your own dump and extend this block if this" >&2
  echo "       instance genuinely is not MySQL — do not delete the check." >&2
  exit 1
fi

if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
  echo "ERROR: DB_DATABASE and/or DB_USERNAME are missing from $(pwd)/.env, so nothing can be" >&2
  echo "       backed up. Nothing has been written by the caller." >&2
  exit 1
fi

# Hostinger ships MariaDB's client on some plans, where the tool is named
# mariadb-dump; both take the flags used below.
DUMP_BIN=""
for c in mysqldump mariadb-dump; do
  if command -v "$c" >/dev/null 2>&1; then DUMP_BIN="$c"; break; fi
done
if [ -z "$DUMP_BIN" ]; then
  echo "ERROR: neither mysqldump nor mariadb-dump is on PATH, so no backup could be taken." >&2
  echo "       Install the MySQL client tools (hPanel → Advanced → SSH) or take a dump by hand" >&2
  echo "       from phpMyAdmin. The caller must not write without one." >&2
  exit 1
fi

BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/erp}"
if ! mkdir -p "$BACKUP_DIR" 2>/dev/null || ! : > "$BACKUP_DIR/.backup-write-probe" 2>/dev/null; then
  rm -f "$BACKUP_DIR/.backup-write-probe" 2>/dev/null || true
  echo "ERROR: '$BACKUP_DIR' could not be created or written, so no backup can be taken. This" >&2
  echo "       does NOT fall back to ../backups on purpose: on this host that path is inside a" >&2
  echo "       live WordPress site's document root, and a full dump carries password hashes," >&2
  echo "       customers and purchase rates (FC-06). Usual cause is a full disk quota; check it," >&2
  echo "       or set BACKUP_DIR to another directory OUTSIDE any web root." >&2
  exit 1
fi
rm -f "$BACKUP_DIR/.backup-write-probe"

BACKUP_FILE="$BACKUP_DIR/erp-db-$(date +%Y%m%d-%H%M%S).sql"

echo "==> Backing up '$DB_DATABASE' to $BACKUP_FILE"
if ! MYSQL_PWD="$DB_PASSWORD" "$DUMP_BIN" \
      --single-transaction --quick \
      --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
      "$DB_DATABASE" > "$BACKUP_FILE"; then
  rm -f "$BACKUP_FILE"   # a partial dump must not sit there looking like a backup
  echo "ERROR: $DUMP_BIN failed (its own error is just above), so there is no backup and the" >&2
  echo "       caller must not write. Usual causes, in order:" >&2
  echo "         * wrong DB credentials in $(pwd)/.env, or a full disk quota;" >&2
  echo "         * 'Access denied; you need the PROCESS privilege ... when trying to dump" >&2
  echo "           tablespaces' — MySQL 8.0.21+ wants a privilege shared-hosting DB users are" >&2
  echo "           rarely granted. The fix is to add --no-tablespaces to the $DUMP_BIN call" >&2
  echo "           here AND in scripts/deploy.sh; it is not added pre-emptively because older" >&2
  echo "           clients reject the flag outright." >&2
  exit 1
fi

# Exit status alone is not sufficient — a dump tool can exit 0 having written
# nothing, and a zero-byte file is not a backup, it is a false sense of one.
if [ ! -s "$BACKUP_FILE" ]; then
  rm -f "$BACKUP_FILE"
  echo "ERROR: $DUMP_BIN exited successfully but produced an empty file, so there is no usable" >&2
  echo "       backup and the caller must not write." >&2
  exit 1
fi

echo "==> Backup complete ($(wc -c < "$BACKUP_FILE" | tr -d ' ') bytes)"
echo "$BACKUP_FILE"
