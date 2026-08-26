---
name: ship-a-pr
description: Use when a build is finished and needs to reach the live factory — the exact review chain (what "Cursor" and "Codex" mean and the commands that run them), the merge gate, what a merge triggers, and the testing that happens at each stage.
---

# Ship a PR — from finished code to the live factory

The chain, from AGENTS.md, in plain words:

    Builder (agent, with test evidence)
      → Cursor review        (a second AI reviews the diff)
        → Codex verification (a third AI reviews the diff)
          → owner / lead     (a HUMAN decides and merges)

"Cursor" and "Codex" are TOOLS, not people. Sendhil is a person (the lead
developer) — he holds the merge button. The two AI reviews exist so the
human is never the first pair of eyes on agent-built code.

## Step 1 — Builder finishes (before any review)

- `cd backend && ./vendor/bin/pint --dirty && php artisan test` — all green.
- `cd frontend && npm run typecheck && npm run test && npm run build` — all green.
- If docs/factory/ was touched: `scripts/factory-knowledge/check.sh` exits 0.
- Push the branch, open the PR as a **DRAFT** (`gh pr create --draft`).
  The PR body states plainly which SHAs the suites passed on.

## Step 2 — Cursor review (the local CLI, proven on PR #33)

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

## Step 3 — Codex verification (the GitHub app)

The repo has the `chatgpt-codex-connector` GitHub app installed. Trigger it
by commenting on the PR:

    @codex review

It reviews the PR head and attaches findings as a PR review. After pushing
fixes, comment `@codex review` again naming the new SHA — a review of an
old SHA does not cover a new one.

## Step 4 — Fix rounds

Findings come back to the builder. For every accepted finding: fix it,
write the test FIRST where the bug is behavior (watch it fail), re-run the
FULL suites, push, and reply on the PR mapping each finding to its fix
commit. Declined findings get a stated reason on the PR, never silence.

## Step 5 — The merge gate (HUMANS ONLY)

- A Claude/agent session NEVER merges, never marks ready-for-review to
  trigger a merge, and never pushes to main. A settings hook blocks merge
  commands in agent sessions as a backstop — do not work around it.
- The chain must have covered the EXACT head SHA. Reviews of older SHAs
  don't count (the 23-Aug standing rule).
- Only the owner may knowingly waive a leg, and the waiver is recorded as
  a PR comment (the PR #19/#20 precedent), never implied.

## Step 6 — What a merge does, and the testing after it

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
