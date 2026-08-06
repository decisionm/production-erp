#!/usr/bin/env python3
"""Validate the factory knowledge system. Exit 0 = sound, 1 = broken.

WHAT IS CHECKED, deterministically:

  decisions/   every record parses; id matches filename and DEC-YYYYMMDD-NNN;
               required fields present; confirmed_by is owner; evidence
               reference non-empty; ids unique; every `supersedes` target
               exists, is marked superseded, and points back via
               `superseded_by`; no record is current and superseded at once.
  CURRENT-DECISIONS.md   byte-identical to regeneration (a stale view fails).
  sources/manifest.json  valid, CANONICAL JSON; every "present" entry names a
               repo file that exists and matches its pinned sha256; "missing"
               entries must say in notes what is needed; a leftover
               manifest.yaml beside it is an error (one truth).

  CANONICAL FORM: every record must byte-match the tool's own serialization
  of its parsed content — hand-edits fail as a class (DEC-20260806-012).

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
import datetime

from lib import ID_RE, canonical_manifest, canonical_record, knowledge_root, load_manifest, parse_record, sha256_of

REQUIRED = ["id", "status", "confirmed_by", "confirmed_at", "scope", "source"]


def validate_decisions(root: Path, errors: list[str]) -> None:
    decisions: dict[str, dict] = {}
    decisions_dir = root / "decisions"
    if not decisions_dir.is_dir():
        return

    for path in sorted(decisions_dir.glob("*.md")):
        raw = path.read_text(encoding="utf-8")
        try:
            meta, body = parse_record(raw, path=path.name)
        except ValueError as err:
            errors.append(str(err))
            continue

        # CANONICAL-FORM ENFORCEMENT — the structural guarantee that replaced
        # four individually-patched parser holes (06-Aug migration). A record
        # either byte-matches what the tool serializes from its own parsed
        # content, or it was hand-edited — which is unsupported by owner
        # decision (DEC-20260806-012). This also catches unknown/typo fields,
        # reordered keys, and stray whitespace, as a class.
        try:
            if canonical_record({k: v for k, v in meta.items()}, body) != raw:
                errors.append(f"{path.name}: not canonical — records are tool-written "
                              "(DEC-20260806-012); recreate it with record_decision.py")
        except ValueError as err:
            errors.append(f"{path.name}: {err}")

        # Strict types — JSON gives real arrays and maps; anything else is an
        # error, never a tolerated variant.
        if not isinstance(meta.get("scope"), list) or not all(isinstance(x, str) for x in meta.get("scope") or []):
            errors.append(f"{path.name}: scope must be an array of strings")
        if "supersedes" in meta and (not isinstance(meta["supersedes"], list)
                                     or not all(isinstance(x, str) for x in meta["supersedes"])):
            errors.append(f"{path.name}: supersedes must be an array of strings")

        # `id:` with no value parses to None — str() it before matching, or
        # the validator itself dies with a TypeError traceback and reports
        # nothing (reviewed 06 Aug).
        rid = str(meta.get("id") or "")
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
        if not meta.get("scope"):
            errors.append(f"{rid}: scope must be non-empty")
        source = meta.get("source")
        if not isinstance(source, dict) or not source.get("reference", "").strip():
            errors.append(f"{rid}: source.reference is empty — memory is not evidence")
        confirmed_at = str(meta.get("confirmed_at", ""))
        if not re.match(r"^\d{4}-\d{2}-\d{2}$", confirmed_at):
            errors.append(f"{rid}: confirmed_at must be YYYY-MM-DD")
        else:
            # Shape alone accepted 2026-13-45 and let a mis-sorted immutable
            # id validate as sound (reviewed 06 Aug).
            try:
                datetime.date.fromisoformat(confirmed_at)
            except ValueError:
                errors.append(f"{rid}: confirmed_at {confirmed_at!r} is not a real calendar date")
        if not body.strip():
            errors.append(f"{rid}: empty decision statement")
        if meta.get("status") == "current" and meta.get("superseded_by"):
            errors.append(f"{rid}: current but carries superseded_by — contradiction")
        if meta.get("status") == "superseded" and not meta.get("superseded_by"):
            errors.append(f"{rid}: superseded but names no superseding record")

        decisions[rid] = meta

    for rid, meta in decisions.items():
        for old in (meta.get("supersedes") or []):
            if old not in decisions:
                errors.append(f"{rid}: supersedes {old}, which does not exist")
            elif decisions[old].get("status") != "superseded":
                errors.append(f"{rid}: supersedes {old}, but {old} is still marked current")
            elif decisions[old].get("superseded_by") != rid:
                errors.append(f"{rid}: supersedes {old}, but {old}.superseded_by is "
                              f"{decisions[old].get('superseded_by')!r}")
        by = meta.get("superseded_by")
        if by and (by not in decisions or rid not in (decisions[by].get("supersedes") or [])):
            errors.append(f"{rid}: superseded_by {by}, but {by} does not list it in supersedes")


def validate_manifest(root: Path, errors: list[str]) -> None:
    if (root / "sources" / "manifest.yaml").exists():
        errors.append("legacy sources/manifest.yaml still exists beside manifest.json — "
                      "two truths; delete the yaml (migrated 06-Aug)")
    manifest = root / "sources" / "manifest.json"
    if not manifest.exists():
        errors.append("sources/manifest.json is missing")
        return
    try:
        meta = load_manifest(root)
    except ValueError as err:
        errors.append(str(err))
        return

    if canonical_manifest(meta) != manifest.read_text(encoding="utf-8"):
        errors.append("manifest.json is not canonical — re-serialize it "
                      "(sorted keys, pinned JSON parameters)")

    # Entries are top-level keys, each a one-level map.
    repo_root = root.parent.parent
    for name, entry in meta.items():
        if name.startswith("_"):
            continue  # documentation keys, not sources
        if not isinstance(entry, dict):
            errors.append(f"manifest {name}: entry must be an object")
            continue
        status = entry.get("status")
        if status == "present":
            # "Present" is the strongest claim in this file and gets the
            # strictest check: a repo path, a pinned sha256, both verified.
            # The old code skipped entries with no path and never required the
            # pin, so "present; sha256 pinned" could pin nothing (reviewed
            # 06 Aug).
            rel = entry.get("path", "")
            if not rel or rel.startswith(("http", "agent:", "~")):
                errors.append(f"manifest {name}: status present needs a repo-relative path — "
                              "an endpoint or home-dir source cannot be 'present'")
                continue
            target = repo_root / rel
            if not target.exists():
                errors.append(f"manifest {name}: status present but {rel} does not exist")
            elif not entry.get("sha256"):
                errors.append(f"manifest {name}: present without a sha256 pin — "
                              "the integrity the manifest header promises")
            elif sha256_of(target) != entry["sha256"]:
                errors.append(f"manifest {name}: sha256 mismatch for {rel} — "
                              "the source changed; re-verify and update the manifest")
        elif status == "missing":
            if not entry.get("notes", "").strip():
                errors.append(f"manifest {name}: missing sources must say in notes what is needed")
        elif status not in ("external", "endpoint"):
            errors.append(f"manifest {name}: status must be present/missing/external/endpoint")


def validate_prose_references(root: Path, errors: list[str]) -> None:
    """Every DEC-/FC- id mentioned in the knowledge prose must exist.

    Added after the cold-session review found two wrong DEC-ids in
    PENDING-OWNER-QUESTIONS.md — hand-typed cross-references are exactly the
    place ids go stale, and exactly what a regex can check. FC ids are
    checked against the constitution's headings the same way."""
    known_dec = {p.stem for p in (root / "decisions").glob("DEC-*.md")} if (root / "decisions").is_dir() else set()
    constitution = root / "FACTORY-CONSTITUTION.md"
    known_fc = set(re.findall(r"## (FC-\d{2})", constitution.read_text(encoding="utf-8"))) if constitution.exists() else set()

    repo_root = root.parent.parent
    prose: list[Path] = [
        p for p in [
            root / "PENDING-OWNER-QUESTIONS.md",
            root / "SOURCE-PRIORITY.md",
            constitution,
            root / "sources" / "manifest.json",
            # The records themselves: a statement citing "supersedes DEC-x"
            # is exactly the hand-typed reference this check exists for, and
            # the first version never scanned them (reviewed 06 Aug).
            *sorted((root / "decisions").glob("*.md")),
            repo_root / "AGENTS.md",
            repo_root / "CLAUDE.md",
            repo_root / "tally-sync-agent" / "CLAUDE.md",
            repo_root / "docs" / "archive" / "INDEX.md",
        ] if p.exists()
    ]
    skills_dir = repo_root / ".claude" / "skills"
    if skills_dir.is_dir():
        prose.extend(sorted(skills_dir.glob("*/SKILL.md")))

    for path in prose:
        text = path.read_text(encoding="utf-8")
        for rid in set(re.findall(r"DEC-\d{8}-\d{3}", text)):
            if rid not in known_dec:
                errors.append(f"{path.name}: references {rid}, which does not exist in decisions/")
        for fc in set(re.findall(r"FC-\d{2}", text)):
            if known_fc and fc not in known_fc:
                errors.append(f"{path.name}: references {fc}, which is not a heading in FACTORY-CONSTITUTION.md")


def main() -> int:
    root = knowledge_root()
    errors: list[str] = []

    validate_decisions(root, errors)
    validate_manifest(root, errors)
    validate_prose_references(root, errors)

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
