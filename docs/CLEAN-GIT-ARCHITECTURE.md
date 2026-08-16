# The Definitive Guide to Clean Git Architecture & History Management

For engineers working in collaborative production repositories.

History is not documentation you write for posterity. It is a **queryable
operational database** you will interrogate at 03:00 during an incident. Every
technique here is judged on one question: does it make that query faster and
its answer more trustworthy?

---

## 1. Executive Summary

### Why this is operational, not aesthetic

**`git bisect` is O(log n), but only if commits are individually valid.**
Bisecting 1,000 commits takes ~10 tests instead of 1,000. That guarantee
collapses when commits don't build: each broken commit forces `git bisect skip`,
and enough skips leave you with a *range* of suspects instead of a culprit. A
"WIP" commit merged into `main` is not untidy — it is a hole in your ability to
find a regression.

```bash
git bisect start BAD GOOD
git bisect run ./scripts/verify.sh    # exit 0 good, 1 bad, 125 skip
```

`bisect run` is the point. It only works if every commit is testable.

**Incident investigation is a search problem.** These only work on a history
where one commit means one change:

```bash
git log -S'functionName' --oneline        # commits changing that string's count
git log -L 42,60:path/to/file             # evolution of a specific line range
git log --first-parent main               # release-level view, ignoring branch noise
```

`--first-parent` is why merge topology matters: with a consistent merge
strategy, it yields one entry per landed change.

**Rollback is only atomic if the change was atomic.** Reverting a feature
spread across 14 interleaved commits is an archaeology exercise. Reverting one
squash-merge, or one merge commit, is a single command.

**Change ownership** via `git blame` degrades badly with noise commits.
Mitigate what you can't prevent:

```bash
git blame --ignore-revs-file .git-blame-ignore-revs path/to/file
git config blame.ignoreRevsFile .git-blame-ignore-revs
```

Put bulk reformatting SHAs in that file. It is the standard escape hatch for
"the entire file is blamed on the person who ran the formatter."

**CI/CD reliability** depends on identity. Rewriting a published branch
invalidates SHAs that deployments, artifacts, and audit logs already reference.
A deploy log pointing at a SHA that no longer exists is an audit gap.

### Four different activities, routinely confused

| Activity | Operates on | Changes SHAs? | Coordination needed | Typical tool |
| --- | --- | --- | --- | --- |
| **Local history cleanup** | Unpushed commits | Yes, harmlessly | None | `rebase -i`, `--amend` |
| **Published history rewrite** | Shared branch | Yes, disruptively | Everyone who cloned | `rebase` + `--force-with-lease` |
| **Repository history cleanup** | Every commit, all time | Yes, all of them | Full team re-clone | `git filter-repo` |
| **Repository storage cleanup** | Objects, not history | **No** | None | `gc`, `repack`, LFS |

The last two are constantly conflated. **`git gc` does not shrink a repo that
is large because a 200 MB binary is in its history** — the object is still
reachable. Only rewriting history unreferences it. Conversely, if the repo is
slow because of loose objects, `gc` fixes it with no history change at all.
Diagnose before choosing:

```bash
git count-objects -vH
git rev-list --objects --all \
  | git cat-file --batch-check='%(objecttype) %(objectname) %(objectsize) %(rest)' \
  | awk '$1=="blob"' | sort -k3 -n -r | head -20
```

---

## 2. Core Capabilities Matrix

| Technique | Command | Primary Use Case | Rewrites History? | Risk Level | Safe on Public Branches? | Best Practice |
| --- | --- | --- | --- | --- | --- | --- |
| **Interactive Rebase** | `git rebase -i HEAD~N` | Reorder, squash, split, reword before review | **Yes** | High | **No** — unless the branch is yours alone and the team expects it | Only below the last published point; verify with `--exec` |
| **Commit Amend** | `git commit --amend` | Fix the tip: message, forgotten file | **Yes** (tip only) | Low locally, High if pushed | **No** | `--no-edit` to keep the message; never amend a commit others built on |
| **Cherry-Pick** | `git cherry-pick -x <sha>` | Backport a fix to a release branch | No (creates new commit) | Medium | **Yes** | Always `-x` to record the origin; check `git cherry` first for duplicates |
| **Patch Staging** | `git add -p` | Split unrelated work into separate commits | No | **None** | **Yes** | The cheapest way to get atomic commits — do it before committing, not after |
| **Pull with Rebase** | `git pull --rebase` | Integrate upstream without a merge bubble | Yes (your local commits) | Low | **Yes** (rewrites only your unpushed work) | Set `pull.rebase=true` and `rebase.autoStash=true` globally |
| **Reset** | `git reset --soft\|--mixed\|--hard` | Move the branch pointer; unstage; discard | **Yes** | `--hard` is the highest-risk command in Git | **No** | `--soft` to recompose commits; know `ORIG_HEAD` before `--hard` |
| **Revert** | `git revert <sha>` | Undo a **published** change | **No** | Low | **Yes** — this is the public-branch answer | `-m 1` for merge commits; `-n` to batch several into one commit |

**The line that governs the whole table:** rewriting is for history that only
you have seen. Reverting is for history the world has seen. When unsure which
side of the line you are on, run `git branch -r --contains <sha>`.

---

## 3. Interactive Rebase Deep Dive

```bash
git rebase -i HEAD~N
```

`N` counts commits back from the tip. Prefer naming the base explicitly, which
removes the off-by-one that `HEAD~N` invites:

```bash
git rebase -i origin/main          # everything your branch adds
git rebase -i $(git merge-base origin/main HEAD)
```

### The todo verbs

| Verb | Short | Effect |
| --- | --- | --- |
| `pick` | `p` | Keep as-is |
| `reword` | `r` | Keep the change, edit the message |
| `edit` | `e` | Stop here so you can amend or **split** |
| `squash` | `s` | Fold into previous, **combine both messages** |
| `fixup` | `f` | Fold into previous, **discard this message** |
| `drop` | `d` | Delete the commit entirely |
| `exec` | `x` | Run a shell command at this point |
| `break` | `b` | Stop unconditionally, for inspection |

Order in the file is chronological (oldest first) — the reverse of `git log`.
Reordering lines reorders commits, which is where conflicts come from.

### `--autosquash`: the workflow that makes rebase routine

Do not hand-edit todo lists to move fixups around. Mark them at commit time:

```bash
git commit --fixup=<sha>       # message becomes "fixup! <original subject>"
git commit --squash=<sha>      # same, but keeps your message for combining
git rebase -i --autosquash origin/main
```

Git pre-orders the todo list so each `fixup!` sits directly under its target,
already marked `fixup`. Make it the default:

```bash
git config --global rebase.autosquash true
```

This is the single highest-leverage habit in this document: review feedback
becomes `--fixup` commits, and the branch collapses to clean atomic commits
with one command before merge.

### `--exec`: proving every commit is bisectable

The guarantee that makes `bisect run` work must itself be verified:

```bash
git rebase -i --exec 'npm test' origin/main
```

The command runs after **every** commit. If one fails, the rebase stops there —
you have found a commit that doesn't stand alone, at authoring time rather than
during an incident six months later.

For expensive suites, verify buildability rather than full correctness:

```bash
git rebase -i --exec 'npm run typecheck' origin/main
```

### Splitting one commit into several

Mark it `edit`, then:

```bash
git reset HEAD^          # keep the changes, un-commit them (mixed reset)
git add -p               # stage the first coherent piece
git commit -m "First atomic change"
git add -p && git commit -m "Second atomic change"
git rebase --continue
```

### `--onto`: transplanting a branch

The three-argument form answers "these commits, but based somewhere else."
Given `main → feature-a → feature-b`, and `feature-a` is abandoned:

```bash
git rebase --onto main feature-a feature-b
```

Read it as: take commits after `feature-a` up to `feature-b`, replay onto
`main`.

### `--update-refs`: stacked branches

When several branches point into one stack, a rebase normally strands the
intermediate ones:

```bash
git rebase -i --update-refs origin/main
git config --global rebase.updateRefs true
```

Intermediate branch pointers move with the commits they marked.

### `rerere`: stop resolving the same conflict repeatedly

```bash
git config --global rerere.enabled true
```

Git records how you resolved a conflict and replays that resolution
automatically next time the same one appears — which is constant during
iterative rebases of a long-lived branch.

### Conflicts and exits

```bash
git rebase --continue     # after staging resolutions
git rebase --skip         # drop the commit being applied
git rebase --abort        # return to the pre-rebase state, always safe
git rebase --edit-todo    # change the plan mid-flight
```

`--abort` is genuinely safe: Git stashed the original tip. If you have already
finished a bad rebase, `--abort` is gone — use the reflog (§8).

### The risks, stated plainly

- **Never rebase a branch someone else has based work on.** Their commits now
  have no parent in your history, and their next `pull` produces duplicates.
- **A rebase changes every SHA it touches**, so review comments anchored to
  lines can detach, and CI results attached to old SHAs no longer apply.
- **Rebasing does not re-test.** Each commit is replayed, not verified. Without
  `--exec`, a clean rebase can produce commits that have never compiled.
- **Long-lived branches make conflicts quadratic.** Rebase onto upstream
  frequently rather than heroically at the end.

---

## 4. Amend and Patch Staging

*(Section 4 onward is inferred from your stated objectives — your message ended
mid-section-3.)*

### Amend

```bash
git commit --amend --no-edit          # add staged changes, keep the message
git commit --amend                    # edit the message
git commit --amend --author="Name <email>"
```

Amend **replaces** the tip commit with a new SHA. The old one survives in the
reflog until gc. The rule is mechanical: if `git branch -r --contains <sha>`
prints anything, amending it is a published rewrite.

### `git add -p` — the highest-value, zero-risk technique

Nothing else in this guide gives atomic commits so cheaply, and it rewrites
nothing.

| Key | Action |
| --- | --- |
| `y` / `n` | Stage / skip this hunk |
| `s` | Split into smaller hunks |
| `e` | Edit the hunk manually |
| `a` / `d` | Stage / skip this and all remaining hunks in the file |
| `q` | Quit |

`e` is the escape hatch when `s` cannot split far enough: delete `+` lines to
leave them unstaged; convert `-` lines to context by replacing `-` with a space.

Verify before committing — the staged and unstaged views must each make sense:

```bash
git diff --cached      # what you are about to commit
git diff               # what you are deliberately leaving behind
git stash --keep-index && npm test    # test ONLY what is staged
```

That last line is the discipline that makes atomic commits real rather than
aspirational: it proves the commit stands alone.

---

## 5. Cherry-Pick and Backporting

```bash
git cherry-pick -x <sha>              # -x appends "(cherry picked from commit ...)"
git cherry-pick -n <sha>              # stage without committing
git cherry-pick A..B                  # a range, exclusive of A
git cherry-pick A^..B                 # a range, inclusive of A
git cherry-pick -m 1 <merge-sha>      # a merge, relative to parent 1
```

**Always use `-x` on backports.** It is the only durable link between the
release-branch commit and its origin, and it is what an auditor follows.

### Detect an already-applied commit before picking

Cherry-picking produces a *different* SHA for the *same* change, so SHA
comparison cannot answer "is this already here?". Patch-ids can:

```bash
git cherry -v main release/1.2        # '+' = not in main, '-' = equivalent present
git patch-id --stable < <(git show <sha>)
```

This is how you tell a genuinely divergent branch from one that is simply a
rebase of work you already have — and therefore whether a PR is superseded.

### Conflicts

```bash
git cherry-pick --continue
git cherry-pick --skip
git cherry-pick --abort
```

If a pick conflicts heavily, the honest reading is usually that the fix depends
on refactoring absent from the target branch. Backport the dependency, or write
a deliberate variant commit — do not force a resolution that produces code
matching neither branch.

---

## 6. Pull with Rebase, and Keeping History Linear

```bash
git config --global pull.rebase true
git config --global rebase.autoStash true
git config --global fetch.prune true
```

Default `git pull` is fetch + merge, which manufactures a merge commit whose
only content is "I pulled." Hundreds of these destroy `--first-parent`.

`autoStash` removes the main friction: local edits are stashed and reapplied
around the rebase.

### Choosing a merge strategy — pick one, enforce it

| Strategy | History shape | Bisect | Revert | Best for |
| --- | --- | --- | --- | --- |
| **Squash merge** | Perfectly linear, 1 commit per PR | Excellent | Trivial (one SHA) | Most teams; small-to-medium PRs |
| **Rebase merge** | Linear, all commits preserved | Excellent **if** each commit is valid | Per-commit | Teams disciplined about atomic commits |
| **Merge commit** | Branching, `--first-parent` meaningful | Good with `--first-parent` | Trivial (`revert -m 1`) | Long-lived release branches; audit trails needing exact author history |

The failure mode is **inconsistency**. A repo mixing all three has no reliable
`--first-parent` view and no predictable revert procedure.

Enforce mechanically rather than by convention:

```bash
gh api -X PUT repos/:owner/:repo/branches/main/protection \
  -F required_linear_history=true
```

---

## 7. Reset versus Revert

### What each reset moves

| Mode | HEAD | Index | Working tree | Use for |
| --- | --- | --- | --- | --- |
| `--soft` | Moves | Untouched | Untouched | Recompose the last N commits into one |
| `--mixed` (default) | Moves | Reset | Untouched | Unstage; keep the edits |
| `--hard` | Moves | Reset | **Overwritten** | Discard everything — irreversible for uncommitted work |

Squash the last three commits without an interactive rebase:

```bash
git reset --soft HEAD~3 && git commit -m "One coherent change"
```

**`--hard` is the only command here that destroys work the reflog cannot
recover**, because uncommitted changes were never in an object. Committed work
is recoverable (§8); uncommitted work is gone. `git stash` first when unsure.

### Revert is the public-branch answer

```bash
git revert <sha>                  # a new commit undoing that one
git revert -n <sha1> <sha2>       # stage several, commit once
git revert -m 1 <merge-sha>       # undo a merge, keeping mainline (parent 1)
```

`-m 1` is required for merges — Git cannot know which parent is "mainline."
Parent 1 is the branch you merged *into*.

**The trap:** reverting a merge, then later merging that branch again, brings
back nothing — the merge base already accounts for those commits. To re-land
it, revert the revert (`git revert <revert-sha>`), then merge.

---

## 8. Recovery Procedures

Almost nothing committed is truly lost. Recovery is a lookup, not a miracle.

### The reflog

```bash
git reflog                        # every position HEAD has held
git reflog show <branch>          # per-branch movement
git reset --hard HEAD@{2}         # return to a prior position
git reflog --date=relative
```

Reflog entries are local and expire (90 days reachable, 30 unreachable by
default). A colleague's reflog cannot save you.

### `ORIG_HEAD`

Set automatically before any dangerous operation — reset, rebase, merge, pull:

```bash
git reset --hard ORIG_HEAD        # undo the last such operation
```

### Genuinely orphaned commits

```bash
git fsck --lost-found --no-reflogs
git show <dangling-sha>
git branch recovered <dangling-sha>
```

### `--force-with-lease`, and its one sharp edge

```bash
git push --force-with-lease origin feature
git push --force-with-lease=feature:<expected-sha> origin feature    # explicit, safer
```

The lease refuses the push if the remote moved since your last fetch, so you
cannot silently clobber a colleague. **The sharp edge:** any command that
updates your remote-tracking ref — including a background `git fetch` — updates
the lease's reference point, and the protection quietly evaporates. Always pass
the **explicit expected SHA** when the push matters.

### The habit that outperforms all of the above

```bash
git branch backup/before-rebase-$(git rev-parse --short HEAD)
```

One command, zero risk, no expiry, and it survives everything the reflog does
not.

---

## 9. Repository Storage Cleanup

**A different problem from history cleanup.** Fix the diagnosis first (§1).

### Removing a large file or secret from all history

`git filter-branch` is deprecated and dangerously slow. Use `git filter-repo`:

```bash
git filter-repo --path secrets.env --invert-paths
git filter-repo --strip-blobs-bigger-than 10M
git filter-repo --replace-text expressions.txt      # redact strings in place
```

Consequences, all unavoidable:

- **Every SHA from the earliest rewritten commit onward changes.** Tags, PR
  references, deploy logs, and issue links break.
- **Every clone must be re-created.** A colleague who pulls instead will
  re-introduce the old objects.
- `filter-repo` intentionally removes the `origin` remote afterwards, to stop a
  reflexive push.

**For a leaked secret, rotation comes first, always.** Assume the secret is
compromised the moment it is pushed — it is in clones, forks, CI caches, and
provider mirrors. Rewriting history is cleanup *after* rotation, never a
substitute for it.

### Reclaiming space with no history change

```bash
git gc --prune=now --aggressive
git repack -adf --depth=250 --window=250
git remote prune origin
```

### Large binaries, going forward

```bash
git lfs migrate import --include="*.psd,*.zip" --everything
```

`lfs migrate import` **is** a history rewrite, with all of §9's consequences.
`git lfs track` for new files is not.

### Working in a large repo without rewriting

```bash
git clone --filter=blob:none <url>        # blobless: fetch content on demand
git clone --depth=1 <url>                 # shallow: CI only, breaks bisect
git sparse-checkout set path/to/subdir
```

Blobless partial clone is usually the right default for large monorepos: full
history for `log` and `bisect`, blobs fetched only when needed.

---

## 10. Team Policies and Enforcement

Policy that lives in a wiki is a suggestion. Policy in branch protection and CI
is a rule.

### Protect the trunk

- Require pull requests; disallow direct pushes.
- Require status checks, and **require the branch to be up to date** before
  merge — otherwise CI passed against a state that never merges.
- Enable **required linear history** if you chose squash or rebase merges.
- Block force-push and deletion on `main` and release branches.
- Require review from code owners on paths that carry real risk.

Audit that it is actually on — an unprotected trunk is silent:

```bash
gh api repos/:owner/:repo/branches/main/protection
```

A `404` means no protection at all.

### Know what a merge triggers

Before merging, read the workflow files **on the ref being merged** — path
filters make this non-obvious:

```bash
git diff --name-only origin/main..HEAD
```

A change that looks like documentation can still touch a filtered path and fire
a production deployment.

### Make each commit verifiable in CI

If you promise bisectable history, test the promise:

```yaml
- run: |
    git rev-list --reverse origin/main..HEAD | while read sha; do
      git checkout -q "$sha" && npm run typecheck || exit 1
    done
```

### Written rules worth having

1. **Rewrite only what is unpublished.** `git branch -r --contains <sha>` settles it.
2. **Shared branches are undone with `revert`, never `reset`.**
3. **Force-push only with `--force-with-lease`,** with an explicit SHA on protected work.
4. **One logical change per commit.** `git add -p` is how, not willpower.
5. **A closed PR states why.** A silent close teaches nobody.
6. **Tag before deleting a ref that records a release.** Content is recoverable; provenance is not.
7. **Nobody force-pushes another person's branch without telling them.**

---

## 11. Quick Reference

### Before opening a PR

```bash
git fetch origin && git rebase -i --autosquash origin/main
git rebase --exec 'npm test' origin/main
git log --oneline origin/main..HEAD
git diff --name-only origin/main..HEAD
```

### Recovery

| Situation | Command |
| --- | --- |
| Bad rebase, still in progress | `git rebase --abort` |
| Bad rebase, already finished | `git reset --hard ORIG_HEAD` |
| Lost a commit | `git reflog` → `git reset --hard HEAD@{n}` |
| Deleted a branch | `git branch <name> <sha-from-reflog>` |
| Truly orphaned | `git fsck --lost-found --no-reflogs` |
| Wrong file in last commit | `git reset HEAD^ -- path && git commit --amend --no-edit` |
| Committed to the wrong branch | `git reset --hard HEAD~1` after `git cherry-pick` onto the right one |

### Recommended global config

```bash
git config --global pull.rebase true
git config --global rebase.autoStash true
git config --global rebase.autosquash true
git config --global rebase.updateRefs true
git config --global rerere.enabled true
git config --global fetch.prune true
git config --global merge.conflictstyle zdiff3
git config --global blame.ignoreRevsFile .git-blame-ignore-revs
```

`zdiff3` is materially better than the default conflict style: it shows the
common ancestor, which usually makes the correct resolution obvious.

---

## Closing

The techniques divide cleanly by audience:

- **Only you have seen it** → rebase, amend, reset. Shape it freely.
- **Others have seen it** → revert, cherry-pick, forward-only. Add, never alter.
- **It must leave the repository** → `filter-repo`, and a coordinated re-clone.
- **It is only taking up space** → `gc` and `repack`, and no history changes.

Almost every Git disaster is one of these applied in the wrong column.
