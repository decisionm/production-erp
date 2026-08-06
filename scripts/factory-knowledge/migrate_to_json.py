#!/usr/bin/env python3
"""ONE-SHOT: convert the 16 constrained-YAML records + manifest.yaml to the
canonical JSON format. Reads with the old parser (its last act), writes with
the canonical serializer. Deleted in the commit after the migration, together
with the old parser, so a bisect to THIS commit can always reproduce the
conversion (owner condition 1, 06-Aug).

Refuses to run twice: a record that already parses as JSON is left alone and
reported. The real equivalence evidence is external to this script — the
byte-identical CURRENT-DECISIONS.md before and after, and the sorted-JSON
equivalence dumps of every parsed record and manifest entry, both produced by
the surrounding procedure and quoted in the sign-off report. This script's
own checks are belt only.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib import (canonical_manifest, canonical_record, knowledge_root,
                 parse_front_matter, parse_manifest_text, parse_record)

MANIFEST_FORMAT_NOTE = [
    "Source registry — where the original evidence lives. POINTS at evidence, never contains it.",
    "Statuses: present (in repo at path, sha256 pinned — validate.py checks both) · missing (once read, no longer held; notes say what is needed) · external (outside the repo, at risk) · endpoint (a live system, queried fresh).",
    "Keys starting with _ are documentation, not sources. Canonical JSON: edit via tooling or re-run validation until byte-canonical.",
]


def main() -> int:
    root = knowledge_root()
    converted = skipped = 0

    for path in sorted((root / "decisions").glob("*.md")):
        text = path.read_text(encoding="utf-8")
        try:
            parse_record(text, path=path.name)
            print(f"  already JSON, untouched: {path.name}")
            skipped += 1
            continue
        except ValueError:
            pass
        meta, body = parse_front_matter(text, path=path.name)
        path.write_text(canonical_record(meta, body), encoding="utf-8")
        print(f"  converted: {path.name}")
        converted += 1

    yaml_path = root / "sources" / "manifest.yaml"
    json_path = root / "sources" / "manifest.json"
    if yaml_path.exists():
        entries = parse_manifest_text(yaml_path.read_text(encoding="utf-8"))
        entries["_format"] = MANIFEST_FORMAT_NOTE
        json_path.write_text(canonical_manifest(entries), encoding="utf-8")
        yaml_path.unlink()
        print(f"  manifest: {yaml_path.name} → {json_path.name} (yaml removed — one truth)")

    print(f"records: {converted} converted, {skipped} already JSON")
    return 0


if __name__ == "__main__":
    sys.exit(main())
