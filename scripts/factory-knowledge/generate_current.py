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
from lib import knowledge_root, load_decisions, read_exact, write_exact

HEADER = """\
# Current factory decisions

**GENERATED FILE — do not edit.** Regenerate with:
`python3 scripts/factory-knowledge/generate_current.py`

One line per decision still in force, newest first. The full record — evidence,
scope, what it replaced — is the file named by the ID in `decisions/`.

THIS FILE IS THE HUMAN SURFACE (FC-08). The record files behind it are a
tool-managed machine format — nobody should ever be sent to read one raw.
"""


def render(decisions: list[dict]) -> str:
    # DEFENSIVE ON EVERY FIELD. This runs on records validate.py may be about
    # to reject — a missing id, a source clobbered into a list — and a
    # renderer that tracebacks on them blocks regeneration of the view until
    # someone hand-repairs a file (reviewed 06 Aug). Broken fields render as
    # visibly broken; they never crash.
    def sort_key(d: dict) -> str:
        return str(d.get("id") or "")

    current = sorted((d for d in decisions if d.get("status") == "current"),
                     key=sort_key, reverse=True)
    superseded = sorted((d for d in decisions if d.get("status") == "superseded"),
                        key=sort_key, reverse=True)

    out = [HEADER]
    out.append(f"**{len(current)} current · {len(superseded)} superseded**\n")
    for d in current:
        scope = d.get("scope")
        scopes = ", ".join(scope) if isinstance(scope, list) else str(scope or "?")
        # The WHOLE statement, whitespace-collapsed to one line. Taking only
        # the first line silently hid every qualifying sentence after it —
        # and this view is what a recorder reads to spot a conflict before
        # writing a new decision (reviewed 06 Aug).
        statement = " ".join((d.get("_body") or "").split())
        src = d.get("source")
        source = src.get("reference", "") if isinstance(src, dict) else ""
        out.append(f"- **{d.get('id', '?')}** ({d.get('confirmed_at', '?')}, {scopes}) — {statement}")
        out.append(f"  - evidence: {source}")
    if superseded:
        out.append("\n## Superseded (history, still readable in decisions/)\n")
        for d in superseded:
            out.append(f"- {d.get('id', '?')} → replaced by {d.get('superseded_by', '?')}")
    return "\n".join(out) + "\n"


def main() -> int:
    # STRICT FLAGS. The old test was `"--check" in sys.argv`, so a typo
    # (--chcek) was silently ignored and the script took the DESTRUCTIVE
    # default — overwriting the very view whose staleness the caller was
    # trying to detect (reviewed 07-Aug). argparse makes an unknown flag an
    # error in a tool whose sibling is a validator.
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true",
                        help="verify the view matches decisions/ without writing")
    args = parser.parse_args()

    root = knowledge_root()
    target = root / "CURRENT-DECISIONS.md"
    try:
        content = render(load_decisions(root))
    except (ValueError, KeyError, AttributeError, TypeError, OSError) as err:
        # The EXPECTED failure classes, named — a malformed record, a
        # clobbered field, an unreadable file. Narrowed from a blanket
        # except (reviewed 07-Aug): anything outside these classes is a
        # genuine bug and SHOULD traceback rather than hide. Never a reason
        # to write a half-built view over a good one.
        print(f"cannot generate: {type(err).__name__}: {err}", file=sys.stderr)
        return 1
    if args.check:
        # read_exact: the default text-mode read folds CRLF to LF, so a
        # line-ending-mangled view compared equal (reviewed 07-Aug).
        if not target.exists() or read_exact(target) != content:
            print("STALE: CURRENT-DECISIONS.md does not match decisions/. Regenerate.",
                  file=sys.stderr)
            return 1
        return 0
    write_exact(target, content)
    print(target)
    return 0


if __name__ == "__main__":
    sys.exit(main())
