# Post-migration QA — 19-Aug-2026

End-to-end QA after the move to `decisionm/production-erp` + `erp.actech.co.in`.
Four independent read-only reviews (security audit, code review of the deployed
delta, migration-completeness sweep, deploy-pipeline correctness), with every
claim below re-verified directly. Nothing was modified on the server.

Live at time of review: `main` = `0afee8f`, deployed by run 32273755403.

---

## 0. Taking the old site down — website ≠ domain

The old **website** can go down safely. An agent whose update check fails just
logs a warning (`checkForUpdatesAndNotify().catch(...)`) and carries on; and the
new host already serves every agent artifact, so nothing is lost with it.

What must **not** happen is the **domain registration lapsing or transferring**.
That is the §1 risk: installed agents keep polling
`erpdemo.amrtech.in/storage/agent/` every 6 hours with `autoDownload` +
`quitAndInstall` and no signature check, so whoever holds the domain can push
code to the machines that reach Tally. Keep paying for the registration until
every installed agent has been replaced by one built from the fixed
`package.json`. Pointing it at nothing is fine; letting it go is not.

**Before the old site goes down:** update the factory PC's agent Settings to
`https://erp.actech.co.in/api/v1` with a token from the new instance, or sync
stops the moment the old host stops answering.

## 1. CRITICAL — the factory agent auto-updates from the OLD domain

`tally-sync-agent/package.json`:
```json
"publish": [{ "provider": "generic", "url": "https://erpdemo.amrtech.in/storage/agent/" }]
```
`tally-sync-agent/src/main.ts:131-147`: `autoDownload = true`, `quitAndInstall`
on `update-downloaded`, checked at startup and **every 6 hours**. The `win:`
build block has only `target` and `icon` — **no code signing**, so
electron-updater has no publisher identity to verify a replacement against.

**Verified live:** both feeds on the old host still answer `200` and currently
advertise `version: 0.3.5`. The channel is active, not dormant.

**Impact:** whoever controls `erpdemo.amrtech.in` gets unattended code
execution within 6 hours on every factory Windows machine running the agent —
the machines that reach Tally. A *demo* subdomain that is no longer production
is precisely the one nobody renews.

**Actions:** (a) keep `erpdemo.amrtech.in` registered and parked regardless of
cost — it is now a code-execution channel, not a spare domain; (b) repoint
`publish.url` and `src/config.ts:31` (`cloudApiBaseUrl` default) at
`erp.actech.co.in`; (c) add Authenticode signing; until then treat
`autoDownload` + `quitAndInstall` as unsafe.

⚠️ `package.json`'s `appId` is `in.amrtech.erpdemo.tallysyncagent`. Changing it
makes electron-builder treat the agent as a DIFFERENT application, so already
installed agents would not upgrade in place. Do not sweep it blindly.

## 2. Agent publishing is BLOCKED on the new host (and a hostname fix is not enough)

`.github/workflows/build-agent.yml:~168` `FEED_URL` is not a value written at
publish time — it is a `curl --fail` **read** of currently-published metadata
feeding `assert-version-advance.js`, which refuses by design ("never assume a
first publish"). On the new host that object does not exist, so the publish job
dies **if that object is missing**.

**CORRECTION (verified 19-Aug, after the initial finding):** it is NOT missing.
The migrated `storage/` carried the whole agent directory across, and the new
host already serves all three artifacts — `tally-sync-agent-latest.json` (200),
`latest.yml` (200) and the full `tally-sync-agent-setup-0.3.5.exe` (206 on a
range request). So **publishing is not blocked and nothing needs hand-seeding**;
once `FEED_URL` points at the new host the gate reads real metadata and works.
This also means the old host holds no artifact that exists nowhere else.

`tally-sync-agent/tests/releaseContract.test.js:458` asserts FEED_URL is
literally `erpdemo.amrtech.in` and must change in the SAME commit
(`:530`/`:596` are inert decoy fixtures).

## 2b. Repointing the Tally agent — two INDEPENDENT halves, only one is easy

Raised by the owner: PO sync to Tally will not work until the agent points at
the new instance. Verified how the agent resolves its config
(`src/config.ts`, `electron-store` with `defaults`):

**Half A — Cloud API base URL (what PO sync uses). Fixable TODAY, no rebuild.**
`cloudApiBaseUrl` is an electron-store *default*, not a hardcoded constant —
`getConfig()` reads `store.get(...)`, and `setConfig()` persists an override.
So on the factory PC:

> Tally Sync Agent → **Settings** → set **Cloud API base URL** to
> `https://erp.actech.co.in/api/v1` → save. The API token is issued per
> instance, so a **new token from the new ERP** is needed too.

The repo default at `src/config.ts:31` still names the old host and only
affects a FRESH install — fix it in the repo as well, or the next reinstall
silently reverts to a dead host.

**Half B — the auto-update feed. NOT fixable from Settings.**
`package.json`'s `publish.url` is baked in at build time. No setting changes
it. Repointing it needs a rebuild + republish, which is currently **blocked**
(§2) and is also the §1 critical risk.

**Consequence — do not conflate them.** Fixing Half A makes PO sync work while
the agent CONTINUES to auto-update from `erpdemo.amrtech.in`. That is why §1's
"keep the old domain registered" stands even after PO sync is healthy: the
update channel outlives the data channel.

## 3. HIGH — a hard refusal shipped on top of an OPEN owner question

`MeasurementType::permitsFractions()` is enforced at three doors
(`StoreMaterialRequestRequest:99`, `StoreStoreIssueRequest:140`,
`StoreStoreIssueReturnRequest:83`), rejecting fractional quantities of items
whose UOM is counted.

The same delta files **Q58(b)** in `PENDING-OWNER-QUESTIONS.md` (line 1262,
still open), which says `500ML IFF Tray` is `Kgs.` while `500ML Tray IFF` is
`Nos.`, and that *only the factory can say* whether that is two products or a
master-data error. The code did not wait for the answer.

AGENTS.md: *"A consequential factory-flow ambiguity is discussed with the owner
BEFORE implementation — add it to PENDING-OWNER-QUESTIONS, don't pick an
answer."* A supervisor requesting `12.5` of the `Nos.`-spelled tray now gets a
422 with no operator remedy — the only fix is a live master-data edit.

**This is live on the floor now.** Recommend downgrading to a warning, or
holding the refusal, until Q58(b) is answered.

## 4. HIGH — the fraction guard is a property of 3 of 4 doors, not of the balance

`StoreStockTransferRequest`, `StoreStockIssueRequest`, `StoreStockReceiptRequest`
contain **zero** `permitsFractions` checks (verified). The delta edited exactly
these three rules to add `PlainDecimal` + `max` and did not add the guard. A
transfer of `12.5` of a `Nos.` item lands `12.5000` in `stock_balances` — the
state the other three doors now refuse, in the same table, one endpoint over.

Pre-existing gap, not a regression. Fix belongs in `StockService`, where all
four converge.

## 5. MEDIUM-HIGH — the only *silent* 503: no `timeout-minutes`

`deploy.yml` sets no `timeout-minutes`, so the job inherits GitHub's 360-minute
default. A **hung** (not failed) SSH/composer/MySQL step leaves the factory at
503 for up to six hours while the run shows "in progress". Both explainer steps
are gated on `failure() || cancelled()`, so nothing annotates anything during a
hang. Every other failure announces itself with a red X; this one does not.

Fix: `timeout-minutes: 25` on the job.

## 6. HIGH — three `deploy.sh` messages tell the operator the floor is serving when it is at 503

`deploy.sh:92` "Nothing was changed"; `:105-106` "the previous release is still
what runs"; `:129` "the previous release is still serving". All three are false
under the current pipeline — by the time `deploy.sh` runs, `artisan down` has
fired and the rsync has landed. `deploy.yml:252` gets it right, so the pipeline
contradicts itself, and the wrong half is what prints *inside the failure*.

## 7. Web exposure — NOT exposed today, but the protection is borrowed

Probed with REAL dump filenames:

| URL | Result |
|---|---|
| `actech.co.in/erp_app/backups/erp-db-*.sql` | **403** |
| `actech.co.in/erp_app/backend/.env` | **403** |
| `actech.co.in/erp_app/backend/storage/logs/laravel.log` | 200, but `cmp`-identical to the SPA shell |
| `actech.co.in/erp_app/backups/` | 301 → rewritten into `backend/public/`; no listing |

Two caveats that keep this open:
- The 403s come from the **WordPress site's** `.htaccess` and a host-level
  `.sql` denylist. `.log` is **not** on that denylist — a probe for a
  nonexistent `probe.sql` 403s while `probe.log` does not. So the log's only
  protection is the `erp_app/.htaccess` rewrite, which is unversioned and which
  WP plugins, caching plugins and Hostinger tooling all regenerate routinely.
  `rsync` targets `$DEPLOY_PATH/backend/`, so **the pipeline cannot restore it.**
- `deploy.sh:111-112` writes seven rolling full DB dumps (password hashes,
  customers, purchase rates — FC-06) to `erp_app/backups/`, inside that docroot.

**Reviewer disagreement, resolved:** one review proposed a deny-all
`backend/.htaccess` (self-heals via rsync); another warned it would 403 the
entire ERP, because Apache merges parent `.htaccess` downward and
`backend/public/.htaccess` carries no `Require`/`Allow` to re-grant. **The
second is correct** — a bare deny-all there takes the factory down. If that
route is taken it needs a matching `Require all granted` in
`backend/public/.htaccess`, both in the repo, tested off-live first.

Lower-risk fixes, in order: move `BACKUP_FILE` outside `public_html` entirely
(a `deploy.sh` change — also update the prune glob at `:159` and the operator
text at `deploy.yml:252`); and the real fix — give `erp.actech.co.in` its own
docroot so none of this applies.

## 8. Issue #167 confirmed — and the PHP pin is in 16 workflows, not one

`deploy.yml:210` hardcodes `/opt/alt/php84/usr/bin/php` (both the primary and
fallback invocation) while `deploy.sh:15-23` has `pick_php()` with a `PHP_BIN`
override. If Hostinger moves that path, both branches exit 127 **before**
`artisan down` — so it fails in the safe direction and does **not** leave the
factory at 503. Real severity: a deploy *freeze*, not an outage.

The cost is breadth: the same pin recurs in ~16 workflows, including
`read-server-log.yml` — the tool you would reach for to diagnose it. Minimal
fix: extract `pick_php()` to `backend/scripts/pick-php.sh` and use
`PHP=$(bash scripts/pick-php.sh 2>/dev/null || echo /opt/alt/php84/usr/bin/php)`.

## 9. Stale `origin` refs make two decision tools silently wrong

- `.claude/skills/land-and-clean-a-branch/SKILL.md` uses `origin/main` in 6
  places (`git cherry origin/main`, `git diff origin/main..HEAD`) to answer
  "is this branch already landed?" / "close vs merge?". `origin/main` is
  **frozen at `903b1b4`** while real main moved on, and the local ref still
  resolves — so it answers **wrongly without erroring**.
- `scripts/factory-knowledge/status.sh:23,30` call `gh` with no `--repo`,
  resolving via `origin`; the 404 is swallowed by `2>/dev/null || true`.
  CLAUDE.md mandates this at session start; it now prints a false all-clear.
- `docs/CLEAN-GIT-ARCHITECTURE.md` has 12 `origin/main` invocations.
- 16 local branches still track `origin`.

## 10. Verified clean — stated so it is not re-litigated

- **No credential file ever reached a commit** on any branch (checked all
  blobs in history for `.env`, `env`, `*.sql`, `storage.zip`). No committed
  secrets found anywhere.
- **Issue #169's workflow-injection sweep DID land on main.** Every free-text
  workflow input that reaches a remote shell is allowlisted, refuses a leading
  `-`, and refuses rather than strips. No outstanding injection.
- **No migrations in the deployed delta at all** — so the "MySQL vs SQLite"
  and 64-char index concerns are vacuous here. Every new `max:` bound matches
  its column precision.
- **No `float` on money or stock**; all quantities stay `decimal`/`bcmath`.
- **Timezone handling on the stock path is correct** (explicit offsets; no
  `now()` against a wall-clock string outside test fixtures).
- The one new endpoint is permission-gated and exposes no rates or supplier
  identity — FC-06 holds.
- Suite green: 2121 backend tests (1 skipped), frontend typecheck + 569 vitest
  tests. *Weak evidence by the author's own account* — a previously-green suite
  missed an unreachable guard because every assertion posted without a request
  line id.
- `ci.yml` uses `pull_request`, not `pull_request_target`, and references no
  secrets.

## 11. Migration completeness

Code is **complete**: 0 commits missing, all 5 tags present, all 17 unpushed
branches fully merged into main. Nothing at risk.

Lost (GitHub metadata does not travel with `git push`): **194 PRs**, **3
issues**. The old repo still EXISTS and is readable via the `muthukumarp-dm`
account — the `decisionm` account gets a 404 because it is private, which is
easy to misread as deletion. So the history is recoverable by API export while
that access lasts.

~543 `#NNN` references across the repo now dangle, ~192 of them in code, CI,
tests and the two skills agents load at decision time.

**Do NOT rewrite:** `docs/archive/*` (frozen), `DEC-20260812-001.md`
(immutable, tool-written — FC-08), `CURRENT-DECISIONS.md` (generated;
`validate.py` compares it byte-for-byte). `manifest.json` is canonical JSON —
a `sed` fix would break `check.sh`.

---

## Fix status (branch `fix/post-migration-hardening`)

| § | Finding | Status |
|---|---|---|
| 1 | Agent auto-updates from old domain | **Partly fixed** — feed repointed in the repo. *Installed agents are unaffected until rebuilt+republished*, so the old domain must stay parked. Code signing NOT added. |
| 2 | Agent publish "blocked" | **Was wrong** — the new host already serves all three artifacts (migrated with `storage/`). `FEED_URL` repointed; no seeding needed. |
| 2b | Tally agent cloud URL | Repo default fixed. **Factory PC still needs the Settings change + a new token** — that is an on-site action. |
| 3 | Refusal on open Q58(b) | **NOT fixed — owner's decision.** |
| 4 | 4th stock door | **NOT fixed** — would compound §3. |
| 5 | No `timeout-minutes` | Fixed (`25`). |
| 6 | False "still serving" messages | Fixed (all three). |
| 7 | Dumps in the docroot | Fixed — `BACKUP_DIR` → `$HOME/backups/erp`, with a fallback so it cannot create a new 503. **Old dumps still on the server need moving by hand.** |
| 8 | PHP pin / issue #167 | **NOT fixed** — touches the deploy path in 16 workflows; wants its own reviewed change. |
| 9 | Stale `origin` refs | Fixed in `status.sh` and `land-and-clean-a-branch`. `CLEAN-GIT-ARCHITECTURE.md` and branch tracking still outstanding. |

Verification after the fixes: release contract 32/32, agent suite 138/138, agent
`tsc --noEmit` clean, factory-knowledge 23/23 + `check.sh` sound, backend Pint
passed. `deploy.sh` and `status.sh` pass `bash -n`.

A `migrate-instance` skill was added at `.claude/skills/migrate-instance/` so the
next move starts from these failures rather than rediscovering them.

## Suggested order

1. Park/renew `erpdemo.amrtech.in` and repoint the agent feed (§1) — cheapest
   fix for the worst risk.
2. Decide Q58(b) with the owner, and soften the live refusal until then (§3).
3. `timeout-minutes` (§5) and the three false `deploy.sh` strings (§6) — both
   tiny, both operator-facing during an outage.
4. Move `BACKUP_FILE` out of the docroot (§7).
5. `pick-php.sh` extraction (§8); `--repo` on `status.sh` (§9).
6. Close the fourth stock door (§4).

Everything touching executing code belongs in a reviewed PR per AGENTS.md
(builder → Cursor → Codex → owner) — which is exactly the chain the deployed
delta did not get, because the new repo has no PRs.
