# Deployment — Hostinger (`erpdemo.amrtech.in`)

This app deploys via **GitHub Actions**: push to `main` → the frontend is built
on the runner (the Hostinger plan has no Node), then `backend/` is rsynced to the
server and `composer install` + `migrate` + cache rebuild run over SSH.

- Pipeline: `.github/workflows/deploy.yml`
- Server tasks: `backend/scripts/deploy.sh`
- `.env` and `storage/` are **never** touched by a deploy — they live only on the server.

---

## One-time server setup (do this once, by hand over SSH)

### 1. hPanel

- **PHP Configuration** → PHP **8.3**.
- **Subdomains** → `erpdemo.amrtech.in`, and set its **Document Root** to:
  `domains/amrtech.in/erp/backend/public`
- **SSL** → enable free SSL for the subdomain.
- **SSH Access** → note the **host**, **username** (`u333512902`), and **port**
  (Hostinger is usually `65002`).

### 2. Bootstrap the app folder

```bash
ssh -p 65002 u333512902@<host>

mkdir -p ~/domains/amrtech.in/erp/backend
cd ~/domains/amrtech.in/erp

# Fastest bootstrap: clone once so composer/.env are set up, then CI takes over.
git clone https://github.com/sendhilpalanivel/production-erp.git .
cd backend

php -d memory_limit=-1 "$(command -v composer)" install --no-dev --optimize-autoloader
cp .env.example .env
# --- edit .env now: see the values block below ---
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=ShiftSeeder --force
php artisan storage:link
chmod -R 775 storage bootstrap/cache
```

### 3. Production `.env` values

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erpdemo.amrtech.in

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=u333512902_erp_dev
DB_USERNAME=u333512902_erpdevuser
DB_PASSWORD=<the DB password>

SANCTUM_STATEFUL_DOMAINS=erpdemo.amrtech.in
SESSION_DOMAIN=erpdemo.amrtech.in
SESSION_SECURE_COOKIE=true
```

> `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` are what make session login work
> once the SPA is served from the real domain. Get these wrong and login silently 419s.

### 4. (Optional) scheduler cron

hPanel → **Cron Jobs**, every minute:
```
cd ~/domains/amrtech.in/erp/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## One-time GitHub setup

### 1. Create a deploy keypair (on your machine)

```bash
ssh-keygen -t ed25519 -f deploy_key -N "" -C "github-deploy"
```

- Append **`deploy_key.pub`** to the server's `~/.ssh/authorized_keys`.
- Keep **`deploy_key`** (the private key) for the secret below. Delete both local files after.

### 2. Add repo secrets

GitHub → repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
|---|---|
| `SSH_HOST` | your Hostinger SSH host |
| `SSH_USER` | `u333512902` |
| `SSH_PORT` | `65002` (or your actual port) |
| `SSH_KEY` | the full contents of the private `deploy_key` |
| `DEPLOY_PATH` | absolute path, e.g. `/home/u333512902/domains/amrtech.in/erp` |

> Use the **absolute** `DEPLOY_PATH` (`/home/u333512902/...`), not `~` — it's used
> inside rsync/ssh where `~` may not expand.

---

## Every deploy (after setup)

```bash
git push origin main
```

That's it. Watch progress in the repo's **Actions** tab. To redeploy without a code
change, use **Run workflow** on the `Deploy to Hostinger` workflow.

### Manual fallback (deploy from SSH without CI)

```bash
cd ~/domains/amrtech.in/erp && git pull && cd backend && bash scripts/deploy.sh
# then rebuild the frontend elsewhere and copy backend/public/build up,
# since the server has no Node.
```
