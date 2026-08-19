---
name: migrate-instance
description: Use when moving the ERP to a different server, domain, database or GitHub org — the cutover order, and the three surfaces a migration breaks that a "does it work?" check cannot see.
---

# Migrating the instance

Everything here was paid for on 19-Aug-2026, moving
`erpdemo.amrtech.in` / `sendhilpalanivel` → `erp.actech.co.in` / `decisionm`.
The move itself went fine. What went wrong was everything that was *true
before* and quietly stopped being true.

## The one rule

**A migration is not "the new place works". It is "the new place works, AND
nothing still points at the old place, AND everything the old place gave us
for free still holds."**

Three surfaces. A liveness check only ever sees the first. Every serious
finding of 19-Aug lived in the second or third.

| Surface | What it is | How it fails |
|---|---|---|
| 1. What moves | code, DB, storage, secrets | loudly — you notice |
| 2. What points AT the old place | agent update feeds, API base URLs, `--repo`-less `gh` calls, remote-tracking refs, docs | **silently** — the old host keeps answering |
| 3. What the old place gave implicitly | app outside the webroot, PHP version already set, PR review chain existing | **invisibly** — nobody listed it, so nobody misses it |

## Before you start: write down surface 3

Before touching anything, list what is true of the CURRENT environment that
nobody has ever had to think about. On 19-Aug all three of these were lost
without a single error being raised:

- the app lived OUTSIDE the web document root → the new subdomain was pointed
  into a live WordPress site's `public_html`, putting `.env`, `config/`, 534 MB
  of uploads and seven rolling full DB dumps inside someone else's docroot;
- the web PHP version was already 8.4 → the new domain served 7.4 and every
  request 500'd on Composer's platform check;
- **PRs existed** → the new repo had none, so a delta merged and auto-deployed
  to the live factory with no review record, against an `AGENTS.md` chain that
  requires one.

If you cannot name these before the move, you will not notice losing them.

## Cutover order

1. **Provision** the new host. If it is a subdomain, give it **its own
   document root** (`~/domains/<sub>/public_html`) — this account does that
   for `mybus.dzyte.com`, and it is the difference between an independent PHP
   version and sharing one with a stranger's WordPress.
2. **Set the web PHP version** and prove it with `curl -sSI | grep x-powered-by`.
   The CLI version tells you nothing about the web version — on 19-Aug the CLI
   was 8.4.21 while the web was 7.4.33.
3. **Import the DB**, then confirm `migrate:status` shows **0 pending** before
   any deploy. If the dump carried the schema, `migrate` must be a no-op; if it
   is not, stop and find out why before running DDL on factory data.
4. **Copy `storage/`** (rsync-excluded, so it only ever moves by hand).
5. **`.env`: diff, do not edit.** See below — this is its own trap.
6. **First deploy is MANUAL.** `deploy.yml` runs `artisan down` BEFORE the
   rsync, so on a server with no `artisan` the job dies before copying
   anything. It cannot cold-start a server. Rsync from a **clean checkout of
   `main`**, never a working tree, then run `bash scripts/deploy.sh`. Create
   `bootstrap/cache/` and `storage/framework/*` first — both are rsync-excluded
   and `composer install` fails without them.
7. **Reverse-dependency sweep** (below) — before declaring done, not after.
8. **Verify per `deploy-live-verify`**, plus the probes below.

## `.env`: diff it key-by-key. Never "update the ones we remember."

The 19-Aug handoff recorded ".env updated ✅" and listed four keys. Two others
still named the old host:

```
SESSION_DOMAIN=erpdemo.amrtech.in
SANCTUM_STATEFUL_DOMAINS=erpdemo.amrtech.in
```

`.env` is **rsync-excluded**, so no deploy will ever correct it. With those
stale, **every login 419s** — and it presents as whatever you fixed last having
failed. Do this instead:

```bash
diff <(sed -E 's/=.*//' old.env | sort) <(sed -E 's/=.*//' new.env | sort)   # keys
grep -nE '<old-host>|<old-db>|<old-user>' new.env                            # values
```
Then `php artisan config:cache` — a cached config built from stale values
outlives the edit.

## The reverse-dependency sweep — the step that was missing

Grep is the cheap half. Do it, then do the expensive half.

```bash
git grep -nE '<old-host>|<old-user>|<old-db>|<old-org>' -- ':!docs/archive'
```

**The expensive half is enumerating what lives OUTSIDE the repo and points
in.** On 19-Aug the worst finding was not in the grep results at all until
someone thought to ask "what else talks to this host?":

- **The Tally Sync Agent on the factory PC.** Two independent halves, and
  fixing one does not fix the other:
  - *Cloud API base URL* — an `electron-store` default, so it is overridable
    from the agent's Settings window. Fixable in a minute, needs a fresh API
    token from the new instance.
  - *Update feed* — baked into `package.json` at build time. **No setting
    changes it.** Installed agents keep polling the old domain forever, with
    `autoDownload = true` and `quitAndInstall`, every 6 hours, unsigned.
- **Therefore: never decommission the old domain on schedule.** Until every
  installed agent has been replaced by one built with the new feed URL, that
  domain is a live unattended-code-execution channel into the machines that
  reach Tally. Park it and keep paying for it.
- **`gh` calls with no `--repo`** resolve through the `origin` remote.
  `scripts/factory-knowledge/status.sh` did this and swallowed the 404
  (`2>/dev/null || true`), so CLAUDE.md's mandated session-start check printed
  a **false all-clear**.
- **Remote-tracking refs.** `origin/main` freezes at the last fetch from the
  old repo and still resolves locally, so `git cherry origin/main <branch>` and
  `git diff origin/main..HEAD` answer **wrongly without erroring** — including
  inside `land-and-clean-a-branch`, which uses them to decide "already landed?"
  Retarget branches (`git branch -u <newremote>/main`) and fix the skills.
- **Anything asserting a URL in tests** — a release-contract test pinned the
  old feed URL, so fixing the workflow alone turns CI red.

## Post-migration probes

**Probe from the PARENT domain, not the app's own hostname.** If the app now
sits inside another site's docroot, its own hostname is the one place the
dangerous paths do not exist — every probe passes and proves nothing.

```bash
curl -sI https://<parent>/<appdir>/backend/.env
curl -sI https://<parent>/<appdir>/backups/<a REAL dump filename>
curl -sI https://<parent>/<appdir>/backend/storage/logs/laravel.log
```

Judge by **body, not status code**. A Laravel SPA catch-all returns `200` for
unknown paths, so `200` is not a leak — compare the body against the homepage:

```bash
cmp <(curl -s https://<app>/) <(curl -s '<suspect-url>') && echo "SPA shell, safe"
```

Then check *why* it is safe. On 19-Aug `.sql` and `.env` were 403 from the
**WordPress site's** `.htaccess` and a host denylist — protection owned by an
unrelated site, and `.log` was not on that denylist at all.

⚠️ **Do NOT "fix" this with a deny-all `.htaccess` at `backend/`.** Apache
merges parent `.htaccess` downward and `backend/public/.htaccess` carries no
`Require`/`Allow` to re-grant — it would 403 the entire ERP. Anything placed
inside `backend/` is also deleted by the next `rsync --delete`, silently. The
durable placements are outside the rsync destination or on its exclude list.

## Restore the review chain before merging anything

`git push` moves commits, branches and tags. It does **not** move PRs or
issues — 194 PRs and 3 issues did not travel, and one of the lost issues
described the exact failure class hit during the migration.

So immediately after the move, the repo has **no PRs, and CI still green**.
Nothing announces that `AGENTS.md`'s chain has stopped running. Either restore
it first, or do not merge to a branch that auto-deploys. Also export the old
repo's PRs/issues via the API while access lasts — a private repo returns 404
to an unauthorised account, which reads exactly like deletion.

## When a failure has a documented cause, prove that cause actually fired

Three deploys failed at `ssh-keyscan` with "no SSH host key". The
`deploy-live-verify` skill documents that precise error as a Hostinger
brute-force ban — so the explanation was accepted and the investigation
stopped. The ban was real. **It was also not the blocker**: the workflow could
never have bootstrapped an empty server, ban or no ban.

A well-documented failure mode is also a blind spot, because it makes the
wrong diagnosis cheap. Confirm the documented cause is what actually happened
before acting on it.

## Never

- Rsync a **working tree** to a live instance. Use a clean checkout of `main`,
  and verify the frontend build landed in `backend/public/build/` in that same
  tree — building in one place and rsyncing another ships no SPA.
- Treat a self-reported "✅" in a handoff as a verified fact.
- Decommission the old host on a schedule, before the reverse-dependency sweep
  is closed out and every agent has been replaced.
- Rewrite history to fix a stale hostname: `docs/archive/*` is frozen,
  `docs/factory/decisions/*` are immutable and tool-written (FC-08), and
  `CURRENT-DECISIONS.md` is generated and byte-compared by `validate.py`.
  `sources/manifest.json` is canonical JSON — a `sed` breaks `check.sh`.
