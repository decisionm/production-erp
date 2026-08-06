#!/usr/bin/env python3
"""Record ONE owner-confirmed factory decision as an immutable file.

    python3 record_decision.py \
        --statement "Scrap and lumps are booked to Tally as produced stock." \
        --scope production --scope tally-sync \
        --confirmed-by owner --confirmed-at 2026-08-05 \
        --source-type tally-journal \
        --source-ref "38 Stock Journals of Transactions.xml (31/38 book Pet Scrap); PR #110; commit 824def3" \
        [--supersedes DEC-20260731-001]

WHAT THIS SCRIPT REFUSES, AND WHY:

  - --confirmed-by anything but the literal word "owner". Only explicit owner
    confirmation creates a current decision. The flag exists so the INVOCATION
    itself asserts that confirmation happened — this script cannot verify it,
    and does not try. A human runs this; the human is making a claim and
    signing it.
  - An empty --source-ref. Memory is not evidence. A decision with no artifact
    behind it (a PR, a commit, a Tally journal, a dated owner message) belongs
    in PENDING-OWNER-QUESTIONS.md, not here.

WHAT IT NEVER DOES: rewrite history. A superseded record keeps its statement
forever; this script only flips its status and stamps superseded_by, so the
chain of what was believed when stays readable.
"""

from __future__ import annotations

import argparse
import datetime
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib import (ID_RE, canonical_record, create_exclusive, knowledge_root,
                 load_decisions, parse_record, validate_date, write_exact)


def next_id(existing: list[dict], date: str) -> str:
    compact = date.replace("-", "")
    taken = [
        int(m.group(2))
        for d in existing
        # str() before matching: an id of JSON null crashed ID_RE.match with
        # a TypeError (reviewed 07-Aug) — the validator had this guard, the
        # one script a human runs interactively did not.
        if (m := ID_RE.match(str(d.get("id") or ""))) and m.group(1) == compact
    ]
    return f"DEC-{compact}-{(max(taken) + 1) if taken else 1:03d}"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--statement", required=True)
    parser.add_argument("--scope", action="append", required=True)
    parser.add_argument("--confirmed-by", required=True)
    parser.add_argument("--confirmed-at", default=None, help="YYYY-MM-DD; defaults to today")
    parser.add_argument("--source-type", required=True,
                        choices=["owner-confirmation", "owner-message", "pr", "commit",
                                 "tally-journal", "workbook", "physical-process"])
    parser.add_argument("--source-ref", required=True)
    parser.add_argument("--supersedes", action="append", default=[])
    args = parser.parse_args()

    if args.confirmed_by != "owner":
        print("REFUSED: only the owner confirms a factory decision. "
              "If this is not owner-confirmed, add it to PENDING-OWNER-QUESTIONS.md instead.",
              file=sys.stderr)
        return 2

    if not args.statement.strip() or not args.source_ref.strip():
        print("REFUSED: a decision needs a statement and an evidence reference. "
              "Memory is not evidence.", file=sys.stderr)
        return 2

    # A blank scope — an unset shell variable, typically — writes a record
    # that scope-based conflict review can never match (reviewed 07-Aug).
    scopes = [scope.strip() for scope in args.scope]
    if not scopes or any(not scope for scope in scopes):
        print("REFUSED: every --scope must be a non-empty word.", file=sys.stderr)
        return 2

    date = args.confirmed_at or datetime.date.today().isoformat()
    # ONE date rule, shared with the validator (lib.validate_date): shape,
    # real calendar, and not in the future.
    problem = validate_date(date)
    if problem:
        print(f"REFUSED: --confirmed-at {problem}", file=sys.stderr)
        return 2

    root = knowledge_root()
    # ERROR BOUNDARY (reviewed 07-Aug): one malformed record anywhere in the
    # store made this tool traceback on load, with valid arguments. A broken
    # store is a named refusal that points at the fixer, not a crash.
    try:
        decisions = load_decisions(root)
    except ValueError as err:
        print(f"REFUSED: the knowledge store is unreadable — {err}\n"
              "Run scripts/factory-knowledge/check.sh and fix that first.", file=sys.stderr)
        return 2

    ids = [str(d.get("id") or "") for d in decisions]
    if len(ids) != len(set(ids)):
        # by_id would silently keep the LAST duplicate, so a supersede could
        # flip the wrong file. Undefined behaviour on duplicate input becomes
        # a named refusal (self-sweep, 07-Aug).
        print("REFUSED: duplicate ids in the store — run check.sh and fix that first.", file=sys.stderr)
        return 2
    by_id = {d.get("id"): d for d in decisions}

    for old_id in args.supersedes:
        if old_id not in by_id:
            print(f"REFUSED: --supersedes names {old_id}, which does not exist.", file=sys.stderr)
            return 2
        if by_id[old_id].get("status") == "superseded":
            print(f"REFUSED: {old_id} is already superseded by "
                  f"{by_id[old_id].get('superseded_by')}.", file=sys.stderr)
            return 2

    new_id = next_id(decisions, date)

    out_path = root / "decisions" / f"{new_id}.md"

    # PREPARE EVERY SUPERSEDE FLIP BEFORE WRITING ANYTHING — and as data,
    # not string surgery: parse, mutate the dict, re-serialize canonically.
    # A dict mutation cannot silently no-op the way a string replace did
    # (reviewed 06 Aug). Statement text is carried through verbatim.
    flips: dict[Path, str] = {}
    for old_id in args.supersedes:
        old_path = Path(by_id[old_id]["_path"])
        old_meta = {k: v for k, v in by_id[old_id].items() if not k.startswith("_")}
        old_meta["status"] = "superseded"
        old_meta["superseded_by"] = new_id
        flips[old_path] = canonical_record(old_meta, by_id[old_id]["_body"])

    meta = {
        "id": new_id,
        "status": "current",
        "confirmed_by": "owner",
        "confirmed_at": date,
        "scope": scopes,
        "source": {"type": args.source_type, "reference": args.source_ref},
    }
    if args.supersedes:
        meta["supersedes"] = list(args.supersedes)

    try:
        (root / "decisions").mkdir(parents=True, exist_ok=True)
    except OSError as err:
        # SELF-SWEEP (07-Aug): the load boundary above did not cover the
        # write side — a permissions problem was still a raw traceback.
        print(f"REFUSED: cannot write to the knowledge store — {err}", file=sys.stderr)
        return 2
    # EXCLUSIVE CREATE — one syscall does what a check-then-write pair
    # cannot: no overwrite of an id-mistyped occupant, and no window for two
    # concurrent recorders to clobber each other (reviewed 06/07-Aug).
    # Immutability enforced at write time, not promised in prose.
    try:
        create_exclusive(out_path, canonical_record(meta, args.statement))
    except FileExistsError:
        print(f"REFUSED: {out_path.name} already exists on disk (its id: field does not parse "
              f"as {new_id}, or a concurrent recorder got there first). Nothing was written.",
              file=sys.stderr)
        return 2
    except OSError as err:
        print(f"REFUSED: cannot write {out_path.name} — {err}", file=sys.stderr)
        return 2

    # The pre-computed flips — statement text is never touched.
    for old_path, new_text in flips.items():
        write_exact(old_path, new_text)

    print(out_path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
