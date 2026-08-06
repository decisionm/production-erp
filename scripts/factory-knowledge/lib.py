"""Shared parse/serialize for the factory knowledge system. Stdlib only.

FORMAT (since the 06-Aug migration, DEC-20260806-012): a decision record is
one canonical JSON object, one blank line, then the plain-markdown statement:

    {
      "id": "DEC-20260806-004",
      ...
    }

    The statement in plain English.

WHY JSON, not YAML and not a hand parser: the first hand parser had four
reviewer-reproduced holes (tab bypass, fence truncation, scalar iteration,
string-surgery flips) in its first day. `json` is stdlib — zero dependencies
on any machine with python3, which matters while hosting is undecided
(TECHNICAL-DOCS §8) — and its serializer is byte-stable, which buys the real
prize: CANONICAL-FORM ENFORCEMENT. validate.py re-serializes what it parsed
and byte-compares against the file, so a record either is exactly what the
tool writes or fails loudly. Hand-edited records are unsupported by owner
decision (DEC-20260806-012); humans read CURRENT-DECISIONS.md (FC-08).

Nothing in this module decides whether a business decision is confirmed.
It parses files; humans and the owner decide facts.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import os
import re
from pathlib import Path

ID_RE = re.compile(r"^DEC-(\d{8})-(\d{3})$")

# THE CANONICAL FORM, pinned explicitly — a byte-comparison whose parameters
# are implicit will eventually fail spuriously across environments (owner
# condition, 06-Aug). Any change here invalidates every record on disk, so
# there is a golden-bytes test (T15) that fails the moment these drift.
JSON_KW = {"indent": 2, "ensure_ascii": False, "separators": (",", ": ")}
FIELD_ORDER = ["id", "status", "confirmed_by", "confirmed_at",
               "scope", "supersedes", "superseded_by", "source", "integrity"]
SOURCE_ORDER = ["type", "reference"]


def read_exact(path: Path) -> str:
    """Read WITHOUT newline translation. Python's default text mode folds
    \r\n to \n on read, which made the byte-canonical check CRLF-blind: a
    whole-file line-ending edit — Windows' native behaviour, and the factory
    PC is Windows — validated as sound (reviewed 07-Aug). Every validation
    read goes through here so the bytes judged are the bytes on disk."""
    with open(path, encoding="utf-8", newline="") as handle:
        return handle.read()


def write_exact(path: Path, content: str) -> None:
    """Write WITHOUT newline translation — on Windows the default translates
    \n to \r\n, emitting non-canonical records natively (reviewed 07-Aug)."""
    with open(path, "w", encoding="utf-8", newline="") as handle:
        handle.write(content)


def create_exclusive(path: Path, content: str) -> None:
    """Exclusive create — 'x' mode closes the check-then-write race two
    concurrent recorders could exploit to silently clobber each other
    (reviewed 07-Aug). Raises FileExistsError; callers name the refusal."""
    with open(path, "x", encoding="utf-8", newline="") as handle:
        handle.write(content)


def validate_date(value: str) -> str | None:
    """None when valid; the problem when not. ONE implementation — the shape
    check, the calendar check and the not-in-the-future check were starting
    to copy-paste between the recorder and the validator (reviewed 07-Aug),
    and a future date mints an id that pins itself above every real decision
    in the newest-first view until that date arrives."""
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", value):
        return f"{value!r} is not YYYY-MM-DD"
    try:
        parsed = datetime.date.fromisoformat(value)
    except ValueError:
        return f"{value!r} is not a real calendar date"
    if parsed > datetime.date.today():
        return f"{value!r} is in the future — a decision cannot be confirmed on a date that has not happened"
    return None


def knowledge_root() -> Path:
    """docs/factory, overridable for tests via FACTORY_KNOWLEDGE_ROOT."""
    env = os.environ.get("FACTORY_KNOWLEDGE_ROOT")
    if env:
        return Path(env)
    return Path(__file__).resolve().parent.parent.parent / "docs" / "factory"


def canonical_meta(meta: dict) -> str:
    """The one serialization of a record's metadata. Refuses unknown keys —
    a typo like "sttatus" must be an error, never silently carried."""
    unknown = set(meta) - set(FIELD_ORDER)
    if unknown:
        raise ValueError(f"unknown record field(s): {', '.join(sorted(unknown))}")

    ordered: dict = {}
    for key in FIELD_ORDER:
        if key not in meta or meta[key] is None:
            continue
        if key == "source":
            src = meta[key]
            if not isinstance(src, dict) or set(src) - set(SOURCE_ORDER):
                raise ValueError("source must be a map with exactly: type, reference")
            ordered[key] = {k: src[k] for k in SOURCE_ORDER if k in src}
        else:
            ordered[key] = meta[key]
    return json.dumps(ordered, **JSON_KW)


def payload_hash(meta: dict, statement: str) -> str:
    """sha256 over the WHOLE payload — every metadata field except the hash
    itself, plus the statement bytes — serialized canonically.

    RULE B (owner, 07-Aug): a guarantee must cover the thing that matters,
    not the wrapper. Canonical-form checking alone pinned the envelope: a
    hand edit that kept canonical shape — a reworded statement, a swapped
    scope — was byte-perfect and validated sound. The hash makes every field
    and the statement part of one sealed payload: any tool-less edit breaks
    it. (It is tamper-EVIDENT against hand edits, not cryptographic proof
    against a determined forger who re-runs the algorithm — the same honest
    bar the canonical form itself sets, now covering content.)"""
    body = {k: v for k, v in meta.items() if k != "integrity"}
    sealed = canonical_meta(body) + "\n\n" + statement.strip("\n") + "\n"
    return "sha256:" + hashlib.sha256(sealed.encode("utf-8")).hexdigest()


def canonical_record(meta: dict, statement: str) -> str:
    """Full canonical file bytes: metadata (integrity hash recomputed, never
    trusted from the caller), exactly one blank line, the statement, one
    trailing newline."""
    sealed = {k: v for k, v in meta.items() if k != "integrity"}
    sealed["integrity"] = payload_hash(sealed, statement)
    return canonical_meta(sealed) + "\n\n" + statement.strip("\n") + "\n"


def parse_record(text: str, *, path: str = "?") -> tuple[dict, str]:
    """(meta, statement) from a record file. Named errors, never a traceback.

    raw_decode returns the exact byte offset where the JSON object ends —
    there are no fences, so there is nothing a stray `---` can truncate."""
    if "\r" in text:
        raise ValueError(f"{path}: CRLF line endings — the canonical format is LF-only")
    try:
        meta, end = json.JSONDecoder().raw_decode(text)
    except json.JSONDecodeError as err:
        raise ValueError(f"{path}: not a JSON-headed record ({err})") from None
    if not isinstance(meta, dict):
        raise ValueError(f"{path}: record metadata must be a JSON object")
    if text[end:end + 2] != "\n\n":
        raise ValueError(f"{path}: exactly one blank line must separate metadata from statement")
    # The STATEMENT, not the frame: the canonical file ends with exactly one
    # newline, which belongs to the format. Stripping it here makes
    # parse→canonical_record a true inverse, and the roundtrip check still
    # catches any file whose framing deviates (extra blank lines, trailing
    # whitespace) because the rebuild then differs from the raw bytes.
    return meta, text[end + 2:].strip("\n")


def load_decisions(root: Path | None = None) -> list[dict]:
    """Every decision record, parsed. Each dict gains _path and _body."""
    root = root or knowledge_root()
    out = []
    decisions_dir = root / "decisions"
    if not decisions_dir.is_dir():
        return out
    for path in sorted(decisions_dir.glob("*.md")):
        meta, body = parse_record(read_exact(path), path=str(path))
        meta["_path"] = str(path)
        meta["_body"] = body
        out.append(meta)
    return out


def canonical_manifest(entries: dict) -> str:
    """The one serialization of the source manifest: sorted keys, same pinned
    JSON parameters. `_`-prefixed keys are documentation, not sources."""
    return json.dumps(entries, sort_keys=True, **JSON_KW) + "\n"


def load_manifest(root: Path | None = None) -> dict:
    root = root or knowledge_root()
    path = root / "sources" / "manifest.json"
    try:
        loaded = json.loads(read_exact(path))
    except json.JSONDecodeError as err:
        raise ValueError(f"manifest.json: invalid JSON ({err})") from None
    if not isinstance(loaded, dict):
        # A top-level array crashed .items() three calls later with a raw
        # traceback (reviewed 07-Aug) — wrong type is a named error here.
        raise ValueError("manifest.json: top level must be a JSON object")
    return loaded


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()
