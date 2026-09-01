"""Trim the factory's real Stock Journal export down to a committable fixture.

WHY THIS SCRIPT EXISTS RATHER THAN A HAND-EDITED FILE. The source export is a
6 MB UTF-16 file living in ~/Downloads — the same failure mode that lost
Transactions.xml (sources/manifest.json records it as `missing`, findings
preserved only in decision records). The fixture the suite reads has to be
reproducible from the original, so what was removed is a matter of record
rather than of somebody's editing session.

WHAT IS REMOVED, AND WHY:

  * RATE and AMOUNT, on every line. Those are purchase rates. FC-06 makes
    them Owner/Accounts only and AGENTS.md forbids putting them, or private
    Tally contents, in the repo. The SHAPE of the voucher is what the suite
    needs to check, and the shape survives their removal intact.
  * GUID, REMOTEID, VCHKEY, ENTEREDBY, ALTEREDBY — machine identity and
    people's login names, neither of which any assertion reads.
  * every voucher after the first two, and the ~90 GST/VAT/excise `No` flags
    Tally emits on each one, which are noise at this size.

WHAT IS KEPT EXACTLY AS THE FACTORY'S TALLY WROTE IT: the voucher type and
object view, the date, the destination godown, and every INVENTORYENTRIESIN /
INVENTORYENTRIESOUT line with its stock item name, ISDEEMEDPOSITIVE flag,
ACTUALQTY / BILLEDQTY strings and per-line godown. Those are the evidence.

Usage (the source is not in the repo and is not expected to be):
    python3 tests/scripts/build_stock_journal_fixture.py ~/Downloads/test_stock_journal_entry.xml
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

KEEP_VOUCHER_FIELDS = (
    "DATE",
    "VOUCHERTYPENAME",
    "VOUCHERNUMBER",
    "PERSISTEDVIEW",
    "VCHENTRYMODE",
    "DESTINATIONGODOWN",
    "EFFECTIVEDATE",
)

KEEP_LINE_FIELDS = ("STOCKITEMNAME", "ISDEEMEDPOSITIVE", "ISSCRAP", "ACTUALQTY", "BILLEDQTY")

VOUCHERS = 2


def field(block: str, tag: str) -> str | None:
    found = re.search(rf"<{tag}>(.*?)</{tag}>", block, re.S)
    return found.group(1) if found else None


def lines(voucher: str, tag: str) -> list[str]:
    out = []
    for block in re.findall(rf"<{tag}\.LIST>(.*?)</{tag}\.LIST>", voucher, re.S):
        kept = [f"      <{name}>{field(block, name)}</{name}>" for name in KEEP_LINE_FIELDS if field(block, name) is not None]
        godown = re.search(r"<GODOWNNAME>(.*?)</GODOWNNAME>", block, re.S)
        if godown:
            kept.append(f"      <GODOWNNAME>{godown.group(1)}</GODOWNNAME>")
        out.append(f"     <{tag}.LIST>\n" + "\n".join(kept) + f"\n     </{tag}.LIST>")
    return out


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__)
        return 2

    source = Path(sys.argv[1]).expanduser()
    text = source.read_text(encoding="utf-16")
    vouchers = re.findall(r"<VOUCHER[ >].*?</VOUCHER>", text, re.S)[:VOUCHERS]

    parts = [
        "<!--",
        "  TRIMMED FROM THE FACTORY'S OWN TALLY EXPORT — see",
        "  tests/scripts/build_stock_journal_fixture.py for what was removed and why.",
        "  Source: test_stock_journal_entry.xml, exported 24-Aug-2026 from the",
        "  'SWAASHPET POLYMERS PVT LTD Testing' company, 34 Stock Journal vouchers.",
        "  Rates and amounts are stripped (FC-06). The two vouchers below are the",
        "  first two of that export, otherwise unaltered.",
        "-->",
        "<ENVELOPE>",
        " <BODY>",
        "  <REQUESTDATA>",
    ]

    for voucher in vouchers:
        parts.append('   <VOUCHER VCHTYPE="Stock Journal" ACTION="Create" OBJVIEW="Consumption Voucher View">')
        for name in KEEP_VOUCHER_FIELDS:
            value = field(voucher, name)
            if value is not None:
                parts.append(f"    <{name}>{value}</{name}>")
        parts.extend(lines(voucher, "INVENTORYENTRIESIN"))
        parts.extend(lines(voucher, "INVENTORYENTRIESOUT"))
        parts.append("   </VOUCHER>")

    parts += ["  </REQUESTDATA>", " </BODY>", "</ENVELOPE>", ""]

    target = Path(__file__).resolve().parents[1] / "fixtures" / "tally" / "production-stock-journals.xml"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text("\n".join(parts), encoding="utf-8")
    print(f"wrote {target} ({len(vouchers)} vouchers)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
