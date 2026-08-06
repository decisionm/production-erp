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
# The suite honours the same PYTHON3 override the scripts do — T18 tests that
# override, so a harness that hardcoded python3 tested a contract it ignored
# (reviewed 07-Aug).
PY3="${PYTHON3:-python3}"
PASS=0; FAIL=0
FIXTURES=()
trap 'rm -rf "${FIXTURES[@]}" 2>/dev/null' EXIT   # no mktemp litter per test

fixture() {
  ROOT="$(mktemp -d)"
  FIXTURES+=("$ROOT")
  mkdir -p "$ROOT/decisions" "$ROOT/sources"
  # The core knowledge files, minimal — validation FAILS CLOSED when any is
  # absent (Rule A), so a fixture that wants "sound" must hold all three.
  printf '# Pending owner questions\n' > "$ROOT/PENDING-OWNER-QUESTIONS.md"
  printf '# Source priority\n' > "$ROOT/SOURCE-PRIORITY.md"
  printf '# Constitution\n\n## FC-01 · fixture boundary\n' > "$ROOT/FACTORY-CONSTITUTION.md"
  "$PY3" - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_manifest, write_exact
write_exact(Path(sys.argv[1], "sources", "manifest.json"), canonical_manifest(
    {"placeholder": {"path": "https://example.invalid", "status": "endpoint"}}))
PY
  export FACTORY_KNOWLEDGE_ROOT="$ROOT"
}

record() { "$PY3" "$SCRIPTS/record_decision.py" "$@" ; }
generate() { "$PY3" "$SCRIPTS/generate_current.py" "$@" ; }
validate() { "$PY3" "$SCRIPTS/validate.py" "$@" ; }

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
"$PY3" - "$ROOT" "$SCRIPTS" <<'PY'
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
"$PY3" - "$ROOT" "$SCRIPTS" <<'PY'
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
valid_record 2026-08-06; generate >/dev/null 2>&1
"$PY3" - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_manifest
Path(sys.argv[1], "sources", "manifest.json").write_text(canonical_manifest(
    {"gone": {"status": "present", "path": "no/such/file.xlsx", "sha256": "abc"}}))
PY
generate >/dev/null 2>&1; validate >/dev/null 2>&1; A=$?
"$PY3" - "$ROOT" "$SCRIPTS" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_manifest
Path(sys.argv[1], "sources", "manifest.json").write_text(canonical_manifest(
    {"gone": {"status": "missing", "path": "no/such/file.xlsx",
              "notes": "Transactions.xml deleted from Downloads — re-share needed"}}))
PY
validate >/dev/null 2>&1; B=$?
"$PY3" - "$ROOT" "$SCRIPTS" <<'PY'
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
"$PY3" - "$ROOT" <<'PY'
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
"$PY3" - "$SCRIPTS" <<'PY'
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
"$PY3" - "$ROOT" "$SCRIPTS" <<'PY'
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
"$PY3" - "$SCRIPTS" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
from lib import canonical_record
meta = {"id": "DEC-20260101-001", "status": "current", "confirmed_by": "owner",
        "confirmed_at": "2026-01-01", "scope": ["test"],
        "source": {"type": "pr", "reference": "PR #1 — dash pins ensure_ascii"}}
golden = ('{\n  "id": "DEC-20260101-001",\n  "status": "current",\n'
          '  "confirmed_by": "owner",\n  "confirmed_at": "2026-01-01",\n'
          '  "scope": [\n    "test"\n  ],\n  "source": {\n    "type": "pr",\n'
          '    "reference": "PR #1 — dash pins ensure_ascii"\n  },\n'
          '  "integrity": "sha256:4cfec28803a6ac7c92b8eb68fb7e2a6cba7e730e7aded8e1f7c356e1f0a98797"\n'
          '}\n\nA rule.\n')
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

echo "T19 CLASS: every field hand-edited in turn is refused — including the statement"
fixture
valid_record 2026-08-06
generate >/dev/null 2>&1
"$PY3" - "$ROOT" "$SCRIPTS" > "$ROOT/t19.log" <<'PY'
import copy
import json
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import FIELD_ORDER, JSON_KW, parse_record, read_exact, write_exact
import subprocess
path = Path(sys.argv[1]) / "decisions" / "DEC-20260806-001.md"
original = read_exact(path)
meta, body = parse_record(original, path=path.name)

def hand_write(mutated_meta, mutated_body):
    ordered = {k: mutated_meta[k] for k in FIELD_ORDER if k in mutated_meta}
    write_exact(path, json.dumps(ordered, **JSON_KW) + "\n\n" + mutated_body + "\n")

def refused() -> bool:
    result = subprocess.run([sys.executable, sys.argv[2] + "/validate.py"],
                            capture_output=True, text=True)
    ok = result.returncode == 1 and "Traceback" not in result.stderr + result.stdout
    write_exact(path, original)  # restore for the next mutation
    return ok

mutations = {
    "statement":  (meta, "A completely reworded rule."),
    "id":         ({**meta, "id": "DEC-20260806-099"}, body),
    "status":     ({**meta, "status": "superseded"}, body),
    "confirmed_by": ({**meta, "confirmed_by": "claude"}, body),
    "confirmed_at": ({**meta, "confirmed_at": "2026-08-05"}, body),
    "scope":      ({**meta, "scope": ["other"]}, body),
    "source":     ({**meta, "source": {"type": "pr", "reference": "PR #999 forged"}}, body),
    "integrity":  ({**meta, "integrity": "sha256:" + "0" * 64}, body),
}
failed = []
for name, (m, b) in mutations.items():
    hand_write(copy.deepcopy(m), b)
    if not refused():
        failed.append(name)
print("refused all" if not failed else "ACCEPTED: " + ", ".join(failed))
sys.exit(1 if failed else 0)
PY
T19=$?
grep -q "refused all" "$ROOT/t19.log"
check "all 8 hand-edits (7 fields + the statement) refused, no tracebacks" 0 $(( T19 == 0 ? $? : T19 ))

echo "T20 CLASS: every check fails closed when its input is deleted"
fixture
valid_record 2026-08-06; generate >/dev/null 2>&1
validate >/dev/null 2>&1; BASE=$?
FAILED_CLOSED=0
for target in decisions PENDING-OWNER-QUESTIONS.md SOURCE-PRIORITY.md FACTORY-CONSTITUTION.md sources/manifest.json CURRENT-DECISIONS.md; do
  mv "$ROOT/$target" "$ROOT/${target##*/}.away" 2>/dev/null || mv "$ROOT/$target" "$ROOT/away" 2>/dev/null
  validate >/dev/null 2>&1 && FAILED_CLOSED=1   # passing with input gone = fail-open
  mv "$ROOT/${target##*/}.away" "$ROOT/$target" 2>/dev/null || mv "$ROOT/away" "$ROOT/$target" 2>/dev/null
done
# ...and an EMPTY decisions dir is not sound either
mkdir "$ROOT/empty" && mv "$ROOT"/decisions/*.md "$ROOT/empty/"
validate >/dev/null 2>&1 && FAILED_CLOSED=1
mv "$ROOT"/empty/*.md "$ROOT/decisions/"
validate >/dev/null 2>&1; AFTER=$?
check "baseline sound ($BASE), 6 deletions + empty store all fail closed, restored sound ($AFTER)" 0 $(( BASE == 0 && FAILED_CLOSED == 0 && AFTER == 0 ? 0 : 1 ))

echo "T21 CLASS: wrong-typed values give named errors, never tracebacks"
fixture
valid_record 2026-08-06; generate >/dev/null 2>&1
"$PY3" - "$ROOT" "$SCRIPTS" > "$ROOT/t21.log" <<'PY'
import json
import subprocess
import sys
from pathlib import Path
root = Path(sys.argv[1])
scripts = sys.argv[2]
sys.path.insert(0, scripts)
from lib import canonical_manifest

def run_validate():
    result = subprocess.run([sys.executable, scripts + "/validate.py"],
                            capture_output=True, text=True)
    return result.returncode, "Traceback" in result.stdout + result.stderr

bad_records = [
    {"id": None},
    {"id": "DEC-20260806-002", "status": "current", "confirmed_by": "owner",
     "confirmed_at": "2026-08-06", "scope": "not-a-list",
     "source": {"type": "pr", "reference": "PR #1"}, "integrity": "x"},
    {"id": "DEC-20260806-002", "status": "current", "confirmed_by": "owner",
     "confirmed_at": "2026-08-06", "scope": ["test"],
     "source": ["not", "a", "map"], "integrity": "x"},
    {"id": "DEC-20260806-002", "status": "current", "confirmed_by": "owner",
     "confirmed_at": "2026-08-06", "scope": ["test"],
     "source": {"type": "pr", "reference": 12345}, "integrity": "x"},
    {"id": "DEC-20260806-002", "status": "superseded", "confirmed_by": "owner",
     "confirmed_at": "2026-08-06", "scope": ["test"], "superseded_by": ["a", "b"],
     "source": {"type": "pr", "reference": "PR #1"}, "integrity": "x"},
]
extra = root / "decisions" / "DEC-20260806-002.md"
crashed = []
for i, bad in enumerate(bad_records):
    extra.write_text(json.dumps(bad, indent=2) + "\n\nBody.\n")
    code, traceback_seen = run_validate()
    if code != 1 or traceback_seen:
        crashed.append(f"record[{i}] code={code} tb={traceback_seen}")
extra.unlink()

manifest = root / "sources" / "manifest.json"
keep = manifest.read_text()
bad_manifests = [
    '[]\n',
    json.dumps({"x": {"status": "present", "path": 42}}) + "\n",
    json.dumps({"x": {"status": "missing", "path": "a", "notes": None}}) + "\n",
    json.dumps({"dir": {"status": "present", "path": "docs", "sha256": "abc"}}) + "\n",
]
for i, bad in enumerate(bad_manifests):
    manifest.write_text(bad)
    code, traceback_seen = run_validate()
    if code != 1 or traceback_seen:
        crashed.append(f"manifest[{i}] code={code} tb={traceback_seen}")
manifest.write_text(keep)
print("all named" if not crashed else "CRASHED: " + "; ".join(crashed))
sys.exit(1 if crashed else 0)
PY
T21=$?
grep -q "all named" "$ROOT/t21.log"
check "5 bad records + 4 bad manifests: all exit 1, zero tracebacks" 0 $(( T21 == 0 ? $? : T21 ))

echo "T22 CLASS: supersession pathologies — self-loop, cycle, date smuggles"
fixture
valid_record 2026-08-06; generate >/dev/null 2>&1
"$PY3" - "$ROOT" "$SCRIPTS" > "$ROOT/t22.log" <<'PY'
import subprocess
import sys
from pathlib import Path
sys.path.insert(0, sys.argv[2])
from lib import canonical_record, write_exact
root = Path(sys.argv[1]) / "decisions"

def sealed(meta, body):
    write_exact(root / f"{meta['id']}.md", canonical_record(meta, body))

def validate_fails():
    result = subprocess.run([sys.executable, sys.argv[2] + "/validate.py"],
                            capture_output=True, text=True)
    return result.returncode == 1 and "Traceback" not in result.stdout + result.stderr

survived = []
base = {"confirmed_by": "owner", "confirmed_at": "2026-08-06",
        "scope": ["test"], "source": {"type": "pr", "reference": "PR #1"}}
# self-loop: sealed, canonical, every pairwise check self-consistent
sealed({**base, "id": "DEC-20260806-002", "status": "superseded",
        "supersedes": ["DEC-20260806-002"], "superseded_by": "DEC-20260806-002"}, "Self loop.")
if not validate_fails():
    survived.append("self-loop")
(root / "DEC-20260806-002.md").unlink()
# two-node cycle
sealed({**base, "id": "DEC-20260806-003", "status": "superseded",
        "supersedes": ["DEC-20260806-004"], "superseded_by": "DEC-20260806-004"}, "A.")
sealed({**base, "id": "DEC-20260806-004", "status": "superseded",
        "supersedes": ["DEC-20260806-003"], "superseded_by": "DEC-20260806-003"}, "B.")
if not validate_fails():
    survived.append("cycle")
(root / "DEC-20260806-003.md").unlink(); (root / "DEC-20260806-004.md").unlink()
# id date disagreeing with confirmed_at — sealed and canonical
sealed({**base, "id": "DEC-20260101-001", "status": "current"}, "Date smuggle.")
if not validate_fails():
    survived.append("id-date-mismatch")
(root / "DEC-20260101-001.md").unlink()
# future confirmed_at — sealed and canonical
sealed({**base, "id": "DEC-20300101-001", "status": "current",
        "confirmed_at": "2030-01-01"}, "From the future.")
if not validate_fails():
    survived.append("future-date")
(root / "DEC-20300101-001.md").unlink()
print("all caught" if not survived else "SURVIVED: " + ", ".join(survived))
sys.exit(1 if survived else 0)
PY
T22=$?
grep -q "all caught" "$ROOT/t22.log"
R=$?
record --statement "x" --scope test --confirmed-by owner --confirmed-at 2030-01-01   --source-type pr --source-ref "PR #1" >/dev/null 2>&1
FUT=$?
check "self-loop, cycle, both date smuggles caught ($T22); tool refuses future date ($FUT)" 0 $(( T22 == 0 && R == 0 && FUT == 2 ? 0 : 1 ))

echo "T23 CLASS: CRLF cannot hide behind text mode; a typo'd flag cannot destroy the view"
fixture
valid_record 2026-08-06; generate >/dev/null 2>&1
"$PY3" - "$ROOT" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1]) / "decisions" / "DEC-20260806-001.md"
data = p.read_bytes().replace(b"\n", b"\r\n")
p.write_bytes(data)
PY
OUT=$(validate 2>&1); S=$?
echo "$OUT" | grep -q "CRLF" ; NAMED=$?
"$PY3" - "$ROOT" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1]) / "decisions" / "DEC-20260806-001.md"
p.write_bytes(p.read_bytes().replace(b"\r\n", b"\n"))
PY
cp "$ROOT/CURRENT-DECISIONS.md" "$ROOT/view.before"
echo "stale marker" >> "$ROOT/CURRENT-DECISIONS.md"
generate --chcek >/dev/null 2>&1; TYPO=$?
grep -q "stale marker" "$ROOT/CURRENT-DECISIONS.md"; SURVIVED=$?
check "CRLF named+refused (S=$S, named=$NAMED); --chcek errors ($TYPO) and the divergent view survives ($SURVIVED)" 0 $(( S == 1 && NAMED == 0 && TYPO == 2 && SURVIVED == 0 ? 0 : 1 ))

unset FACTORY_KNOWLEDGE_ROOT
echo
echo "── ${PASS} passed, ${FAIL} failed ──"
[ "$FAIL" -eq 0 ]
