#!/usr/bin/env bash
# Session-start status, computed LIVE — nothing here is a committed claim.
#
# WHY A SCRIPT AND NOT A COMMITTED STATUS FILE: a file recording "current
# branch and commit" is stale the moment it is committed (the commit that
# updates it changes the SHA it records), and hand-maintained "unfinished
# work" narrative fails the 30-day test. Volatile truth is computed, not
# written down. Owner-approved design decision, 06-Aug.
set -uo pipefail
cd "$(dirname "$0")/../.."

echo "── factory status ($(date '+%Y-%m-%d %H:%M')) ──"
echo "branch:   $(git branch --show-current)  @ $(git rev-parse --short HEAD)"
echo "worktree: $(git status --porcelain | wc -l | tr -d ' ') dirty file(s)"

if command -v gh >/dev/null 2>&1; then
  gh run list --workflow=deploy.yml --limit 1 \
    --json status,conclusion,headSha,updatedAt \
    --jq '"deploy:   \(.[]|.status) \(.[]|.conclusion // "") @ \(.[]|.headSha[0:7]) (\(.[]|.updatedAt[0:16]))"' \
    2>/dev/null || echo "deploy:   (gh unavailable — check GitHub Actions)"
  prs=$(gh pr list --state open --json number,title --jq 'length' 2>/dev/null || echo "?")
  echo "open PRs: ${prs}"
else
  echo "deploy:   (gh not installed — cannot check)"
fi

python3 scripts/factory-knowledge/validate.py | tail -1
open_q=$(grep -c '^## Q' docs/factory/PENDING-OWNER-QUESTIONS.md 2>/dev/null || echo 0)
echo "open owner questions: ${open_q}  → docs/factory/PENDING-OWNER-QUESTIONS.md"
