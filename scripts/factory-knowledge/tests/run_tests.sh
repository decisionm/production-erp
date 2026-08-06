#!/usr/bin/env bash
# Deterministic tests for the factory knowledge scripts. Fixtures only —
# every test runs in a throwaway directory via FACTORY_KNOWLEDGE_ROOT, and
# nothing here reads or writes the real docs/factory, the application, the
# database, or Tally.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SCRIPTS="$HERE/.."
PASS=0; FAIL=0

fixture() {  # fresh knowledge root per test
  ROOT="$(mktemp -d)"
  mkdir -p "$ROOT/decisions" "$ROOT/sources"
  printf 'placeholder:\n  status: endpoint\n  path: "https://example.invalid"\n' > "$ROOT/sources/manifest.yaml"
  export FACTORY_KNOWLEDGE_ROOT="$ROOT"
}

record() { python3 "$SCRIPTS/record_decision.py" "$@" ; }
generate() { python3 "$SCRIPTS/generate_current.py" "$@" ; }
validate() { python3 "$SCRIPTS/validate.py" "$@" ; }

check() {  # check <name> <want_exit> <got_exit>
  if [ "$3" -eq "$2" ]; then PASS=$((PASS+1)); echo "  PASS  $1";
  else FAIL=$((FAIL+1)); echo "  FAIL  $1 (wanted exit $2, got $3)"; fi
}

echo "T1  an unconfirmed discussion cannot become a decision (confirmed_by != owner refused)"
fixture
record --statement "Idea from a chat" --scope test --confirmed-by claude \
  --source-type owner-message --source-ref "a transcript" >/dev/null 2>&1
check "refuses non-owner confirmation" 2 $?

echo "T2  no evidence, no record (memory is not evidence)"
fixture
record --statement "Something remembered" --scope test --confirmed-by owner \
  --source-type owner-message --source-ref "   " >/dev/null 2>&1
check "refuses empty evidence reference" 2 $?

echo "T3  a confirmed decision creates one valid immutable record"
fixture
record --statement "The factory ships one outer box per batch." --scope packing \
  --confirmed-by owner --confirmed-at 2026-08-06 \
  --source-type pr --source-ref "PR #136" >/dev/null 2>&1
R1=$?
generate >/dev/null 2>&1 && validate >/dev/null 2>&1
check "record + generate + validate all green" 0 $(( R1 == 0 ? $? : R1 ))

echo "T4  a changed decision supersedes; history survives"
fixture
record --statement "Old rule." --scope packing --confirmed-by owner \
  --confirmed-at 2026-08-01 --source-type pr --source-ref "PR #1" >/dev/null 2>&1
record --statement "New rule." --scope packing --confirmed-by owner \
  --confirmed-at 2026-08-06 --source-type pr --source-ref "PR #2" \
  --supersedes DEC-20260801-001 >/dev/null 2>&1
generate >/dev/null 2>&1; validate >/dev/null 2>&1
V=$?
grep -q "Old rule." "$ROOT/decisions/DEC-20260801-001.md" \
  && grep -q "status: superseded" "$ROOT/decisions/DEC-20260801-001.md" \
  && grep -q "superseded_by: DEC-20260806-001" "$ROOT/decisions/DEC-20260801-001.md" \
  && ! grep -qF "**DEC-20260801-001**" "$ROOT/CURRENT-DECISIONS.md"
check "old statement intact, marked superseded, out of the current view" 0 $(( V == 0 ? $? : V ))

echo "T5  a broken supersedes chain fails validation"
fixture
cat > "$ROOT/decisions/DEC-20260806-001.md" <<'REC'
---
id: DEC-20260806-001
status: current
confirmed_by: owner
confirmed_at: 2026-08-06
scope:
  - test
supersedes:
  - DEC-20260101-001
source:
  type: pr
  reference: "PR #9"
---

Points at a record that does not exist.
REC
generate >/dev/null 2>&1; validate >/dev/null 2>&1
check "missing supersedes target fails" 1 $?

echo "T6  a superseded record with no successor fails"
fixture
cat > "$ROOT/decisions/DEC-20260806-001.md" <<'REC'
---
id: DEC-20260806-001
status: superseded
confirmed_by: owner
confirmed_at: 2026-08-06
scope:
  - test
source:
  type: pr
  reference: "PR #9"
---

Orphaned history.
REC
generate >/dev/null 2>&1; validate >/dev/null 2>&1
check "superseded without superseded_by fails" 1 $?

echo "T7  a stale generated view fails until regenerated"
fixture
record --statement "A rule." --scope test --confirmed-by owner \
  --confirmed-at 2026-08-06 --source-type pr --source-ref "PR #1" >/dev/null 2>&1
generate >/dev/null 2>&1
echo "hand edit" >> "$ROOT/CURRENT-DECISIONS.md"
validate >/dev/null 2>&1; S=$?
generate >/dev/null 2>&1; validate >/dev/null 2>&1; F=$?
check "stale fails ($S), regenerated passes ($F)" 0 $(( S == 1 && F == 0 ? 0 : 1 ))

echo "T8  a manifest claiming a file that is not there fails; declared-missing passes"
fixture
printf 'gone:\n  status: present\n  path: "no/such/file.xlsx"\n  sha256: "abc"\n' > "$ROOT/sources/manifest.yaml"
generate >/dev/null 2>&1; validate >/dev/null 2>&1; A=$?
printf 'gone:\n  status: missing\n  path: "no/such/file.xlsx"\n  notes: "Transactions.xml deleted from Downloads — re-share needed"\n' > "$ROOT/sources/manifest.yaml"
validate >/dev/null 2>&1; B=$?
check "present-but-absent fails ($A), honest missing passes ($B)" 0 $(( A == 1 && B == 0 ? 0 : 1 ))

echo "T9  malformed input fails safely — named error, no crash, nothing corrupted"
fixture
printf 'no frontmatter at all' > "$ROOT/decisions/DEC-20260806-001.md"
OUT=$(validate 2>&1); S=$?
echo "$OUT" | grep -q "Traceback" && TB=1 || TB=0
check "malformed record: exit 1, no traceback" 0 $(( S == 1 && TB == 0 ? 0 : 1 ))

echo "T10 duplicate ids fail"
fixture
for f in a b; do cat > "$ROOT/decisions/DEC-20260806-00${f}.md" <<'REC'
---
id: DEC-20260806-001
status: current
confirmed_by: owner
confirmed_at: 2026-08-06
scope:
  - test
source:
  type: pr
  reference: "PR #1"
---

Twice the same id.
REC
done
mv "$ROOT/decisions/DEC-20260806-00a.md" "$ROOT/decisions/DEC-20260806-001.md"
mv "$ROOT/decisions/DEC-20260806-00b.md" "$ROOT/decisions/DEC-20260806-002.md"
generate >/dev/null 2>&1; validate >/dev/null 2>&1
check "duplicate id (and filename mismatch) fails" 1 $?

unset FACTORY_KNOWLEDGE_ROOT
echo
echo "── ${PASS} passed, ${FAIL} failed ──"
[ "$FAIL" -eq 0 ]
