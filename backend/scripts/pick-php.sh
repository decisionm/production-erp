#!/usr/bin/env bash
#
# Print the PHP binary this app must be run with, and nothing else.
#
# WHY THIS IS ITS OWN FILE (issue #167). The rule "use a PHP >= 8.4.1" was
# written twice: scripts/deploy.sh searched a candidate list, while the
# workflows pinned the literal /opt/alt/php84/usr/bin/php in 17 places across
# 16 files. Two spellings of one rule is drift waiting to happen, and the
# failure is not theoretical — Hostinger owns that path. A host PHP upgrade
# that moves or renames it takes out the deploy AND every diagnostic and
# remediation workflow at the same moment, including read-server-log, which is
# the tool you would reach for to find out why. So the search lives here, once,
# and everything else asks.
#
# The app's composer.lock resolves to packages requiring PHP >= 8.4.1 (symfony
# 8.1, spatie/activitylog 5), while Hostinger's default CLI `php` is older
# (7.4 on the box as of 19-Aug-2026). Picking the wrong one is not a subtle
# failure: composer refuses on its platform check.
#
# Contract: prints ONE line to stdout — a command name or an absolute path.
# Never prints anything else to stdout, so `PHP=$(bash scripts/pick-php.sh)`
# is safe. Always exits 0; a host with no suitable PHP still prints `php` and
# lets composer's platform check produce the real, legible error rather than
# this script inventing one.
#
# Override with PHP_BIN=/path/to/php for a one-off run on an odd host.
set -euo pipefail

if [ -n "${PHP_BIN:-}" ]; then
    printf '%s\n' "$PHP_BIN"
    exit 0
fi

# Order matters: a bare `php8.4` on PATH is preferred over an alt-php absolute
# path, because a host that provides the former has chosen it deliberately.
# 8.5 sits above 8.3 because the floor is 8.4.1 — 8.3 is listed last as a
# best-effort, and composer will refuse it loudly rather than run wrong.
for candidate in \
    php8.4 \
    php8.5 \
    /opt/alt/php84/usr/bin/php \
    /opt/alt/php85/usr/bin/php \
    php8.3 \
    /opt/alt/php83/usr/bin/php
do
    if command -v "$candidate" >/dev/null 2>&1 || [ -x "$candidate" ]; then
        printf '%s\n' "$candidate"
        exit 0
    fi
done

printf 'php\n'
