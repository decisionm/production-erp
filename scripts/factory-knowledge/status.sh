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
cd "$(dirname "$0")/../.."

echo "── factory status ($(date '+%Y-%m-%d %H:%M')) ──"
echo "branch:   $(git branch --show-current)  @ $(git rev-parse --short HEAD)"
echo "worktree: $(git status --porcelain | wc -l | tr -d ' ') dirty file(s)"

if command -v gh >/dev/null 2>&1; then
  deploy=$(gh run list --workflow=deploy.yml --limit 1 \
    --json status,conclusion,headSha,updatedAt \
    --jq '"deploy:   \(.[]|.status) \(.[]|.conclusion // "") @ \(.[]|.headSha[0:7]) (\(.[]|.updatedAt[0:16]))"' \
    2>/dev/null || true)
  # An empty run list yields empty output with exit 0 — the fallback has to
  # test the OUTPUT, not the exit code (reviewed 06 Aug).
  [ -n "$deploy" ] && echo "$deploy" || echo "deploy:   (no runs visible — check GitHub Actions)"
  prs=$(gh pr list --state open --json number --jq 'length' 2>/dev/null || echo "?")
  echo "open PRs: ${prs}"
else
  echo "deploy:   (gh not installed — cannot check)"
fi

# Full output on failure, one-line banner on success — never a truncated
# middle that hides "N problem(s)".
PYBIN="${PYTHON3:-python3}"
if ! command -v "$PYBIN" >/dev/null 2>&1; then
  echo "validation: CANNOT RUN — ${PYBIN} not found on this machine"
  exit 2
fi
validation=$("$PYBIN" scripts/factory-knowledge/validate.py 2>&1); vexit=$?
if [ "$vexit" -eq 0 ]; then
  echo "$validation" | head -1
else
  echo "$validation"
fi

# grep -c prints 0 AND exits 1 on zero matches — `|| echo 0` was appending a
# second line and printing "0\n0" (reviewed 06 Aug).
open_q=$(grep -c '^## Q' docs/factory/PENDING-OWNER-QUESTIONS.md 2>/dev/null || true)
echo "open owner questions: ${open_q:-0}  → docs/factory/PENDING-OWNER-QUESTIONS.md"

exit "$vexit"
