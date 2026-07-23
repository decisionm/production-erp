# Deployment — demo instance (Hostinger)

How `erpdemo.amrtech.in` is deployed and kept up to date. This is the Phase 0 deployment runbook from `DEVELOPMENT-PLAN.md`, made concrete for the Hostinger shared-hosting account the domain already sits on. The same steps repeat per company instance later (one instance + one DB per company — see `TECHNICAL-DOCS.md` §2).

## How deploys happen

`.github/workflows/deploy.yml` runs on every push to `main` (or manually via *Actions → Deploy demo → Run workflow*):

1. Builds the frontend into `backend/public/build/` and installs production composer dependencies **on the CI runner** — the server never needs Node or Composer, only PHP.
2. Rsyncs the built `backend/` tree to the server, excluding `.env` and `storage/` (those live on the server only and are never touched by a deploy).
3. Runs `php artisan migrate --force` and rebuilds the config/route/view caches over SSH.

`.github/workflows/ci.yml` runs the full test/build gate on every PR and push to `main` — the deploy workflow deliberately mirrors CLAUDE.md's pre-commit checklist, so anything that reaches `main` green is deployable.

Until the secrets below are configured, the deploy workflow skips itself cleanly instead of failing.

## One-time server setup (hPanel)

1. **Enable SSH** (hPanel → Advanced → SSH Access) and note host, port (usually `65002`), and username.
2. **Add the deploy key:** generate a keypair (`ssh-keygen -t ed25519 -f deploy_key -N ""`), put the public half in hPanel's SSH keys, keep the private half for the GitHub secret below.
3. **Create the MySQL database** (hPanel → Databases): note DB name, user, password. Host is `localhost`.
4. **Create the app directory and .env** — SSH in, then:
   ```bash
   mkdir -p ~/domains/erpdemo.amrtech.in/erp/storage
   # copy backend/.env.example to ~/domains/erpdemo.amrtech.in/erp/.env and edit:
   #   APP_ENV=production, APP_DEBUG=false, APP_URL=https://erpdemo.amrtech.in
   #   DB_CONNECTION=mysql + the hPanel DB credentials
   #   SANCTUM_STATEFUL_DOMAINS=erpdemo.amrtech.in, SESSION_DOMAIN=erpdemo.amrtech.in
   # then, after the first deploy has uploaded the code:
   cd ~/domains/erpdemo.amrtech.in/erp
   mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/public
   php artisan key:generate
   ```
5. **Point the web root at `erp/public`:** replace the subdomain's `public_html` with a symlink —
   ```bash
   cd ~/domains/erpdemo.amrtech.in
   rm -rf public_html && ln -s erp/public public_html
   ```
   (If hPanel fights the symlink, the alternative is hPanel's "document root" setting for the subdomain, pointed at `erp/public`.)
6. **Cron jobs** (hPanel → Advanced → Cron Jobs), both every minute — the scheduler, and the queue drained in `--stop-when-empty` bursts since shared hosting has no persistent workers (`QUEUE_CONNECTION=database`):
   ```
   php /home/<user>/domains/erpdemo.amrtech.in/erp/artisan schedule:run
   php /home/<user>/domains/erpdemo.amrtech.in/erp/artisan queue:work --stop-when-empty --max-time=50
   ```
   If the CLI `php` isn't 8.3 on the account, use the versioned binary Hostinger provides (e.g. `/usr/bin/php8.3`) in both cron lines.
7. **Seed base data** after the first successful deploy: `php artisan db:seed` (or the specific seeders for users/shifts/roles).

## GitHub secrets

Repo → Settings → Environments → create `demo` → add:

| Secret | Value |
|---|---|
| `DEPLOY_SSH_HOST` | Hostinger SSH host (from hPanel) |
| `DEPLOY_SSH_PORT` | usually `65002` |
| `DEPLOY_SSH_USER` | Hostinger SSH username |
| `DEPLOY_SSH_KEY` | the **private** half of the deploy keypair |
| `DEPLOY_PATH` | `/home/<user>/domains/erpdemo.amrtech.in/erp` |

## First-deploy checklist

1. Server setup steps 1–5 done.
2. Secrets configured → push to `main` (or run the workflow manually) → watch Actions go green.
3. Steps 4 (key:generate, storage dirs) and 6–7 on the server.
4. Smoke test: `https://erpdemo.amrtech.in/` loads the login page; log in; `/tally-sync` dashboard lists (empty) queue.

## What this instance is NOT

- Not the pilot customer's production instance — that gets its own copy of this runbook when the time comes (fresh DB, fresh `.env`, ideally a VPS by then for real queue workers — see `TECHNICAL-DOCS.md` §8).
- The Tally sync agent does not run anywhere near this server — it runs on the factory's Windows machine next to Tally and polls this instance's API outbound (see `tally-sync-agent/README.md`).
