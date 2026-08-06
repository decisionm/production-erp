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

## Never

- Deploy or run write=true with failing tests or an unread dry run.
- Chain live workflow writes back-to-back without cooldown (that is what
  triggers the SSH ban).
