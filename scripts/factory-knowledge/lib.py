"""Shared parsing for the factory knowledge system.

DELIBERATELY CONSTRAINED FORMAT, stdlib only. Decision records and the source
manifest use a small subset of YAML that this module parses by hand:

    key: value
    key:
      - list item
    parent:
      child: value

One nesting level, no anchors, no multi-line scalars, no quoting semantics
beyond stripping a single pair of surrounding quotes. The constraint is the
point: these files are read by deterministic scripts that must behave the same
on every machine with a bare python3 — no PyYAML, no pip, no version drift.

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


def parse_front_matter(text: str, *, path: str = "?") -> tuple[dict, str]:
    """Return (frontmatter dict, body). Raises ValueError on malformed input —
    a clean, named error, never a traceback into half-parsed state."""
    lines = text.split("\n")
    if not lines or lines[0].strip() != "---":
        raise ValueError(f"{path}: no frontmatter (file must start with ---)")

    meta: dict = {}
    current_key: str | None = None  # a key holding a list
    current_map: str | None = None  # a key holding a one-level map
    end = None

    for i, raw in enumerate(lines[1:], start=1):
        if raw.strip() == "---":
            end = i
            break
        if not raw.strip() or raw.strip().startswith("#"):
            continue
        indent = len(raw) - len(raw.lstrip(" "))
        line = raw.strip()

        if indent == 0:
            current_key = current_map = None
            if ":" not in line:
                raise ValueError(f"{path}: unparseable line {i + 1}: {line!r}")
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
                    raise ValueError(f"{path}: list item with no key, line {i + 1}")
                if not isinstance(meta.get(current_key), list):
                    meta[current_key] = []
                meta[current_key].append(_strip(line[2:]))
            else:
                if current_map is None or ":" not in line:
                    raise ValueError(f"{path}: unparseable nested line {i + 1}: {line!r}")
                if not isinstance(meta.get(current_map), dict):
                    meta[current_map] = {}
                child, _, value = line.partition(":")
                meta[current_map][child.strip()] = _strip(value)

    if end is None:
        raise ValueError(f"{path}: frontmatter never closed with ---")

    body = "\n".join(lines[end + 1:]).strip()
    return meta, body


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
