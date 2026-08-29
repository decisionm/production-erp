---
name: ship-a-pr
description: Use when a build is finished and needs to reach the live factory — the pre-merge testing bar, the optional Cursor/Codex review tools and the commands that run them, what a merge triggers, and the verification after it.
---

# Ship a PR — from finished code to the live factory

## Step 1 — Builder finishes (before anything else)

- `cd backend && ./vendor/bin/pint --dirty && php artisan test` — all green.
- `cd frontend && npm run typecheck && npm run test && npm run build` — all green.
- If docs/factory/ was touched: `scripts/factory-knowledge/check.sh` exits 0.
- Push the branch and open the PR (`gh pr create`). The PR body states
  plainly which SHAs the suites passed on.

## Optional quality tool — Cursor review (the local CLI, proven on PR #33)

An extra pair of AI eyes on the diff, used when the change warrants it.
Cursor's CLI is installed on this Mac as `cursor-agent` (logged in as the
user's own account — check with `cursor-agent status`). The flags matter:
a very long inline prompt returns EMPTY output, and `--mode=plan` blocks
the `git diff` it needs. What works:

1. Write the review instructions to a FILE (scope, lock order, FC-06, the
   output format, "READ-ONLY — do not edit anything").
2. Snapshot the tree first: `git diff --stat | shasum` and
   `git status --short | shasum` — verify both identical afterwards.
3. Run: `cursor-agent -p "Perform the code review described in <file>.
   Follow its instructions and output format exactly. Do not modify any
   file." --output-format text`
4. Post the verbatim output to the PR as a comment titled
   "Cursor review — commit <sha>", stating it was run locally and that the
   tree was verified untouched.

## Optional quality tool — Codex verification (the GitHub app)

The repo has the `chatgpt-codex-connector` GitHub app installed. Trigger it
by commenting on the PR:

    @codex review

It reviews the PR head and attaches findings as a PR review. After pushing
fixes, comment `@codex review` again naming the new SHA — a review of an
old SHA does not cover a new one.

## Fix rounds (when a review tool was used)

Findings come back to the builder. For every accepted finding: fix it,
write the test FIRST where the bug is behavior (watch it fail), re-run the
FULL suites, push, and reply on the PR mapping each finding to its fix
commit. Declined findings get a stated reason on the PR, never silence.

## What a merge does, and the testing around it

Merging to `main` PUSHES TO THE LIVE FACTORY: `.github/workflows/deploy.yml`
runs on every push to main (build → rsync to erp.actech.co.in). There is no
staging environment. Therefore:

- BEFORE merge: unit/feature suites (step 1) + a LOCAL end-to-end
  walkthrough — `composer run dev` + `npm run dev`, walk the changed flow
  in the browser at :5173 against seeded dev data. Dev data is not
  live-shaped (the 09-Aug lesson) — check is_active-style filters.
- AFTER merge: invoke the `deploy-live-verify` skill — confirms the
  workflow went green and smoke-checks the live surface READ-ONLY.
  On live: never create batches, never post vouchers, never move stock to
  "test" — live testing is reading screens and, where a write is needed,
  the owner/lead does it with real data.
