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
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib import ID_RE, knowledge_root, load_decisions


def next_id(existing: list[dict], date: str) -> str:
    compact = date.replace("-", "")
    taken = [
        int(m.group(2))
        for d in existing
        if (m := ID_RE.match(d.get("id", ""))) and m.group(1) == compact
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

    date = args.confirmed_at or datetime.date.today().isoformat()
    # Shape AND calendar. A shape-only regex accepted 2026-13-45 and minted a
    # mis-sorted immutable id (reviewed 06 Aug); fromisoformat alone accepts
    # dash-less forms in 3.11, so both checks stand.
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", date):
        print(f"REFUSED: --confirmed-at must be YYYY-MM-DD, got {date!r}", file=sys.stderr)
        return 2
    try:
        datetime.date.fromisoformat(date)
    except ValueError:
        print(f"REFUSED: {date!r} is not a real calendar date.", file=sys.stderr)
        return 2

    root = knowledge_root()
    decisions = load_decisions(root)
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
    # NEVER OVERWRITE. next_id counts parsed ids, not filenames — a record
    # whose id: field is mistyped leaves its FILENAME occupied while its id
    # goes uncounted, and write_text would have clobbered it silently
    # (reviewed 06 Aug). Immutability gets enforced at write time, not
    # promised in prose.
    if out_path.exists():
        print(f"REFUSED: {out_path.name} already exists on disk but its id: field does not "
              f"parse as {new_id}. Fix that record first; nothing was written.", file=sys.stderr)
        return 2

    # PREPARE EVERY SUPERSEDE FLIP BEFORE WRITING ANYTHING. The old approach
    # replaced the literal string 'status: current' and silently no-opped on
    # any other spelling (status: "current"), leaving the new record written
    # and history half-flipped (reviewed 06 Aug). Line-based, counted, and
    # verified up front — either the whole operation is possible or none of
    # it happens.
    flips: dict[Path, str] = {}
    for old_id in args.supersedes:
        old_path = Path(by_id[old_id]["_path"])
        text = old_path.read_text(encoding="utf-8")
        new_text, hits = re.subn(
            r"(?m)^status:.*$",
            f"status: superseded\nsuperseded_by: {new_id}",
            text,
            count=1,
        )
        if hits != 1:
            print(f"REFUSED: cannot find the status line in {old_path.name} to mark it "
                  "superseded. Nothing was written.", file=sys.stderr)
            return 2
        flips[old_path] = new_text

    lines = [
        "---",
        f"id: {new_id}",
        "status: current",
        "confirmed_by: owner",
        f"confirmed_at: {date}",
        "scope:",
        *[f"  - {s}" for s in args.scope],
    ]
    if args.supersedes:
        lines.append("supersedes:")
        lines.extend(f"  - {s}" for s in args.supersedes)
    lines += [
        "source:",
        f"  type: {args.source_type}",
        f"  reference: \"{args.source_ref}\"",
        "---",
        "",
        args.statement.strip(),
        "",
    ]

    (root / "decisions").mkdir(parents=True, exist_ok=True)
    out_path.write_text("\n".join(lines), encoding="utf-8")

    # The pre-verified flips — statement text is never touched.
    for old_path, new_text in flips.items():
        old_path.write_text(new_text, encoding="utf-8")

    print(out_path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
