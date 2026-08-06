"""Shared parsing for the factory knowledge system.

DELIBERATELY CONSTRAINED FORMAT, stdlib only. Decision records and the source
manifest use a small subset of YAML that this module parses by hand:

    key: value
    key:
      - list item
    parent:
      child: value

One nesting level, SPACE indentation only (a tab raises — reviewed 06 Aug: a
tab-indented child parsed as top-level and silently skipped validation), no
anchors, no multi-line scalars, no quoting semantics beyond stripping one pair
of surrounding quotes. The constraint is the point: these files are read by
deterministic scripts that must behave the same on every machine with a bare
python3 — no PyYAML, no pip, no version drift.

Nothing in this module decides whether a business decision is confirmed.
It parses files; humans and the owner decide facts.
"""

from __future__ import annotations

import hashlib
import os
import re
from pathlib import Path

ID_RE = re.compile(r"^DEC-(\d{8})-(\d{3})$")


def knowledge_root() -> Path:
    """docs/factory, overridable for tests via FACTORY_KNOWLEDGE_ROOT."""
    env = os.environ.get("FACTORY_KNOWLEDGE_ROOT")
    if env:
        return Path(env)
    return Path(__file__).resolve().parent.parent.parent / "docs" / "factory"


def _strip(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        value = value[1:-1]
    return value


def _parse_block(lines: list[str], *, path: str, offset: int = 0) -> dict:
    """Parse a block of constrained-YAML lines. Raises ValueError with the
    file and line named — a loud, specific error, never a silent skip."""
    meta: dict = {}
    current_key: str | None = None  # a key holding a list
    current_map: str | None = None  # a key holding a one-level map

    for i, raw in enumerate(lines, start=1 + offset):
        if not raw.strip() or raw.strip().startswith("#"):
            continue
        leading = raw[: len(raw) - len(raw.lstrip())]
        if "\t" in leading:
            raise ValueError(f"{path}: tab indentation on line {i} — use spaces")
        indent = len(leading)
        line = raw.strip()

        if indent == 0:
            current_key = current_map = None
            if ":" not in line:
                raise ValueError(f"{path}: unparseable line {i}: {line!r}")
            key, _, value = line.partition(":")
            key = key.strip()
            value = _strip(value)
            if value == "":
                # Could be a list or a map — decided by the next indented line.
                meta[key] = None
                current_key = current_map = key
            else:
                meta[key] = value
        else:
            if line.startswith("- "):
                if current_key is None:
                    raise ValueError(f"{path}: list item with no key, line {i}")
                if not isinstance(meta.get(current_key), list):
                    if isinstance(meta.get(current_key), dict):
                        raise ValueError(
                            f"{path}: line {i} mixes list items into the map under {current_key!r}"
                        )
                    meta[current_key] = []
                meta[current_key].append(_strip(line[2:]))
            else:
                if current_map is None or ":" not in line:
                    raise ValueError(f"{path}: unparseable nested line {i}: {line!r}")
                existing = meta.get(current_map)
                if isinstance(existing, list):
                    raise ValueError(
                        f"{path}: line {i} mixes map keys into the list under {current_map!r}"
                    )
                if not isinstance(existing, dict):
                    meta[current_map] = {}
                child, _, value = line.partition(":")
                meta[current_map][child.strip()] = _strip(value)

    return meta


def parse_front_matter(text: str, *, path: str = "?") -> tuple[dict, str]:
    """Return (frontmatter dict, body). Raises ValueError on malformed input —
    a clean, named error, never a traceback into half-parsed state."""
    lines = text.split("\n")
    if not lines or lines[0].strip() != "---":
        raise ValueError(f"{path}: no frontmatter (file must start with ---)")

    end = None
    for i, raw in enumerate(lines[1:], start=1):
        if raw.strip() == "---":
            end = i
            break
    if end is None:
        raise ValueError(f"{path}: frontmatter never closed with ---")

    meta = _parse_block(lines[1:end], path=path, offset=1)
    body = "\n".join(lines[end + 1:]).strip()
    return meta, body


def parse_manifest_text(text: str, *, path: str = "manifest.yaml") -> dict:
    """Parse the whole manifest body directly — NOT by wrapping it in
    frontmatter fences. The fence trick meant a literal `---` line inside the
    manifest silently ended parsing and everything below it went unvalidated
    (reviewed 06 Aug). Here a `---` line has no colon and fails loudly."""
    return _parse_block(text.split("\n"), path=path)


def as_list(value) -> list:
    """A scalar written inline (`supersedes: DEC-...`) is one-item data, not a
    string to iterate character by character (reviewed 06 Aug)."""
    if value is None:
        return []
    if isinstance(value, list):
        return value
    return [value]


def load_decisions(root: Path | None = None) -> list[dict]:
    """Every decision record, parsed. Each dict gains _path and _body."""
    root = root or knowledge_root()
    out = []
    decisions_dir = root / "decisions"
    if not decisions_dir.is_dir():
        return out
    for path in sorted(decisions_dir.glob("*.md")):
        meta, body = parse_front_matter(path.read_text(encoding="utf-8"), path=str(path))
        meta["_path"] = str(path)
        meta["_body"] = body
        out.append(meta)
    return out


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()
