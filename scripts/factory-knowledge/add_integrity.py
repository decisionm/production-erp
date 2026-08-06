#!/usr/bin/env python3
"""ONE-SHOT: seal the existing records with the integrity payload hash
(RULE B, owner 07-Aug). Statements and every semantic field pass through
byte-untouched — canonical_record recomputes only the hash. Refuses records
already sealed. Deleted in the commit after this runs, per the established
pattern: the sealing commit can always reproduce the sealing."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
from lib import canonical_record, knowledge_root, load_decisions, write_exact

sealed = skipped = 0
for d in load_decisions(knowledge_root()):
    meta = {k: v for k, v in d.items() if not k.startswith("_")}
    if meta.get("integrity"):
        print(f"  already sealed: {d['id']}")
        skipped += 1
        continue
    write_exact(Path(d["_path"]), canonical_record(meta, d["_body"]))
    print(f"  sealed: {d['id']}")
    sealed += 1
print(f"{sealed} sealed, {skipped} already sealed")
