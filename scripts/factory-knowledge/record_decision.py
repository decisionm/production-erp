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
from lib import ID_RE, knowledge_root, load_decisions, parse_front_matter


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
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", date):
        print(f"REFUSED: --confirmed-at must be YYYY-MM-DD, got {date!r}", file=sys.stderr)
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
    out_path = root / "decisions" / f"{new_id}.md"
    out_path.write_text("\n".join(lines), encoding="utf-8")

    # Flip the superseded records' status — statement text is never touched.
    for old_id in args.supersedes:
        old_path = Path(by_id[old_id]["_path"])
        text = old_path.read_text(encoding="utf-8")
        text = text.replace("status: current", f"status: superseded\nsuperseded_by: {new_id}", 1)
        old_path.write_text(text, encoding="utf-8")

    print(out_path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
