# Deployment — Hostinger (`erp.actech.co.in`)

Single source of truth for deploying and updating this app on Hostinger.

> **Migrated 19-Aug-2026** from `erp.actech.co.in` (GitHub
> `sendhilpalanivel/production-erp`) to the values below. Anything anywhere
> still naming the old host or the old repo is STALE — see "Migration
> leftovers" at the end of this file for the ones known to remain.

## Status

- **Live:** https://erp.actech.co.in (Laravel API + bundled React SPA)
- **GitHub:** `decisionm/production-erp` — **`main` tracks `decisionm/main`.**
  The `origin` remote still points at the OLD repo, so `git push origin main`
  does **not** deploy. Use `git push` (or `git push decisionm main`).
- **Server:** Hostinger shared hosting — SSH `u159162192@93.127.208.238:65002`
  (local key: `~/.ssh/actech_deploy_key`)
- **App path:** `/home/u159162192/domains/actech.co.in/public_html/erp_app`
- **Docroot:** `public_html/erp` is a **symlink → `erp_app/backend/public`**,
  and that symlink is what the `erp.actech.co.in` subdomain serves
- **Database:** MySQL `u159162192_u159162192` (user `u159162192_erp` — the
  names look swapped; that is what hPanel issued)
- **PHP:** **8.4.21** — web version is set in hPanel **on `actech.co.in`**,
  CLI via `/opt/alt/php84/usr/bin/php`

> ⚠️ **Two live sites share one PHP setting.** `erp.actech.co.in` is served
> out of `actech.co.in`'s `public_html`, so it is not a separate hPanel
> website entry — changing its PHP version also moves the WordPress site at
> `actech.co.in`. Both were raised 7.4 → 8.4 on 19-Aug-2026.
>
> ⚠️ **The app lives inside another site's document root.** The old server
> kept it outside. Nothing is exposed today (`.env` returns 403; other paths
> return the SPA shell), but the margin is one `.htaccess` edit on an
> unrelated site. The clean fix is to recreate `erp.actech.co.in` so it owns
> `~/domains/erp.actech.co.in/public_html` — this account already does that
> for `mybus.dzyte.com`.

The two scripts that run a deploy:

| File | Runs where | Does |
|---|---|---|
| `.github/workflows/deploy.yml` | GitHub Actions | Builds the frontend, ships `backend/` to the server, invokes `deploy.sh` |
| `backend/scripts/deploy.sh` | On the server | `composer install` → `migrate --force` → `storage:link` → cache rebuild (auto-selects PHP 8.4) |

---

## Updating the app (the everyday flow)

Deploying any frontend or backend change is:

```bash
git push          # main tracks decisionm/main — NOT origin (the old repo)
```

That triggers GitHub Actions, which:
1. Builds + type-checks the React app on the runner (the server has no Node).
2. `rsync`s `backend/` — including the freshly built `public/build/` — to the server.
3. Runs `backend/scripts/deploy.sh` over SSH (composer, migrations, caches).

Watch it in the repo's **Actions** tab. To redeploy without a code change, use **Run workflow** on the *Deploy to Hostinger* workflow.

**What a deploy never touches** (so config/data survive every push):
`.env`, `storage/`, `vendor/`, `bootstrap/cache/` — excluded from rsync; `.env` and DB creds live only on the server.

### Database changes

Any migration that adds, drops or renames a column must be followed by
`php artisan schema:catalogue:generate` (run locally, commit the changed
files under `backend/resources/schema-catalogue/`) and a `meaning:` line for
each new column. `CatalogueCompletenessTest` fails CI until the files match
the schema again; hand-written annotations survive the regeneration.
Just add a normal Laravel migration and push — `deploy.sh` runs `migrate --force` automatically. ⚠️ On MySQL, custom composite index/unique names must stay **≤ 64 characters** (dev uses SQLite, which doesn't enforce this — name them explicitly, e.g. `$table->unique([...], 'short_name')`).

### Live data corrections (never a migration, always dry-run first)

Changing live ROWS — as opposed to schema — happens through a manually
dispatched workflow, never as a side effect of a deploy. Every one of them
defaults to a dry run, needs a typed confirmation to write, and takes a full
database dump on the server first (`backend/scripts/backup-db.sh`, which
refuses to let the write proceed if the dump fails or comes back empty).

| Workflow | What it does |
|---|---|
| *Correct consumption item* | Prints the correction statement for the accountant; with `write=true`, posts append-only movements moving batch consumption from one item to another. Originals and Tally are never touched. |
| *Remove WIP demo rows* | Lists demo-seeded stock movements standing on Production/WIP; with `write=true --ids=`, removes exactly the ids a person read off the dry run and recomputes the touched balances. |

Each workflow's header names the owner decision it implements, and
`docs/factory/CURRENT-DECISIONS.md` is the readable index of those.

Both refuse fail-closed with counts rather than guessing, and the removal also
refuses an id that is not a candidate, a row another record references, and one
leg of a transfer pair. Read the dry run, then run the write with the ids or
counts it printed — the write is the lead's step, not the agent's.

### Manual deploy (fallback, no CI)
The server has no Node, so the frontend must be built elsewhere. From a machine with Node:

```bash
# 1. build frontend locally (outputs into backend/public/build)
cd frontend && npm ci && npm run build

# 2. upload code + build (excludes config/runtime), then run server tasks
cd ../backend
tar -czf - --exclude='./.env' --exclude='./vendor' --exclude='./storage' \
    --exclude='./bootstrap/cache' --exclude='./public/storage' . \
  | ssh -p 65002 u159162192@93.127.208.238 \
    "tar -xzf - -C ~/domains/actech.co.in/public_html/erp_app/backend \
     && cd ~/domains/actech.co.in/public_html/erp_app/backend && bash scripts/deploy.sh"
```

---

## Tally Sync Agent releases (the release ritual)

The tray agent on the factory PC deploys on its own track — a web deploy
never updates it, and its release is deliberately NOT automatic end to end.

```
build on CI  →  REVIEW GATE  →  merge (candidate artifact only)  →  manual publish dispatch ON MAIN  →  storage + update feed  →  verify on the Settings page
```

1. **Build on CI.** Push the agent change to a branch, open the PR, then run
   the *Build Tally Sync Agent* workflow on that branch (**Run workflow**,
   leave `publish` unticked / false). The installer is built natively on a
   Windows runner and lands as a downloadable artifact (`agent-installer`,
   7-day retention) with its sha256 in `tally-sync-agent-latest.json`.
   Nothing live is touched.
2. **Review gate.** The build→publish gap is the point, not a missing
   feature: a build must never reach the factory unreviewed, because the
   running agent AUTO-UPDATES from the published feed within ~6 hours — so
   publishing IS deploying to the factory, with no human in between. The
   gate earned its keep on 07 Aug 2026, when one click of a
   reviewed-looking-but-never-live-tested stock read crashed the factory's
   live Tally. Nobody proceeds past this step until the diff has passed
   review (the usual builder → Cursor → Codex → owner chain).
3. **Merging builds a candidate — it does NOT publish.** A push to `main`
   runs this workflow and uploads the installer as a review artifact, then
   stops. Nothing reaches the factory on merge.
4. **Manual publish dispatch, on main.** After the review passes, run the
   workflow **on `main`** with **`publish: true`**.
   > ℹ️ **The feed now points at the new host, and the first publish after
   > 19-Aug-2026 is still not routine.** `tally-sync-agent/package.json`'s
   > `publish.url` is `https://erp.actech.co.in/storage/agent/`,
   > `src/config.ts`'s `cloudApiBaseUrl` default is
   > `https://erp.actech.co.in/api/v1`, and
   > `tally-sync-agent/tests/releaseContract.test.js` asserts the matching
   > `FEED_URL` — so a build cut today ships an agent pointed at the live
   > host. The publish gate (`assert-version-advance.js`) reads that feed
   > rather than writing it, and the new host was verified on 19-Aug-2026 to
   > serve all three artifacts, so nothing needs hand-seeding.
   >
   > What that does NOT fix: an agent already installed on the factory PC has
   > the OLD `erpdemo.amrtech.in` feed baked in and keeps using it until it is
   > replaced by one built from the current `package.json`. That is why the
   > old domain must stay registered (see "Migration leftovers" below), and
   > why the version being published matters — the repo is at `0.3.9` while
   > the last PUBLISHED version is `0.3.5` (tag `agent-v0.3.5`). Publishing IS
   > deploying to the factory: the running agent auto-updates within ~6 hours.
   > That rebuilds and then
   publishes. All three conditions are enforced in the workflow — manual
   dispatch, `publish: true`, and `refs/heads/main` — so a push cannot
   publish and a dispatch from a feature branch cannot publish even with
   `publish: true` set. The publish job uploads
   the installer, `latest.yml` (the electron-updater feed) and
   `tally-sync-agent-latest.json` atomically into
   `backend/storage/app/public/agent/` on the live box. It touches only that
   directory — never the app, never the database.
5. **Pick-up is automatic from here.** The Tally Sync → Settings page's
   download button serves the new version immediately, and the running
   agent self-updates from `latest.yml` within ~6 hours (or on its next
   restart).
6. **Verify before anyone downloads.** Open Tally Sync → Settings on the
   live ERP and read the "Latest version: X · built DATE" line under the
   download button — it must show the version just published. The
   site-visit steps (install-over, settings survival, and confirming normal
   voucher sync) live in `tally-sync-agent/SITE-CHECKLIST.md`. From v0.3.3
   the Stock Summary read trigger is REMOVED and from v0.3.4 the agent makes
   no automatic reads from Tally at all — so verification never involves
   reading from Tally, and nothing in this ritual asks anyone to.

## Pipeline internals

`deploy.yml` needs 5 GitHub Actions secrets (Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `SSH_HOST` | `93.127.208.238` |
| `SSH_USER` | `u159162192` |
| `SSH_PORT` | `65002` |
| `SSH_KEY` | private half of the CI deploy key (public half in the server's `~/.ssh/authorized_keys`) |
| `DEPLOY_PATH` | `/home/u159162192/domains/actech.co.in/public_html/erp_app` (absolute — `~` won't expand in rsync/ssh) |

`deploy.sh` auto-selects a PHP ≥ 8.4 binary (`php8.4` / `/opt/alt/php84/...`) because Hostinger's default CLI `php` is 8.2, while the locked deps (symfony 8.1, spatie/activitylog 5) require **PHP ≥ 8.4.1**. Override with `PHP_BIN=/path/to/php`.

---

## One-time setup (already done — kept for rebuild/reference)

### hPanel
- **PHP Configuration** → set **`actech.co.in`** to **PHP 8.4** (web requests 500 otherwise).
  The subdomain has no separate entry — this moves the WordPress site too.
- **SSL** → enable free SSL for the subdomain.
- **SSH Access** → note host/user/port; **Add SSH Key** for the CI deploy public key.
- Docroot: `public_html` symlinked to `erp/backend/public` (see below).

### Server bootstrap
> ⚠️ **The GitHub Actions workflow CANNOT bootstrap an empty server.**
> `deploy.yml` runs `artisan down` *before* the rsync; with no `artisan` on
> the server that step exits non-zero and the job dies before copying
> anything. The first deploy to any new server must be done by hand
> (below). Do **not** "fix" this by weakening the maintenance step — that
> window is what stops the floor meeting an unmigrated schema.
> Paid for on 19-Aug-2026: three deploys failed and were misdiagnosed as an
> SSH ban before this was found.

```bash
ssh -i ~/.ssh/actech_deploy_key -p 65002 u159162192@93.127.208.238
BASE=~/domains/actech.co.in/public_html/erp_app
# These are rsync-EXCLUDED, so a fresh server has neither. composer install
# fails with "bootstrap/cache directory must be present and writable" without them.
mkdir -p $BASE/backend/storage/framework/{cache/data,sessions,views,testing} \
         $BASE/backend/storage/{logs,app/public} $BASE/backend/bootstrap/cache
chmod -R 775 $BASE/backend/storage $BASE/backend/bootstrap/cache
# create $BASE/backend/.env (values below), then get the code onto the server
# (rsync from a CLEAN checkout of main — never your working tree), then:
cd $BASE/backend
/opt/alt/php84/usr/bin/php artisan key:generate    # ONLY on a brand-new DB
bash scripts/deploy.sh                             # composer + backup + migrate + seed + cache + up
# point the subdomain docroot at Laravel's public/:
ln -s erp_app/backend/public ~/domains/actech.co.in/public_html/erp
```

After that first manual deploy, `artisan` exists and the normal GitHub
Actions flow works — verified 19-Aug-2026 (run 32256368562, green).

### Production `.env`
```dotenv
APP_NAME="Production ERP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.actech.co.in

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=u159162192_u159162192
DB_USERNAME=u159162192_erp
DB_PASSWORD=<the DB password>

SESSION_DRIVER=database
SESSION_DOMAIN=erp.actech.co.in
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=erp.actech.co.in
QUEUE_CONNECTION=database
CACHE_STORE=database

# Ask ERP (natural-language queries, PR "Ask ERP: schema catalogue…").
# Without the key the page answers "Ask ERP is not configured on this
# server" and nothing else breaks. The model id is the one exact string;
# effort is the cost lever (low | medium | high).
ANTHROPIC_API_KEY=<from the Anthropic console>
ASK_ERP_MODEL=claude-opus-5
ASK_ERP_EFFORT=medium
# Optional: run the guarded SELECTs as a SELECT-only MySQL user. Create the
# user in hPanel with SELECT on the ERP database only, then:
# ASK_ERP_DB_CONNECTION=ask_erp
# ASK_ERP_DB_USERNAME=<select-only user>
# ASK_ERP_DB_PASSWORD=<its password>
```

### (Optional) scheduler cron
hPanel → **Cron Jobs**, every minute:
```
cd ~/domains/actech.co.in/public_html/erp_app/backend && /opt/alt/php84/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| **500**, "Composer dependencies require PHP >= 8.4.1" | Subdomain's web PHP is < 8.4 → set **PHP 8.4** in hPanel → PHP Configuration. |
| **Login 419 / silent logout** | `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` must equal `erp.actech.co.in`; `APP_URL` must be the https domain. **`.env` is rsync-excluded, so a host move does NOT update these** — they were missed in the 19-Aug migration and would have 419'd every login. Re-run `config:cache` after editing. |
| **Deploy dies at "Enter maintenance mode"** | Either the server has no `artisan` yet (see the bootstrap note — the workflow cannot cold-start a server), or the app cannot boot (half-installed `vendor/`, stale `bootstrap/cache`). |
| **"The deploy host returned no SSH host key"** | Hostinger brute-force protection banned the runner. It fails BEFORE touching anything. Wait ~10 min, re-run; retrying inside the window EXTENDS the ban. Never weaken the check. Confirm it is really a ban — on 19-Aug this masked the bootstrap problem above. |
| **`SQLSTATE[HY000] [2002] Operation not permitted`** | Intermittent MySQL refusal on this host (seen on the OLD server too, so not migration-related). If it recurs, try `DB_HOST=localhost` (socket) instead of `127.0.0.1` (TCP). |
| **`Access denied for user ...`** on migrate | Wrong DB creds in `.env`, or user not granted — verify/reset in hPanel → Databases. |
| **Migration fails, "Identifier name ... is too long"** | Composite index/unique name > 64 chars — give it an explicit short name. |
| **"Frontend build not found"** | `public/build/` missing — the frontend wasn't built/rsynced; re-run the deploy. |
| **Deploy dies at "Check the backup directory is writable"** | `~/backups/erp` on the server cannot be created/written (usually disk quota). It fails BEFORE `artisan down`, so the floor is still serving and nothing was copied. Fix over SSH and re-run. `deploy.sh` deliberately does **not** fall back to `../backups` — that is inside the WordPress docroot. |
| **A maintenance-window step times out (5/10/15 min)** | A hang, not a slow deploy — a whole server-side deploy is ~90s. The step fails normally, so the "app is in MAINTENANCE MODE" explainer fires and tells you what to do. Do not raise the timeout to make it pass. |

---

## Migration leftovers (19-Aug-2026)

**Fixed on branch `fix/post-migration-hardening`** (see
`docs/engineering/POST-MIGRATION-QA-2026-08-19.md` for why each mattered):

| File | Change |
|---|---|
| `tally-sync-agent/package.json` | `publish.url` → `erp.actech.co.in` — the update feed baked into every installer |
| `.github/workflows/build-agent.yml` | `FEED_URL` → new host (it is a `curl --fail` **read gate**, not a written value) |
| `tally-sync-agent/tests/releaseContract.test.js` | `:458` assertion repointed in the same commit, or CI goes red |
| `tally-sync-agent/src/config.ts` | default `cloudApiBaseUrl` → new host (fresh installs only) |
| `tally-sync-agent/src/settings-window/index.html` | placeholder |
| `backend/scripts/deploy.sh` | stale `Manual use:` path; three error messages that claimed the app was still serving while it was at 503; `BACKUP_DIR` moved out of the web docroot, and (later) its fallback BACK into that docroot removed — it now refuses instead |
| `.github/workflows/deploy.yml` | recovery text now names the new backup path; the maintenance window is bounded per STEP (job-level `timeout-minutes: 25` removed — it counted build+test time and cancelled rather than failed, so no explainer was guaranteed); a backup-directory read/write preflight runs BEFORE `artisan down` |
| `scripts/factory-knowledge/status.sh` | `--repo` pinned; an unreachable repo now SAYS SO instead of printing a false all-clear |
| `.claude/skills/land-and-clean-a-branch/SKILL.md` | `origin/main` → resolved upstream (`main@{upstream}`) |

**Deliberately NOT changed:**

| Item | Why |
|---|---|
| `tally-sync-agent/package.json` `appId` (`in.amrtech.erpdemo.tallysyncagent`) | Changing it makes electron-builder treat this as a DIFFERENT app — installed factory agents would not upgrade in place. Cosmetic embarrassment beats a stranded agent. |
| The `permitsFractions()` refusal (3 doors) | It enforces an answer to **Q58(b), which is still open**. AGENTS.md: add the question, don't pick an answer. Owner's call. |
| The missing 4th-door fraction guard | Adding it would ship MORE refusals keyed to that same open question. Fix belongs in `StockService` *after* Q58(b) is answered. |
| `permissions: contents: read` on 19 workflows | Correct in principle, but a blanket sweep across 19 files without testing each is how a working pipeline breaks. Wants its own reviewed change. |
| `docs/archive/*`, `docs/factory/decisions/*`, `CURRENT-DECISIONS.md`, `sources/manifest.json` | Frozen / immutable (FC-08) / generated and byte-compared / canonical JSON. Rewriting them falsifies history or breaks `check.sh`. |

⚠️ **Manual step still owed on the server:** existing dumps written before this
change remain at `erp_app/backups/`. Move or delete them — the new code only
changes where *future* dumps land.

⚠️ **Do not decommission `erpdemo.amrtech.in`.** Agents already installed on the
factory PC have the OLD feed URL baked in and keep polling it every 6 hours with
`autoDownload` + `quitAndInstall`. They only stop once replaced by an agent built
from the fixed `package.json`. Until then that domain is a live code-execution
channel — park it, keep paying for it.

## Web-exposure probes (verified 19-Aug-2026, re-run after ANY layout change)

The app sits inside a live WordPress site's docroot, so the parent domain —
not just the ERP subdomain — must be probed. Actual results:

| URL | Result |
|---|---|
| `https://actech.co.in/erp_app/backend/.env` | **403** |
| `https://actech.co.in/erp_app/backups/erp-db-*.sql` | **403** (tested with REAL dump filenames) |
| `https://actech.co.in/erp_app/backups/` | 301 → rewritten into `backend/public/` → SPA shell; no listing |
| `https://actech.co.in/erp_app/backend/storage/logs/laravel.log` | 200 but **byte-identical to the SPA shell** (`cmp` clean) — Laravel's catch-all, not the log |
| `https://erp.actech.co.in/.env` | **403** |

⚠️ **Two reasons not to relax about this.**

1. **The 403s come from the WordPress site's own `.htaccess`**, which belongs
   to an unrelated site and can be changed by anyone maintaining it. The ERP's
   protection is borrowed, not owned.
2. **`rsync --delete` silently removes any hardening file placed inside
   `backend/`.** A protective `.htaccess` dropped there disappears on the next
   deploy with no error — the worst failure mode for a protection file.

If hardening is added before the layout is fixed, the only deploy-durable
placements are **outside the rsync destination or on its exclude list**:
`erp_app/backups/.htaccess` and `erp_app/backend/storage/.htaccess`.
Do **NOT** put a deny-all at `erp_app/backend/` — Apache merges parent
`.htaccess` downward and `backend/public/.htaccess` has no `Require`/`Allow`
to re-grant, so it would 403 the entire ERP.

## Migration state notes

- **DB import:** the old database was dumped and imported into
  `u159162192_u159162192`. All 177 migrations arrived recorded, so the first
  deploy reported `Nothing to migrate` — no DDL ran against factory data.
- **`storage/`** (534 MB of uploads) was copied across and is rsync-excluded,
  so deploys never touch it.
- **Backups on the server:** `~/backups/erp/erp-db-*.sql`, written by
  `deploy.sh` before every migrate, last 7 kept (`BACKUP_DIR` overrides the
  directory). `$HOME` is never web-served, which is the whole point: a full
  dump carries password hashes, customers and purchase rates (FC-06).
  `deploy.sh` deliberately does **not** fall back to `../backups`, and
  `deploy.yml` proves the directory is writable BEFORE `artisan down`, so an
  unusable backup path fails the run with the floor still serving.
  - *Legacy, no longer written to:* dumps taken before the 19-Aug-2026 layout
    change still sit at `erp_app/backups/erp-db-*.sql`, inside a live
    WordPress docroot. Those are the manual cleanup owed above — not the
    current location, and not where `deploy.sh` writes or prunes.
- **`.env` is mode 600** and is excluded from rsync — it exists only on the
  server. Never commit it; the repo root `.gitignore` now covers `/.env`,
  `/env`, `/*.sql`, `/storage.zip`.
