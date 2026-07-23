# Deployment — Hostinger (`erpdemo.amrtech.in`)

Single source of truth for deploying and updating this app on Hostinger.

## Status

- **Live:** https://erpdemo.amrtech.in (Laravel API + bundled React SPA)
- **Server:** Hostinger shared hosting — SSH `u333512902@89.117.188.162:65002`
- **App path:** `/home/u333512902/domains/erpdemo.amrtech.in/erp`
- **Docroot:** the subdomain's `public_html` is a **symlink → `erp/backend/public`**
- **Database:** MySQL `u333512902_erp_dev`
- **PHP:** **8.4** (both web, set in hPanel, and CLI via `/opt/alt/php84/usr/bin/php`)

The two scripts that run a deploy:

| File | Runs where | Does |
|---|---|---|
| `.github/workflows/deploy.yml` | GitHub Actions | Builds the frontend, ships `backend/` to the server, invokes `deploy.sh` |
| `backend/scripts/deploy.sh` | On the server | `composer install` → `migrate --force` → `storage:link` → cache rebuild (auto-selects PHP 8.4) |

---

## Updating the app (the everyday flow)

Once **PR #1 is merged to `main`**, deploying any frontend or backend change is:

```bash
git push origin main
```

That triggers GitHub Actions, which:
1. Builds + type-checks the React app on the runner (the server has no Node).
2. `rsync`s `backend/` — including the freshly built `public/build/` — to the server.
3. Runs `backend/scripts/deploy.sh` over SSH (composer, migrations, caches).

Watch it in the repo's **Actions** tab. To redeploy without a code change, use **Run workflow** on the *Deploy to Hostinger* workflow.

**What a deploy never touches** (so config/data survive every push):
`.env`, `storage/`, `vendor/`, `bootstrap/cache/` — excluded from rsync; `.env` and DB creds live only on the server.

### Database changes
Just add a normal Laravel migration and push — `deploy.sh` runs `migrate --force` automatically. ⚠️ On MySQL, custom composite index/unique names must stay **≤ 64 characters** (dev uses SQLite, which doesn't enforce this — name them explicitly, e.g. `$table->unique([...], 'short_name')`).

### Manual deploy (fallback, no CI)
The server has no Node, so the frontend must be built elsewhere. From a machine with Node:

```bash
# 1. build frontend locally (outputs into backend/public/build)
cd frontend && npm ci && npm run build

# 2. upload code + build (excludes config/runtime), then run server tasks
cd ../backend
tar -czf - --exclude='./.env' --exclude='./vendor' --exclude='./storage' \
    --exclude='./bootstrap/cache' --exclude='./public/storage' . \
  | ssh -p 65002 u333512902@89.117.188.162 \
    "tar -xzf - -C ~/domains/erpdemo.amrtech.in/erp/backend \
     && cd ~/domains/erpdemo.amrtech.in/erp/backend && bash scripts/deploy.sh"
```

---

## Pipeline internals

`deploy.yml` needs 5 GitHub Actions secrets (Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `SSH_HOST` | `89.117.188.162` |
| `SSH_USER` | `u333512902` |
| `SSH_PORT` | `65002` |
| `SSH_KEY` | private half of the CI deploy key (public half in the server's `~/.ssh/authorized_keys`) |
| `DEPLOY_PATH` | `/home/u333512902/domains/erpdemo.amrtech.in/erp` (absolute — `~` won't expand in rsync/ssh) |

`deploy.sh` auto-selects a PHP ≥ 8.4 binary (`php8.4` / `/opt/alt/php84/...`) because Hostinger's default CLI `php` is 8.2, while the locked deps (symfony 8.1, spatie/activitylog 5) require **PHP ≥ 8.4.1**. Override with `PHP_BIN=/path/to/php`.

---

## One-time setup (already done — kept for rebuild/reference)

### hPanel
- **PHP Configuration** → set `erpdemo.amrtech.in` to **PHP 8.4** (web requests 500 otherwise).
- **SSL** → enable free SSL for the subdomain.
- **SSH Access** → note host/user/port; **Add SSH Key** for the CI deploy public key.
- Docroot: `public_html` symlinked to `erp/backend/public` (see below).

### Server bootstrap
```bash
ssh -p 65002 u333512902@89.117.188.162
cd ~/domains/erpdemo.amrtech.in
mkdir -p erp/backend/storage/framework/{cache/data,sessions,views,testing} \
         erp/backend/storage/{logs,app/public} erp/backend/bootstrap/cache
chmod -R 775 erp/backend/storage erp/backend/bootstrap/cache
# create erp/backend/.env (values below), then get the code onto the server
# (git clone, or the manual tar upload above), then:
cd erp/backend
/opt/alt/php84/usr/bin/php artisan key:generate
bash scripts/deploy.sh                       # composer + migrate + cache
/opt/alt/php84/usr/bin/php artisan db:seed --class=PermissionSeeder --force
/opt/alt/php84/usr/bin/php artisan db:seed --class=ShiftSeeder --force
# demo data (optional): db:seed --class=BottleManufacturingDemoSeeder --force
# point the docroot at Laravel's public/:
cd ~/domains/erpdemo.amrtech.in && mv public_html public_html.bak && ln -s erp/backend/public public_html
```

### Production `.env`
```dotenv
APP_NAME="Production ERP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erpdemo.amrtech.in

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=u333512902_erp_dev
DB_USERNAME=u333512902_erpdevuser
DB_PASSWORD=<the DB password>

SESSION_DRIVER=database
SESSION_DOMAIN=erpdemo.amrtech.in
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=erpdemo.amrtech.in
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### (Optional) scheduler cron
hPanel → **Cron Jobs**, every minute:
```
cd ~/domains/erpdemo.amrtech.in/erp/backend && /opt/alt/php84/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| **500**, "Composer dependencies require PHP >= 8.4.1" | Subdomain's web PHP is < 8.4 → set **PHP 8.4** in hPanel → PHP Configuration. |
| **Login 419 / silent logout** | `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` must equal `erpdemo.amrtech.in`; `APP_URL` must be the https domain. |
| **`Access denied for user ...`** on migrate | Wrong DB creds in `.env`, or user not granted — verify/reset in hPanel → Databases. |
| **Migration fails, "Identifier name ... is too long"** | Composite index/unique name > 64 chars — give it an explicit short name. |
| **"Frontend build not found"** | `public/build/` missing — the frontend wasn't built/rsynced; re-run the deploy. |
