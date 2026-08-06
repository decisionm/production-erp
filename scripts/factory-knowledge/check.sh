#!/usr/bin/env bash
# All factory-knowledge validation, one command. Read-only.
#
# EXIT CODES — "failed" and "could not run" are different facts and callers
# need to tell them apart (owner condition, 06-Aug):
#   0  validation ran, knowledge system sound
#   1  validation ran, problems found
#   2  validation COULD NOT RUN on this machine (no python3)
set -uo pipefail
cd "$(dirname "$0")"
PY="${PYTHON3:-python3}"
if ! command -v "$PY" >/dev/null 2>&1; then
  echo "CANNOT RUN: ${PY} not found — knowledge validation unavailable on this machine; CI enforces it." >&2
  exit 2
fi
"$PY" validate.py
