#!/usr/bin/env bash
# Deterministic tests for the factory knowledge scripts (canonical-JSON
# format, DEC-20260806-012). Fixtures only — every test runs in a throwaway
# directory via FACTORY_KNOWLEDGE_ROOT; nothing here reads or writes the real
# docs/factory, the application, the database, or Tally.
#
# Valid fixtures are created THROUGH record_decision.py (records are
# tool-written by decision); invalid fixtures are hand-crafted precisely
# because hand-crafting is the failure mode under test.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SCRIPTS="$HERE/.."
PASS=0; FAIL=0

fixture() {
  ROOT="$(mktemp -d)"
  mkdir -p "$ROOT/decisions" "$ROOT/sources"
  python3 - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_manifest
Path(sys.argv[1], "sources", "manifest.json").write_text(canonical_manifest(
    {"placeholder": {"path": "https://example.invalid", "status": "endpoint"}}))
PY
  export FACTORY_KNOWLEDGE_ROOT="$ROOT"
}

record() { python3 "$SCRIPTS/record_decision.py" "$@" ; }
generate() { python3 "$SCRIPTS/generate_current.py" "$@" ; }
validate() { python3 "$SCRIPTS/validate.py" "$@" ; }

check() {  # check <name> <want_exit> <got_exit>
  if [ "$3" -eq "$2" ]; then PASS=$((PASS+1)); echo "  PASS  $1";
  else FAIL=$((FAIL+1)); echo "  FAIL  $1 (wanted exit $2, got $3)"; fi
}

valid_record() {  # valid_record <date> [extra args...] — via the tool, always
  local date="$1"; shift
  record --statement "A rule." --scope test --confirmed-by owner \
    --confirmed-at "$date" --source-type pr --source-ref "PR #1" "$@" >/dev/null 2>&1
}

echo "T1  an unconfirmed discussion cannot become a decision"
fixture
record --statement "Idea from a chat" --scope test --confirmed-by claude \
  --source-type owner-message --source-ref "a transcript" >/dev/null 2>&1
check "refuses non-owner confirmation" 2 $?

echo "T2  no evidence, no record (memory is not evidence)"
fixture
record --statement "Something remembered" --scope test --confirmed-by owner \
  --source-type owner-message --source-ref "   " >/dev/null 2>&1
check "refuses empty evidence reference" 2 $?

echo "T3  a confirmed decision creates one valid canonical record"
fixture
valid_record 2026-08-06; R1=$?
generate >/dev/null 2>&1 && validate >/dev/null 2>&1
check "record + generate + validate all green" 0 $(( R1 == 0 ? $? : R1 ))

echo "T4  a changed decision supersedes as data; history survives"
fixture
record --statement "Old rule." --scope packing --confirmed-by owner \
  --confirmed-at 2026-08-01 --source-type pr --source-ref "PR #1" >/dev/null 2>&1
record --statement "New rule." --scope packing --confirmed-by owner \
  --confirmed-at 2026-08-06 --source-type pr --source-ref "PR #2" \
  --supersedes DEC-20260801-001 >/dev/null 2>&1
generate >/dev/null 2>&1; validate >/dev/null 2>&1
V=$?
grep -q "Old rule." "$ROOT/decisions/DEC-20260801-001.md" \
  && grep -q '"status": "superseded"' "$ROOT/decisions/DEC-20260801-001.md" \
  && grep -q '"superseded_by": "DEC-20260806-001"' "$ROOT/decisions/DEC-20260801-001.md" \
  && ! grep -qF "**DEC-20260801-001**" "$ROOT/CURRENT-DECISIONS.md"
check "statement intact, flip is data not surgery, out of the view" 0 $(( V == 0 ? $? : V ))

echo "T5  a broken supersedes chain fails validation"
fixture
python3 - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_record
meta = {"id": "DEC-20260806-001", "status": "current", "confirmed_by": "owner",
        "confirmed_at": "2026-08-06", "scope": ["test"],
        "supersedes": ["DEC-20260101-001"],
        "source": {"type": "pr", "reference": "PR #9"}}
(Path(sys.argv[1]) / "decisions" / "DEC-20260806-001.md").write_text(
    canonical_record(meta, "Points at a record that does not exist."))
PY
generate >/dev/null 2>&1; validate >/dev/null 2>&1
check "missing supersedes target fails" 1 $?

echo "T6  a superseded record with no successor fails"
fixture
python3 - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_record
meta = {"id": "DEC-20260806-001", "status": "superseded", "confirmed_by": "owner",
        "confirmed_at": "2026-08-06", "scope": ["test"],
        "source": {"type": "pr", "reference": "PR #9"}}
(Path(sys.argv[1]) / "decisions" / "DEC-20260806-001.md").write_text(
    canonical_record(meta, "Orphaned history."))
PY
generate >/dev/null 2>&1; validate >/dev/null 2>&1
check "superseded without superseded_by fails" 1 $?

echo "T7  a stale generated view fails until regenerated"
fixture
valid_record 2026-08-06
generate >/dev/null 2>&1
echo "hand edit" >> "$ROOT/CURRENT-DECISIONS.md"
validate >/dev/null 2>&1; S=$?
generate >/dev/null 2>&1; validate >/dev/null 2>&1; F=$?
check "stale fails ($S), regenerated passes ($F)" 0 $(( S == 1 && F == 0 ? 0 : 1 ))

echo "T8  manifest honesty: lying present fails; missing-with-notes passes; no-sha256 fails"
fixture
python3 - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_manifest
Path(sys.argv[1], "sources", "manifest.json").write_text(canonical_manifest(
    {"gone": {"status": "present", "path": "no/such/file.xlsx", "sha256": "abc"}}))
PY
generate >/dev/null 2>&1; validate >/dev/null 2>&1; A=$?
python3 - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_manifest
Path(sys.argv[1], "sources", "manifest.json").write_text(canonical_manifest(
    {"gone": {"status": "missing", "path": "no/such/file.xlsx",
              "notes": "Transactions.xml deleted from Downloads — re-share needed"}}))
PY
validate >/dev/null 2>&1; B=$?
python3 - "$ROOT" "$SCRIPTS" <<'PY'
import os
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_manifest
root = Path(sys.argv[1])
real = root / "sources" / "real.bin"; real.write_bytes(b"data")
rel = os.path.relpath(real, root.parent.parent)
Path(root, "sources", "manifest.json").write_text(canonical_manifest(
    {"real": {"status": "present", "path": rel}}))
PY
validate >/dev/null 2>&1; C=$?
check "lying-present fails ($A), honest-missing passes ($B), unpinned-present fails ($C)" 0 $(( A == 1 && B == 0 && C == 1 ? 0 : 1 ))

echo "T9  malformed inputs fail safely — named error, no traceback"
fixture
printf 'not json at all' > "$ROOT/decisions/DEC-20260806-001.md"
OUT=$(validate 2>&1); S=$?
printf '{"id": "DEC-20260806-002"}no blank line' > "$ROOT/decisions/DEC-20260806-002.md"
OUT2=$(generate 2>&1); G=$?
{ echo "$OUT"; echo "$OUT2"; } | grep -q "Traceback" && TB=1 || TB=0
check "invalid JSON + missing separator: both exit 1, zero tracebacks" 0 $(( S == 1 && G == 1 && TB == 0 ? 0 : 1 ))

echo "T10 duplicate ids fail"
fixture
valid_record 2026-08-06
cp "$ROOT/decisions/DEC-20260806-001.md" "$ROOT/decisions/DEC-20260806-002.md"
generate >/dev/null 2>&1; validate >/dev/null 2>&1
check "duplicate id (filename mismatch) fails" 1 $?

echo "T11 a prose reference to a nonexistent decision fails"
fixture
valid_record 2026-08-06
generate >/dev/null 2>&1
printf '# Pending\n\n## Q1 x\n\nAnswered by DEC-20260806-001.\n' > "$ROOT/PENDING-OWNER-QUESTIONS.md"
validate >/dev/null 2>&1; A=$?
printf '# Pending\n\n## Q1 x\n\nAnswered by DEC-20991231-999.\n' > "$ROOT/PENDING-OWNER-QUESTIONS.md"
validate >/dev/null 2>&1; B=$?
check "real id passes ($A), phantom id fails ($B)" 0 $(( A == 0 && B == 1 ? 0 : 1 ))

echo "T12 the tool never overwrites an existing record file"
fixture
valid_record 2026-08-06
# Corrupt the id INSIDE the file so next_id thinks -001 is free while the
# filename stays occupied — the reviewer's reproduction.
printf '{\n  "id": "DEC-2026-08-06-001"\n}\n\nOriginal statement that must survive.\n' \
  > "$ROOT/decisions/DEC-20260806-001.md"
valid_record 2026-08-06; R=$?
grep -q "Original statement that must survive." "$ROOT/decisions/DEC-20260806-001.md"
check "refused (exit $R) and the occupant's bytes survive" 0 $(( R == 2 ? $? : 1 ))

echo "T13 canonical-form enforcement rejects hand-edits as a class"
fixture
valid_record 2026-08-06
generate >/dev/null 2>&1
validate >/dev/null 2>&1; A=$?
python3 - "$ROOT" <<'PY'
import json
import sys
from pathlib import Path
p = Path(sys.argv[1]) / "decisions" / "DEC-20260806-001.md"
text = p.read_text()
meta, end = json.JSONDecoder().raw_decode(text)
# Same data, different bytes: re-serialized with sorted keys — the classic
# well-meaning hand edit.
p.write_text(json.dumps(meta, indent=2, sort_keys=True) + text[end:])
PY
validate >/dev/null 2>&1; B=$?
python3 - "$SCRIPTS" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
from lib import canonical_record
meta = {"id": "DEC-20260806-001", "status": "current", "confirmed_by": "owner",
        "confirmed_at": "2026-08-06", "scope": ["test"], "sttatus": "oops",
        "source": {"type": "pr", "reference": "PR #1"}}
try:
    canonical_record(meta, "x")
    sys.exit(1)  # an unknown key must refuse
except ValueError:
    sys.exit(0)
PY
C=$?
check "tool-written passes ($A), reordered keys fail ($B), typo key refused ($C)" 0 $(( A == 0 && B == 1 && C == 0 ? 0 : 1 ))

echo "T14 impossible calendar dates are refused everywhere"
fixture
record --statement "A rule." --scope test --confirmed-by owner \
  --confirmed-at 2026-13-45 --source-type pr --source-ref "PR #1" >/dev/null 2>&1
R=$?
python3 - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_record
meta = {"id": "DEC-20261345-001", "status": "current", "confirmed_by": "owner",
        "confirmed_at": "2026-13-45", "scope": ["test"],
        "source": {"type": "pr", "reference": "PR #1"}}
(Path(sys.argv[1]) / "decisions" / "DEC-20261345-001.md").write_text(
    canonical_record(meta, "Minted from a typo."))
PY
generate >/dev/null 2>&1; validate >/dev/null 2>&1; V=$?
check "tool refuses (exit $R), validator fails the smuggled one (exit $V)" 0 $(( R == 2 && V == 1 ? 0 : 1 ))

echo "T15 golden bytes: the canonical form is pinned, not implicit"
fixture
python3 - "$SCRIPTS" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
from lib import canonical_record
meta = {"id": "DEC-20260101-001", "status": "current", "confirmed_by": "owner",
        "confirmed_at": "2026-01-01", "scope": ["test"],
        "source": {"type": "pr", "reference": "PR #1 — dash pins ensure_ascii"}}
golden = ('{\n  "id": "DEC-20260101-001",\n  "status": "current",\n'
          '  "confirmed_by": "owner",\n  "confirmed_at": "2026-01-01",\n'
          '  "scope": [\n    "test"\n  ],\n  "source": {\n    "type": "pr",\n'
          '    "reference": "PR #1 — dash pins ensure_ascii"\n  }\n}\n\nA rule.\n')
sys.exit(0 if canonical_record(meta, "A rule.") == golden else 1)
PY
check "byte-exact against the pinned golden string" 0 $?

echo "T16 multi-line statements render whole, never truncated"
fixture
record --statement "First line.
Second line that must not vanish." --scope test --confirmed-by owner \
  --confirmed-at 2026-08-06 --source-type pr --source-ref "PR #1" >/dev/null 2>&1
generate >/dev/null 2>&1
grep -q "Second line that must not vanish." "$ROOT/CURRENT-DECISIONS.md"
check "the qualifying second line is in the view" 0 $?

echo "T17 a leftover manifest.yaml beside manifest.json fails (one truth)"
fixture
valid_record 2026-08-06; generate >/dev/null 2>&1
touch "$ROOT/sources/manifest.yaml"
validate >/dev/null 2>&1
check "two manifests fail" 1 $?

echo "T18 check.sh distinguishes cannot-run (2) from failed (1)"
fixture
valid_record 2026-08-06; generate >/dev/null 2>&1
PYTHON3=definitely-not-a-binary bash "$SCRIPTS/check.sh" >/dev/null 2>&1; A=$?
printf 'not json' > "$ROOT/decisions/DEC-20260806-002.md"
bash "$SCRIPTS/check.sh" >/dev/null 2>&1; B=$?
check "no python3 → 2 (got $A); broken knowledge → 1 (got $B)" 0 $(( A == 2 && B == 1 ? 0 : 1 ))

unset FACTORY_KNOWLEDGE_ROOT
echo
echo "── ${PASS} passed, ${FAIL} failed ──"
[ "$FAIL" -eq 0 ]
