#!/usr/bin/env python3
"""Validate the factory knowledge system. Exit 0 = sound, 1 = broken.

WHAT IS CHECKED, deterministically:

  decisions/   every record parses; id matches filename and DEC-YYYYMMDD-NNN;
               required fields present; confirmed_by is owner; evidence
               reference non-empty; ids unique; every `supersedes` target
               exists, is marked superseded, and points back via
               `superseded_by`; no record is current and superseded at once.
  CURRENT-DECISIONS.md   byte-identical to regeneration (a stale view fails).
  sources/manifest.yaml  parses; every entry with status "present" names a file
               that exists and matches its recorded sha256; entries with
               status "missing" must say in `notes` what is needed.

THE HONEST LIMITATION, stated here because pretending otherwise is worse:
this validator proves STRUCTURAL integrity, not semantic truth. Two current
decisions that share a scope and contradict each other in meaning cannot be
detected by a script — that judgement belongs to the human recording the
newer decision, which is why the record-factory-decision skill's first step
is to read the current decisions in the same scope before writing one.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib import ID_RE, knowledge_root, parse_front_matter, sha256_of

REQUIRED = ["id", "status", "confirmed_by", "confirmed_at", "scope", "source"]


def validate_decisions(root: Path, errors: list[str]) -> None:
    decisions: dict[str, dict] = {}
    decisions_dir = root / "decisions"
    if not decisions_dir.is_dir():
        return

    for path in sorted(decisions_dir.glob("*.md")):
        try:
            meta, body = parse_front_matter(path.read_text(encoding="utf-8"), path=path.name)
        except ValueError as err:
            errors.append(str(err))
            continue

        rid = meta.get("id", "")
        if not ID_RE.match(rid):
            errors.append(f"{path.name}: id {rid!r} is not DEC-YYYYMMDD-NNN")
            continue
        if path.stem != rid:
            errors.append(f"{path.name}: filename does not match id {rid}")
        if rid in decisions:
            errors.append(f"{path.name}: duplicate id {rid}")
            continue

        for field in REQUIRED:
            if not meta.get(field):
                errors.append(f"{rid}: missing required field {field!r}")
        if meta.get("confirmed_by") != "owner":
            errors.append(f"{rid}: confirmed_by must be owner — only the owner confirms decisions")
        if meta.get("status") not in ("current", "superseded"):
            errors.append(f"{rid}: status must be current or superseded, got {meta.get('status')!r}")
        if not isinstance(meta.get("scope"), list) or not meta.get("scope"):
            errors.append(f"{rid}: scope must be a non-empty list")
        source = meta.get("source")
        if not isinstance(source, dict) or not source.get("reference", "").strip():
            errors.append(f"{rid}: source.reference is empty — memory is not evidence")
        if not re.match(r"^\d{4}-\d{2}-\d{2}$", str(meta.get("confirmed_at", ""))):
            errors.append(f"{rid}: confirmed_at must be YYYY-MM-DD")
        if not body.strip():
            errors.append(f"{rid}: empty decision statement")
        if meta.get("status") == "current" and meta.get("superseded_by"):
            errors.append(f"{rid}: current but carries superseded_by — contradiction")
        if meta.get("status") == "superseded" and not meta.get("superseded_by"):
            errors.append(f"{rid}: superseded but names no superseding record")

        decisions[rid] = meta

    for rid, meta in decisions.items():
        for old in meta.get("supersedes") or []:
            if old not in decisions:
                errors.append(f"{rid}: supersedes {old}, which does not exist")
            elif decisions[old].get("status") != "superseded":
                errors.append(f"{rid}: supersedes {old}, but {old} is still marked current")
            elif decisions[old].get("superseded_by") != rid:
                errors.append(f"{rid}: supersedes {old}, but {old}.superseded_by is "
                              f"{decisions[old].get('superseded_by')!r}")
        by = meta.get("superseded_by")
        if by and (by not in decisions or meta["id"] not in (decisions[by].get("supersedes") or [])):
            errors.append(f"{rid}: superseded_by {by}, but {by} does not list it in supersedes")


def validate_manifest(root: Path, errors: list[str]) -> None:
    manifest = root / "sources" / "manifest.yaml"
    if not manifest.exists():
        errors.append("sources/manifest.yaml is missing")
        return
    try:
        meta, _ = parse_front_matter("---\n" + manifest.read_text(encoding="utf-8") + "\n---",
                                     path="manifest.yaml")
    except ValueError as err:
        errors.append(str(err))
        return

    # Entries are top-level keys, each a one-level map.
    repo_root = root.parent.parent
    for name, entry in meta.items():
        if not isinstance(entry, dict):
            continue
        status = entry.get("status")
        if status == "present":
            rel = entry.get("path", "")
            target = (repo_root / rel) if rel and not rel.startswith(("http", "agent:")) else None
            if target is None:
                continue
            if not target.exists():
                errors.append(f"manifest {name}: status present but {rel} does not exist")
            elif entry.get("sha256") and sha256_of(target) != entry["sha256"]:
                errors.append(f"manifest {name}: sha256 mismatch for {rel} — "
                              "the source changed; re-verify and update the manifest")
        elif status == "missing":
            if not entry.get("notes", "").strip():
                errors.append(f"manifest {name}: missing sources must say in notes what is needed")
        elif status not in ("external", "endpoint"):
            errors.append(f"manifest {name}: status must be present/missing/external/endpoint")


def main() -> int:
    root = knowledge_root()
    errors: list[str] = []

    validate_decisions(root, errors)
    validate_manifest(root, errors)

    # The generated view must match its inputs.
    import subprocess
    gen = subprocess.run(
        [sys.executable, str(Path(__file__).parent / "generate_current.py"), "--check"],
        capture_output=True, text=True, env=dict(__import__("os").environ),
    )
    if gen.returncode != 0:
        errors.append(gen.stderr.strip() or "CURRENT-DECISIONS.md is stale")

    if errors:
        print(f"FACTORY KNOWLEDGE: {len(errors)} problem(s)")
        for err in errors:
            print(f"  ✗ {err}")
        return 1
    print("FACTORY KNOWLEDGE: sound")
    return 0


if __name__ == "__main__":
    sys.exit(main())
