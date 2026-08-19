#!/usr/bin/env bash
# Session-start status, computed LIVE — nothing here is a committed claim.
#
# WHY A SCRIPT AND NOT A COMMITTED STATUS FILE: a file recording "current
# branch and commit" is stale the moment it is committed (the commit that
# updates it changes the SHA it records), and hand-maintained "unfinished
# work" narrative fails the 30-day test. Volatile truth is computed, not
# written down. Owner-approved design decision, 06-Aug.
#
# EXITS NONZERO when the knowledge system fails validation — this is the
# check CLAUDE.md mandates at session start, so it must not smile through a
# broken state (reviewed 06 Aug: the old version showed one truncated error
# line and exited 0).
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR/../.."

echo "── factory status ($(date '+%Y-%m-%d %H:%M')) ──"
echo "branch:   $(git branch --show-current)  @ $(git rev-parse --short HEAD)"
echo "worktree: $(git status --porcelain | wc -l | tr -d ' ') dirty file(s)"

# Pin the repo explicitly. Without --repo, gh resolves through the `origin`
# remote — which after the 19-Aug-2026 migration still pointed at the OLD repo,
# so both calls 404'd, the 404 was swallowed by `2>/dev/null || true`, and this
# check (which CLAUDE.md mandates at session start) printed a confident
# "(no runs visible)" all-clear while the real deploy state was never read.
# A status check that cannot reach its repo must SAY SO, not shrug.
ERP_REPO="${ERP_REPO:-decisionm/production-erp}"

if command -v gh >/dev/null 2>&1; then
  if ! gh repo view "$ERP_REPO" >/dev/null 2>&1; then
    echo "deploy:   (CANNOT REACH $ERP_REPO — wrong gh account, or no access. NOT an all-clear.)"
    echo "open PRs: (unknown — see above)"
  else
  deploy=$(gh run list --repo "$ERP_REPO" --workflow=deploy.yml --limit 1 \
    --json status,conclusion,headSha,updatedAt \
    --jq '"deploy:   \(.[]|.status) \(.[]|.conclusion // "") @ \(.[]|.headSha[0:7]) (\(.[]|.updatedAt[0:16]))"' \
    2>/dev/null || true)
  # An empty run list yields empty output with exit 0 — the fallback has to
  # test the OUTPUT, not the exit code (reviewed 06 Aug).
  [ -n "$deploy" ] && echo "$deploy" || echo "deploy:   (no runs visible — check GitHub Actions)"
  prs=$(gh pr list --repo "$ERP_REPO" --state open --json number --jq 'length' 2>/dev/null || echo "?")
  echo "open PRs: ${prs}"
  fi
else
  echo "deploy:   (gh not installed — cannot check)"
fi

# Full output on failure, one-line banner on success — never a truncated
# middle that hides "N problem(s)".
# Validation is DELEGATED to check.sh — its interpreter probe, its exit
# contract (0 sound / 1 failed / 2 cannot run), one implementation. The two
# scripts re-implementing the same probe was exactly the copy-paste drift
# mechanism that let the manifest.yaml pointer go stale (reviewed 07-Aug).
validation=$(bash "$SCRIPT_DIR/check.sh" 2>&1); vexit=$?
if [ "$vexit" -eq 0 ]; then
  echo "$validation" | head -1
else
  echo "$validation"
fi

# grep -c prints 0 AND exits 1 on zero matches — `|| echo 0` was appending a
# second line and printing "0\n0" (reviewed 06 Aug).
#
# Count OPEN questions, not headings. A heading ending "— RESOLVED" is closed;
# "— PARTLY RESOLVED" and "— NARROWED" are NOT — the remaining half is still
# owed, and Q18/Q24/Q30 are exactly that. Counting every "## Q" reported 42
# when 30 were open, which overstates the backlog at the one moment a session
# is deciding what to work on (reviewed 16 Aug).
open_q=$(grep '^## Q' docs/factory/PENDING-OWNER-QUESTIONS.md 2>/dev/null \
  | grep -vc '— RESOLVED$' || true)
echo "open owner questions: ${open_q:-0}  → docs/factory/PENDING-OWNER-QUESTIONS.md"

exit "$vexit"
