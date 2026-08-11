---
name: deploy-live-verify
description: Use when shipping code to the live factory or running live master-data changes — the deploy ritual, the dry-run-first rule, and the known infrastructure failure and its cure.
---

# Deploy to live, and verify it landed

The live instance is a working factory. Everything here is sequence, because
the sequence is what keeps a bad deploy from reaching a running shift.

## Code deploys

1. Branch → `./vendor/bin/pint --dirty`, `php artisan test` (backend),
   `npm run typecheck && npm run build` (frontend). All green BEFORE the PR.
2. PR → wait for ALL checks (never a counted number — this repo has added
   checks before and will again) → merge → GitHub Actions deploys
   automatically.
3. Watch the deploy run to completion. `scripts/factory-knowledge/status.sh`
   shows the latest deploy state and SHA.
4. Deploys run ONLY these seeders: Permission, Shift,
   ProductionConfigurationDefaults. Anything else on live is a manual
   workflow, never a deploy side effect.

## Live master-data changes (the important half)

1. Every change is a manual GitHub workflow wrapping an artisan command.
2. **Dry-run first, always** (`write=false`, the default). READ the output.
3. Only then `write=true`. Then dry-run AGAIN to prove nothing is left to do.
4. Seeder/rename lessons already paid for: idempotent-on-a-mutable-field is
   not idempotent (the shift duplication incident, PR #125); a row is not a
   figure (PR #133).

## The known failure

"The deploy host returned no SSH host key" — Hostinger brute-force
protection banning the runner IP. It fails BEFORE touching anything.
Wait several minutes, re-run. Never "fix" it by weakening the check.

**It is a ban, not a flake, and retrying inside the window EXTENDS it.**
Every SSH-using workflow shares the ban: deploy, read-server-log,
tally-sync-status, every master-data workflow. Paid for on 11-Aug-2026
twice in one night — a deploy re-run ~2 minutes after the first attempt,
then read-server-log fired ~3 minutes after the deploy's own SSH session.
Both were banned; the cost was ~20 minutes with live in a bad state.

Rules that follow:
- After ANY workflow that opens SSH, wait **~10 minutes** before opening
  another — including a retry of the one that just failed, and including
  the read-only ones.
- Measure the cooldown from the last SSH ATTEMPT, successful or not, not
  from when you noticed. Convert the timestamps properly: run logs are UTC,
  the factory clock is IST (+05:30). Mis-converting is how the second ban
  happened.
- A green deploy still counts as an SSH session. Do not chase it
  immediately with a log read.

## Deploying is a window, not an instant

The workflow closes the app (`artisan down`) BEFORE the rsync and reopens it
(`artisan up`, last line of `scripts/deploy.sh`) only after migrations
succeed. If a deploy fails, **the app is left DOWN on purpose** — read the
failure, fix, re-run (idempotent, takes a fresh backup). Do not `artisan up`
by hand to "restore service": that reopens the floor onto code whose schema
never migrated, which is the exact incident the window exists to prevent
(11-Aug-2026: rsync succeeded, migrate died on a transient DB refusal, live
served new code on the old schema — reads degraded silently, writes would
have thrown).

## Never

- Deploy or run write=true with failing tests or an unread dry run.
- Chain live workflow writes back-to-back without cooldown (that is what
  triggers the SSH ban).
- Re-trigger a live workflow inside the brute-force window.
- Treat a green CI run as a green deploy — they are different workflows.
  Check the run named "Deploy to Hostinger", and check the migrate step's
  own output, not just the tick.
