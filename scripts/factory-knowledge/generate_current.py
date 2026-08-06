#!/usr/bin/env python3
"""Generate CURRENT-DECISIONS.md from docs/factory/decisions/.

The generated file is a VIEW, never a source: hand-editing it changes nothing
and validate.py fails until it is regenerated, which is exactly the property
that lets a reader trust it. Deterministic output — same records, same bytes.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib import knowledge_root, load_decisions

HEADER = """\
# Current factory decisions

**GENERATED FILE — do not edit.** Regenerate with:
`python3 scripts/factory-knowledge/generate_current.py`

One line per decision still in force, newest first. The full record — evidence,
scope, what it replaced — is the file named by the ID in `decisions/`.
"""


def render(decisions: list[dict]) -> str:
    current = sorted(
        (d for d in decisions if d.get("status") == "current"),
        key=lambda d: d.get("id", ""), reverse=True,
    )
    superseded = sorted(
        (d for d in decisions if d.get("status") == "superseded"),
        key=lambda d: d.get("id", ""), reverse=True,
    )

    out = [HEADER]
    out.append(f"**{len(current)} current · {len(superseded)} superseded**\n")
    for d in current:
        scopes = ", ".join(d.get("scope") or [])
        statement = (d.get("_body") or "").split("\n")[0]
        source = (d.get("source") or {}).get("reference", "")
        out.append(f"- **{d['id']}** ({d.get('confirmed_at')}, {scopes}) — {statement}")
        out.append(f"  - evidence: {source}")
    if superseded:
        out.append("\n## Superseded (history, still readable in decisions/)\n")
        for d in superseded:
            out.append(f"- {d['id']} → replaced by {d.get('superseded_by', '?')}")
    return "\n".join(out) + "\n"


def main() -> int:
    root = knowledge_root()
    target = root / "CURRENT-DECISIONS.md"
    try:
        content = render(load_decisions(root))
    except ValueError as err:
        # A malformed record is a validation problem with a name, not a crash —
        # and emphatically not a reason to write a half-built view over a good one.
        print(f"cannot generate: {err}", file=sys.stderr)
        return 1
    if "--check" in sys.argv:
        if not target.exists() or target.read_text(encoding="utf-8") != content:
            print("STALE: CURRENT-DECISIONS.md does not match decisions/. Regenerate.",
                  file=sys.stderr)
            return 1
        return 0
    target.write_text(content, encoding="utf-8")
    print(target)
    return 0


if __name__ == "__main__":
    sys.exit(main())
