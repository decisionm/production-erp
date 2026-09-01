# Production Stock Journal — validated against the factory's own Tally export

**Read 01-Sep-2026.** Structure only. No rates, no amounts, no supplier
identity (AGENTS.md, FC-06).

## The evidence

| | |
|---|---|
| Source | `~/Downloads/test_stock_journal_entry.xml` — UTF-16 LE, CRLF, 6.1 MB |
| Manifest entry | `tally-testing-xml-2026-08-26`, status **`external`** |
| Company | SWAASHPET POLYMERS PVT LTD |
| Vouchers | **34**, every one `VCHTYPE="Stock Journal"` |
| Inventory lines | **732** (397 IN, 335 OUT) |

**The source file is deliberately NOT committed.** Permission to commit it is
**PENDING Q13**, and an open question is not a fact. What is committed is one
voucher, rate-neutralised, at
`tally-sync-agent/tests/fixtures/tally-stock-journal-real.xml`.

### What was neutralised in the committed fixture, and nothing else

| Element | Why |
|---|---|
| `RATE`, `AMOUNT` | Purchase rates are Owner/Accounts only (FC-06) and never reach documentation (AGENTS.md). The export books resin and scrap at real per-kg rates. |
| `GUID`, `VCHKEY`, `REMOTEID` | Identify the live company file. |

Element names, nesting, direction flags, item names, quantities, units and
godown are the factory's unmodified data. A test asserts the fixture stays
rate-free, so a future refresh cannot quietly reintroduce them.

## What the real vouchers say

### 1. A Stock Journal carries `INVENTORYENTRIESIN/OUT.LIST`

Not `ALLINVENTORYENTRIES.LIST` — that is the Sales/Purchase shape, and it
appears **zero** times in all 34 vouchers.

### 2. Tag and `ISDEEMEDPOSITIVE` agree on every one of the 732 lines

| Tag | `ISDEEMEDPOSITIVE` | Count | Meaning |
|---|---|---|---|
| `INVENTORYENTRIESIN` | `Yes` | 397 / 397 | Destination — stock **increases** |
| `INVENTORYENTRIESOUT` | `No` | 335 / 335 | Source (Consumption) — stock **decreases** |

There is no counter-example. The agent's builder treats `ISDEEMEDPOSITIVE` as
load-bearing and the tag as corroborating; the export supports both readings,
so the tests assert both.

### 3. FC-04 holds exactly as written

**IN (produced):** bottles, and `Pet Scrap`.
**OUT (consumed):** `Relpet`, `PET Polyster Chips`, `Master Batch Amber`,
master boxes, trays, pouches, covers, packing tape.

No produced bottle appears on the OUT side; no resin appears on the IN side.

Most-seen items, by voucher count:

| IN | | OUT | |
|---|---|---|---|
| Pet Scrap | 33 | Relpet | 26 |
| L.500ML Kidney Clear Pet Bottle 28gms (Cover) | 31 | 100 Ml Master Box | 25 |
| B.200 Ml Round Pet Bottle Amber 18gms | 29 | Hm Polythene Bags - 30.5 x 49 x 200G | 25 |
| A.60 Ml Round Amber Pet Bottle 10gms | 28 | 60 Ml Master Box | 25 |

### 4. FC-02 confirmed — scrap is booked inward

`Pet Scrap` appears on the IN side of **33 of 34** vouchers and on the OUT
side of none. This is the same finding the 30-Jul `Transactions.xml` gave
(31 of 38); a second, independent export reproduces it.

### 5. FC-03 confirmed — tape is counted in Nos

`Packing Tape - Transparent` appears on 17 vouchers' OUT side, quantity
always suffixed `Nos.`. No metre-denominated tape line exists anywhere in the
export.

### 6. DEC-20260830-002 confirmed — one godown

Every `GODOWNNAME` in the fixture voucher, on both sides, is
`SWAASHPET POLYMERS PVT LTD`. Across the whole export no line books to a
second godown. The books carry one godown, as the decision records.

### 7. Three of the 34 are not production journals

| Voucher | Shape | What it looks like |
|---|---|---|
| 114 | OUT `PET Polyster Chips` 588.990 Kgs → IN `Pet Scrap` 588.990 Kgs | regrind / reclassification, equal quantities |
| 116 | OUT `PET Polyster Chips` 6000.000 Kgs → IN `Pet Scrap` 6000.000 Kgs | same |
| 115 | IN `Plastic Bags Used` 328.0000 Nos, **no OUT side at all** | a one-sided adjustment |

Voucher 115 matters beyond the count: a Stock Journal in this company's books
may legitimately carry **no consumption side**. Anything that assumes both
sides are populated — a reader, a reconcile, a validator — will meet this
voucher. It is also the only IN line in the export that is neither a bottle
nor `Pet Scrap`.

Anything counting "production vouchers" here should exclude all three. The
fixture deliberately uses voucher **104**, a real production journal.

> Not investigated: whether 115 is an error, an opening adjustment, or a
> deliberate practice. It is reported, not interpreted — the accountant's
> intent is not something to infer from XML.

## What we emit, held against it

`tally-sync-agent/src/tally/voucherBuilders/stockJournal.ts` was found already
correct. `tests/stockJournalRealXml.test.js` now pins it against the fixture:

- same elements (`VOUCHERTYPENAME`, `STOCKITEMNAME`, `ISDEEMEDPOSITIVE`,
  `ACTUALQTY`, `BILLEDQTY`, `BATCHALLOCATIONS.LIST`, `GODOWNNAME`, `BATCHNAME`);
- every item on the same side the real voucher put it, line for line;
- the same direction convention;
- `BATCHNAME` = `Primary Batch`, as Tally's own writes.

**One deliberate difference:** we emit no `RATE` and no `AMOUNT`. The ERP never
asserts a purchase rate into a voucher (FC-06); Tally costs the line from item
costs. The real export has these elements — that we omit them is a choice, and
the test pins it as one so it cannot be "fixed" by accident.

### Non-vacuity

Inverting the builder's IN/OUT mapping fails tests 10 and 11. The assertions
bite.

## Reproducing

The source is UTF-16; convert before parsing:

```bash
iconv -f UTF-16LE -t UTF-8 ~/Downloads/test_stock_journal_entry.xml > sj.xml
```

Then `cd tally-sync-agent && npm test`. The committed fixture needs no
conversion — it is UTF-8.
