#!/usr/bin/env bash
# All factory-knowledge validation, one command, one exit code.
# Read-only: touches no application data, no database, no Tally.
set -euo pipefail
cd "$(dirname "$0")"
python3 validate.py
