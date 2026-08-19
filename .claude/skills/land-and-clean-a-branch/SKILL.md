---
name: land-and-clean-a-branch
description: Use when merging a PR, rebasing a conflicted one, closing a superseded one, or deleting branches/worktrees — what a merge triggers on this repo, and what is lost forever if a ref goes without being read first.
---

# Land a branch, then clean up after it

`deploy-live-verify` owns the deploy ritual. This starts one step earlier —
*should this merge happen at all* — and ends after the branch is gone.

Every rule here was paid for. The reasons are kept because a rule without its
reason gets "simplified" away by the next person.

## Before you merge: what does this merge TRIGGER?

Never assume. Read the workflow file **on the ref being merged**, not the one
you remember.

- `deploy.yml` fires on push to `main` with `paths-ignore: docs/**`. Docs-only
  merges do not deploy. **Anything else does** — a comment-only change to one
  PHP file is enough.
- `build-agent.yml`: publishing IS deploying to the factory floor. The agent
  self-updates within ~6h, and `update-downloaded` installs immediately.
- A deploy opens a maintenance window where every route 503s. A Tally rejection
  arriving in that window is lost silently and forever (issue #168). So a
  no-op redeploy is not free — if the payload is unchanged, question the merge
  timing rather than shrugging.


> ⚠️ **Never hardcode `origin/main` in these checks.** After the 19-Aug-2026
> migration `origin` still pointed at the OLD repo, so `origin/main` froze at
> the last fetch and **still resolved locally** — every command below answered
> confidently and WRONGLY, with no error. Resolve the real upstream first and
> use it everywhere:
>
> ```bash
> MAIN=$(git rev-parse --abbrev-ref main@{upstream})   # e.g. decisionm/main
> git fetch "${MAIN%%/*}" --prune
> ```

Check `git diff --name-only "$MAIN"..HEAD -- backend frontend` and the
migrations directory. Zero files there means the deployed app is byte-identical
to what already runs.

## Never merge a branch older than what is live

A PR is a snapshot of a moment, not a proposal that stays true.

```bash
git cherry "$MAIN" <branch>        # '+' = not on main, '-' = already there
git log --oneline $(git merge-base "$MAIN" <branch>).."$MAIN" | wc -l
```

Check the version the branch ships against the version in the field. A PR at
v0.3.2 merged while the field runs v0.3.5 regresses the download link to the
build that is field-proven to hang Tally — even though installed clients
refuse the downgrade.

## A superseded PR is CLOSED with evidence, never merged

If every commit has a patch-equivalent on main, the work already landed:

```bash
git cherry "$MAIN" <branch>        # all '-' means fully contained
```

Close it with the evidence in the comment — identical patch-ids, the
merge-base lag, and what later reversed the feature. A silent close teaches
nobody. Merging it "just to tidy up" collides with whatever superseded it.

## Rebasing a conflicted PR

1. **Back up the head first**, and name the SHA in your report:
   `git branch backup/<pr>-pre-rebase <sha>`
2. Work in a throwaway worktree so the main checkout is never disturbed.
3. **Generated files are REGENERATED, never hand-merged.** `validate.py`
   compares `CURRENT-DECISIONS.md` byte-for-byte against
   `generate_current.py --check`, so a hand-merge fails CI even when it looks
   right. Take either side to clear the conflict, then run the generator and
   check the header count is what you predicted. A surprising count means an
   unread supersession — read it before accepting.
4. **Append-registries** (`PENDING-OWNER-QUESTIONS.md`) conflict because both
   sides appended, not because they disagree. Keep both, in id order. Ids are
   reserved ahead of time, so a collision is usually not real — verify before
   re-minting.
5. Push with a lease pinned to the exact prior SHA:
   `git push --force-with-lease=<branch>:<sha> "${MAIN%%/*}" <branch>`

## Before deleting a ref, ask what ONLY that ref records

Content on main is recoverable. **Provenance is not.**

This repo had zero tags while five branch names were the only record of which
SHA was built and published to the factory as each agent version. Deleting them
would have silently destroyed the answer to "what was running on the day of the
incident".

Convert first, verify, then delete:

```bash
git show origin/<branch>:tally-sync-agent/package.json   # confirm the version matches
git tag -a agent-vX.Y.Z origin/<branch> -m "...released SHA, run <id>..."
git push "${MAIN%%/*}" --tags
```

## Before removing a worktree, look inside it

```bash
git -C <worktree> status --porcelain      # blank = safe
```

`git worktree remove` refuses a dirty tree — **do not reach for `--force` to
get past it.** A cleanup nearly destroyed a 513-line converter and a 2603-line
fixture that existed in no commit anywhere. Commit and push the work, then
remove the worktree.

Stashes survive branch deletion (they are independent refs), so a deleted
branch does not take its stash with it.

## Unpushed work is invisible — go looking for it

Nothing warns you that a branch exists only on one laptop.

```bash
git for-each-ref --format='%(refname:short) %(upstream)' refs/heads | awk '$2==""'
```

Eleven green commits closing a live security hole sat unpushed for two days.

## After merging, the docs go stale

A merge silently falsifies every sentence that described the branch as
unmerged. Sweep for it, and remember sentences wrap — a line-based grep misses
half of them:

```bash
grep -rn "unmerged\|PR #<n>" --include='*.md' --include='*.php' . | grep -v docs/archive/
```

Correct the FACT and keep the RULE. When a doc explains why something was done,
that reasoning is usually still true — mark a resolved section RESOLVED rather
than deleting it, so the next reader learns why it existed.

Never let this sweep answer an owner question or mark one resolved. Correcting
"this record is unmerged" is bookkeeping. Deciding what a record means is not.
