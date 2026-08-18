#!/usr/bin/env python3
"""Validate the factory knowledge system. Exit 0 = sound, 1 = broken.

TWO RULES GOVERN EVERY CHECK HERE (owner, 07-Aug):

  RULE A — FAIL CLOSED. No check may pass because its input was missing,
  empty, absent, or the wrong type. "Nothing to check" is a named error,
  never "sound". Applied to: the decisions directory itself, an empty
  store, each core knowledge file, a constitution with no FC headings, an
  empty manifest, and every wrong-typed value that used to traceback.

  RULE B — COVER THE PAYLOAD, NOT THE ENVELOPE. Every record carries an
  integrity hash over ALL its fields and its statement (lib.payload_hash);
  a hand edit of ANY field or of the statement — canonical in shape or not
  — breaks it. Byte-canonical checking alone pinned only the envelope: a
  reworded statement round-tripped as sound (reviewed 07-Aug). Reads are
  newline-exact (lib.read_exact), so CRLF mangling cannot hide behind
  text-mode normalization.

THE HONEST LIMITATIONS, still true and still stated: semantic conflict
between two decisions needs the human recording the newer one (the
record-factory-decision skill's first step). The integrity hash is
tamper-EVIDENT against hand edits, not cryptographic proof against a
determined forger. FILE-PATH references inside record bodies and prose are
NOT yet validated — an open owner question (PENDING-OWNER-QUESTIONS Q14,
07-Aug), with DEC-20260806-001's stale manifest.yaml pointer as the live
instance. Not silently pre-empted here; not silently forgotten either.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib import (ID_RE, canonical_manifest, canonical_record, knowledge_root,
                 load_manifest, parse_record, payload_hash, read_exact,
                 sha256_of, validate_date)

REQUIRED = ["id", "status", "confirmed_by", "confirmed_at", "scope", "source", "integrity"]


def validate_decisions(root: Path, errors: list[str]) -> dict[str, dict]:
    decisions: dict[str, dict] = {}
    decisions_dir = root / "decisions"

    # RULE A: a missing store is not an empty result, and an empty store is
    # not a sound one — this system has 17 records; zero means something is
    # very wrong, and "nothing to check" must never smile.
    if not decisions_dir.is_dir():
        errors.append("decisions/ directory is missing — nothing to check is not sound")
        return decisions
    paths = sorted(decisions_dir.glob("*.md"))
    if not paths:
        errors.append("decisions/ holds no records — an empty store validates nothing")
        return decisions

    for path in paths:
        try:
            raw = read_exact(path)
            meta, body = parse_record(raw, path=path.name)
        except (ValueError, OSError) as err:
            errors.append(str(err))
            continue

        rid = str(meta.get("id") or "")
        id_match = ID_RE.match(rid)
        if not id_match:
            errors.append(f"{path.name}: id {meta.get('id')!r} is not DEC-YYYYMMDD-NNN")
            continue
        if path.stem != rid:
            errors.append(f"{path.name}: filename does not match id {rid}")
        if rid in decisions:
            errors.append(f"{path.name}: duplicate id {rid}")
            continue

        # RULE B — the payload seal, checked before anything subtler so the
        # error a hand-editor sees names the actual offence.
        try:
            if meta.get("integrity") != payload_hash(meta, body):
                errors.append(f"{rid}: integrity hash does not match the content — the record "
                              "was edited outside the tool (DEC-20260806-012); recreate it "
                              "with record_decision.py")
            if canonical_record(meta, body) != raw:
                errors.append(f"{rid}: not canonical — records are tool-written "
                              "(DEC-20260806-012); recreate it with record_decision.py")
        except ValueError as err:
            errors.append(f"{rid}: {err}")

        # ONE report per missing field — the old loop reported a single
        # omission up to three ways (reviewed 07-Aug). An absent field is
        # reported absent and then skipped by the typed checks below.
        missing = {field for field in REQUIRED if not meta.get(field)}
        for field in sorted(missing):
            errors.append(f"{rid}: missing required field {field!r}")

        if "confirmed_by" not in missing and meta.get("confirmed_by") != "owner":
            errors.append(f"{rid}: confirmed_by must be owner — only the owner confirms decisions")
        if "status" not in missing and meta.get("status") not in ("current", "superseded"):
            errors.append(f"{rid}: status must be current or superseded, got {meta.get('status')!r}")
        if "scope" not in missing:
            scope = meta.get("scope")
            if not isinstance(scope, list) or not scope or not all(
                    isinstance(x, str) and x.strip() for x in scope):
                errors.append(f"{rid}: scope must be a non-empty array of non-empty strings")
        if "source" not in missing:
            src = meta.get("source")
            if not isinstance(src, dict) or not isinstance(src.get("reference"), str) \
                    or not src.get("reference", "").strip() or not isinstance(src.get("type"), str):
                # source.reference as null/number was an AttributeError
                # traceback (reviewed 07-Aug); wrong type is a named error.
                errors.append(f"{rid}: source must be a map with string type and a non-empty "
                              "string reference — memory is not evidence")
        if "supersedes" in meta and (not isinstance(meta["supersedes"], list) or not all(
                isinstance(x, str) for x in meta["supersedes"])):
            errors.append(f"{rid}: supersedes must be an array of strings")
        if "superseded_by" in meta and not isinstance(meta["superseded_by"], str):
            # An array here was 'unhashable type: list' mid-run, discarding
            # every already-collected error (reviewed 07-Aug).
            errors.append(f"{rid}: superseded_by must be a single id string")
            meta = {**meta, "superseded_by": None}
        if "confirmed_at" not in missing:
            confirmed_at = str(meta.get("confirmed_at"))
            problem = validate_date(confirmed_at)
            if problem:
                errors.append(f"{rid}: confirmed_at {problem}")
            elif id_match.group(1) != confirmed_at.replace("-", ""):
                # The id embeds a date next_id derives FROM confirmed_at; a
                # disagreement means one of them was edited — the same
                # smuggle class as an impossible date, surviving through the
                # id (reviewed 07-Aug).
                errors.append(f"{rid}: id date and confirmed_at {confirmed_at} disagree")
        if not body.strip():
            errors.append(f"{rid}: empty decision statement")
        if meta.get("status") == "current" and meta.get("superseded_by"):
            errors.append(f"{rid}: current but carries superseded_by — contradiction")
        if meta.get("status") == "superseded" and not meta.get("superseded_by"):
            errors.append(f"{rid}: superseded but names no superseding record")

        decisions[rid] = meta

    for rid, meta in decisions.items():
        for old in (meta.get("supersedes") or []):
            if not isinstance(old, str):
                continue  # already reported as a type error above
            if old == rid:
                errors.append(f"{rid}: supersedes itself")
            elif old not in decisions:
                errors.append(f"{rid}: supersedes {old}, which does not exist")
            elif decisions[old].get("status") != "superseded":
                errors.append(f"{rid}: supersedes {old}, but {old} is still marked current")
            elif decisions[old].get("superseded_by") != rid:
                errors.append(f"{rid}: supersedes {old}, but {old}.superseded_by is "
                              f"{decisions[old].get('superseded_by')!r}")
        by = meta.get("superseded_by")
        if isinstance(by, str) and by and (
                by not in decisions or rid not in (decisions[by].get("supersedes") or [])):
            errors.append(f"{rid}: superseded_by {by}, but {by} does not list it in supersedes")

    # EVERY CHAIN MUST END AT A CURRENT RECORD. The pairwise checks above all
    # pass for a self-loop or an A↔B cycle — hand-crafted corruption the tool
    # can never produce, which validated as sound and rendered a view with
    # zero current decisions in the chain (reviewed 07-Aug).
    for rid, meta in decisions.items():
        if meta.get("status") != "superseded":
            continue
        seen, cursor = {rid}, meta.get("superseded_by")
        while isinstance(cursor, str) and cursor in decisions:
            if cursor in seen:
                errors.append(f"{rid}: circular supersession chain through {cursor}")
                break
            seen.add(cursor)
            nxt = decisions[cursor]
            if nxt.get("status") == "current":
                break
            cursor = nxt.get("superseded_by")
        else:
            errors.append(f"{rid}: supersession chain never reaches a current record")

    return decisions


def validate_manifest(root: Path, errors: list[str]) -> None:
    if (root / "sources" / "manifest.yaml").exists():
        errors.append("legacy sources/manifest.yaml still exists beside manifest.json — "
                      "two truths; delete the yaml (migrated 06-Aug)")
    manifest_path = root / "sources" / "manifest.json"
    if not manifest_path.exists():
        errors.append("sources/manifest.json is missing")
        return
    try:
        meta = load_manifest(root)
    except (ValueError, OSError) as err:
        errors.append(str(err))
        return

    if canonical_manifest(meta) != read_exact(manifest_path):
        errors.append("manifest.json is not canonical — re-serialize it "
                      "(sorted keys, pinned JSON parameters, LF-only)")

    entries = {name: entry for name, entry in meta.items() if not name.startswith("_")}
    # RULE A: a registry with no sources registers nothing.
    if not entries:
        errors.append("manifest.json declares no sources — an empty registry is not sound")

    repo_root = root.parent.parent
    for name, entry in entries.items():
        if not isinstance(entry, dict):
            errors.append(f"manifest {name}: entry must be an object")
            continue
        status = entry.get("status")
        rel = entry.get("path")
        if not isinstance(rel, str) or not rel.strip():
            # A non-string path reached filesystem calls as a traceback
            # (reviewed 07-Aug).
            errors.append(f"manifest {name}: path must be a non-empty string")
            continue
        if status == "present":
            if rel.startswith(("http", "agent:", "~")):
                errors.append(f"manifest {name}: status present needs a repo-relative path — "
                              "an endpoint or home-dir source cannot be 'present'")
                continue
            target = repo_root / rel
            if not target.is_file():
                # is_file, not exists: a DIRECTORY at the path was an
                # IsADirectoryError traceback out of sha256_of (reviewed 07-Aug).
                errors.append(f"manifest {name}: status present but {rel} is not a file")
            elif not entry.get("sha256"):
                errors.append(f"manifest {name}: present without a sha256 pin — "
                              "the integrity the manifest header promises")
            else:
                try:
                    if sha256_of(target) != entry["sha256"]:
                        errors.append(f"manifest {name}: sha256 mismatch for {rel} — "
                                      "the source changed; re-verify and update the manifest")
                except OSError as err:
                    errors.append(f"manifest {name}: cannot hash {rel} — {err}")
        elif status in ("missing", "external"):
            # RULE A for evidence-at-risk: an external source with no notes is
            # exactly the silent-loss mode that already destroyed
            # Transactions.xml (reviewed 07-Aug); missing already required
            # notes, external now does too.
            notes = entry.get("notes")
            if not isinstance(notes, str) or not notes.strip():
                errors.append(f"manifest {name}: status {status} must say in notes what it is "
                              "and what is needed")
        elif status != "endpoint":
            errors.append(f"manifest {name}: status must be present/missing/external/endpoint")


def prose_files(root: Path, errors: list[str]) -> list[Path]:
    """The prose to scan for DEC-/FC- references.

    RULE A on the core three: PENDING-OWNER-QUESTIONS, SOURCE-PRIORITY and
    the constitution are load-bearing; each missing is an error, not a
    silent skip. Repo-level prose comes from `git ls-files '*.md'` minus the
    frozen archive — the hardcoded allowlist meant every NEW prose file
    silently escaped the check (reviewed 07-Aug). Where git is unavailable
    (isolated fixture roots in tests), the sweep falls back to the knowledge
    root alone, which the fixtures fully contain.
    """
    core = {
        "PENDING-OWNER-QUESTIONS.md": root / "PENDING-OWNER-QUESTIONS.md",
        "SOURCE-PRIORITY.md": root / "SOURCE-PRIORITY.md",
        "FACTORY-CONSTITUTION.md": root / "FACTORY-CONSTITUTION.md",
    }
    out: list[Path] = []
    for name, path in core.items():
        if path.exists():
            out.append(path)
        else:
            errors.append(f"{name} is missing — a core knowledge file cannot be silently skipped")

    if (root / "decisions").is_dir():
        out.extend(sorted((root / "decisions").glob("*.md")))
    out.append(root / "sources" / "manifest.json")

    repo_root = root.parent.parent
    try:
        listed = subprocess.run(
            ["git", "-C", str(repo_root), "ls-files", "*.md"],
            capture_output=True, text=True, check=True, timeout=30,
        ).stdout.splitlines()
        out.extend(repo_root / line for line in listed
                   if line and not line.startswith("docs/archive/")
                   and not line.startswith("docs/factory/"))
    except (subprocess.SubprocessError, OSError):
        # SELF-SWEEP (07-Aug): silence here is only honest when there is no
        # repo. If a .git exists and the sweep still failed, repo-level prose
        # would silently escape checking — that is Rule A territory, not a
        # fallback.
        if (repo_root / ".git").exists():
            errors.append("git ls-files failed in a git repo — repo-level prose "
                          "could not be swept for DEC-/FC- references")

    return [p for p in out if p.exists()]


def validate_prose_references(root: Path, decisions: dict[str, dict], errors: list[str]) -> None:
    constitution = root / "FACTORY-CONSTITUTION.md"
    known_fc: set[str] = set()
    if constitution.exists():
        known_fc = set(re.findall(r"## (FC-\d{2})", read_exact(constitution)))
        # RULE A: a constitution whose headings were reformatted away would
        # have made every FC citation vacuously pass (the old `if known_fc`
        # guard — reviewed 07-Aug). No headings is an error, and the guard is
        # gone: with the file present, every FC reference is checked.
        if not known_fc:
            errors.append("FACTORY-CONSTITUTION.md has no '## FC-NN' headings — "
                          "every FC reference would go unchecked")

    known_dec = set(decisions)
    for path in prose_files(root, errors):
        try:
            text = read_exact(path)
        except OSError as err:
            errors.append(f"{path.name}: unreadable — {err}")
            continue
        for rid in set(re.findall(r"DEC-\d{8}-\d{3}", text)):
            if rid not in known_dec:
                errors.append(f"{path.name}: references {rid}, which does not exist in decisions/")
        # Word-anchored: "RFC-4180" is not a reference to FC-41.
        for fc in set(re.findall(r"\bFC-\d{2}", text)):
            if fc not in known_fc:
                errors.append(f"{path.name}: references {fc}, which is not a heading in "
                              "FACTORY-CONSTITUTION.md")


def main() -> int:
    root = knowledge_root()
    errors: list[str] = []

    decisions = validate_decisions(root, errors)
    validate_manifest(root, errors)
    validate_prose_references(root, decisions, errors)

    gen = subprocess.run(
        [sys.executable, str(Path(__file__).parent / "generate_current.py"), "--check"],
        capture_output=True, text=True,
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
