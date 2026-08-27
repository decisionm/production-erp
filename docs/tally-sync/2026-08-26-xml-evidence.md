# Phase 1 — Tally XML Evidence Report

Source: XML exports in `~/Downloads`, exported 24–26 Aug 2026 from the standalone
**"SWAASHPET POLYMERS PVT LTD Testing"** Tally company (a copy of the factory's books).
All files are UTF-16 with occasional raw control bytes and invalid numeric character
references — any importer MUST sanitize before XML parsing (proven here; parsers that
don't will crash on 4 of these 9 files).

Per-file field inventories: `*.fields.txt` (every element path + occurrence count +
example). Representative vouchers: `*.sample.txt` (RATE/AMOUNT values masked — FC-06).
Party (vendor/customer) names appear only in local extracts, never in repo docs.

## File → content map (dedup findings)

| File | Actual content | Count |
|---|---|---|
| test_stock_master.xml | All Masters: STOCKITEM ×624 (all unique, each with GUID+ALTERID), STOCKGROUP ×19 unique (each exported twice), UNIT ×6 unique, GODOWN ×1 | canonical item master |
| stoock_journal.xml | **identical to test_stock_master.xml** (misnamed) | — |
| test_master.xml | Stock group masters only | 19 groups |
| test_stock_group.xml | Stock groups + 3 units | 22 groups |
| test_ledger_type.xml | **VOUCHERTYPE masters, not ledgers** (misnamed) | 27 types |
| test2_purchase_order.xml | Purchase Order vouchers, Aug-26 | 7 |
| test2_purchase.xml | Purchase (invoice) vouchers | 17 |
| test_purchase_invoice.xml | **identical voucher set to test2_purchase.xml** | 17 |
| receipt_note.xml | **Money-receipt vouchers (bank allocations), NOT goods receipt notes** | 20 |
| test2_stock_journal.xml / test_stock_journal_entry.xml | Stock Journal vouchers, Jul-26, identical sets | 34 |
| test_sales.xml / sales_voucher.xml | Sales (invoice) vouchers | 55 |
| test2_sales_order.xml | Sales Order vouchers | 34 |
| test_invidual_bills.xml | BILLFIXED outstanding-bill rows | 27 |
| test_statistics.xml / test_outstanding_bills.xml / test_group_bills.xml | report scaffolding, no masters/vouchers | — |

## Item master (product identity ground truth)

- 624 unique stock items; **every one has a unique GUID** (`{companyGUID}-{serial}`)
  and an ALTERID. GUID is the stable remote identifier for mapping.
- Group tree (19 groups): Finished Goods → {Amber ×123, Clear ×89, Green ×12,
  Liquor ×35, Milk White ×29, Orange ×8 Pet Bottle; Tablet Container ×29} + 35 direct;
  Caps & Closures ×132; Packing Material → {Carton Box ×15, Tray ×9} + 27 direct;
  Master Batch ×32; Raw Material ×11 → PET (empty); Scrap ×16; HDPE Bottles &
  Container ×10; BOPP TAPE, SHRINK ROLLS (both empty); **12 items with NO group**
  (pen drive, servo amplifier, mould release spray, 3D prototypes, "Stock", etc. —
  the Spare/Consumable/Unclassified tail).
- Units (6): Nos. (dp4) — 543 items; Kgs. (dp3) — 73; "Not Applicable" — 4; Pcs. — 2;
  compound "Ltr of 1000 Nos." — 1; rolls — 1.
- HSN present on 285/624 items only.
- Aliases (LANGUAGENAME) effectively unused (1 junk entry). PARTNO unused.
- **Pack-variant identity lives in the item NAME**: 24 items carry trailing
  "- NNN Nos" counts, and the same bottle appears as separate items per pack count
  (e.g. `B.100 Ml Round Pet Bottle Amber 12.9 Gms - 812 Nos` AND `- 840 Nos`).
  Tally distinguishes PACK, not just product — separate ERP identities required.
- Godown master: exactly ONE ("SWAASHPET POLYMERS PVT LTD"). Tally carries no
  location split; ERP RM Store / Production / FG Store locations are ERP-side only
  (DEC-20260817-001).
- Batch: every BATCHALLOCATIONS uses the placeholder "Primary Batch" — Tally-side
  batch tracking is OFF; lot identity must be ERP-side.

## Voucher shapes (identity + join keys)

Common header: DATE (YYYYMMDD), GUID, REMOTEID, VOUCHERNUMBER, VOUCHERTYPENAME,
PARTYLEDGERNAME/PARTYNAME, REFERENCE (+REFERENCEDATE). All quantities are dual-unit
strings (`<count> Nos. =  <weight> Kgs.`) — importer must parse both.

Field SHAPES only below. Actual voucher numbers, order references and dated
customer PO strings are private Tally contents and stay in the external
evidence store (AGENTS.md) — the placeholders show the format an importer has
to read, which is the whole of what this document is for.

- **Purchase Order** (7): line ALLINVENTORYENTRIES → STOCKITEMNAME, ACTUALQTY/BILLEDQTY,
  BATCHALLOCATIONS{ORDERNO=own voucher no, ORDERDUEDATE per line, GODOWNNAME}.
  Header BASICDUEDATEOFPYMT ("45 Days"), BASICORDERTERMS ("Door Delivery").
  ORDERLINESTATUS=Yes.
- **Purchase invoice** (17): same line shape; BATCHALLOCATIONS/ORDERNO carries the PO's
  voucher number when the purchase came from a PO and is ABSENT for
  direct purchases — most of these 17 have no PO reference. Per-line
  ACCOUNTINGALLOCATIONS → "Interstate Purchase Taxable"; GST in LEDGERENTRIES.
- **Sales Order** (34): VOUCHERNUMBER a plain integer string; line ORDERNO = own SO
  number, ORDERDUEDATE per line = promised delivery; header BASICORDERREF holds the
  CUSTOMER'S PO as free text in the shape `PO NO :<nnn> Dated <dd-mm-yy>` — the
  SO↔invoice join key the ERP already models as customer_po_reference.
- **Sales invoice** (55): VOUCHERNUMBER formatted `<n>/<fy>` (serial, slash, financial
  year) — NOT a plain integer like the SO's; dual-unit quantities;
  "Interstate Sales Taxable" allocations; party + GST + freight in ledger entries.
- **Stock Journal** (34, one per DAY of Jul-26): PERSISTEDVIEW="Consumption Voucher
  View", DESTINATIONGODOWN single; per voucher ~12 IN lines (finished bottle items)
  and ~6 OUT lines (Relpet resin, master boxes, trays, pouches) — matches the
  consolidated-daily-voucher ground truth; ISDEEMEDPOSITIVE drives column meaning.
- **Money Receipt** (20): bank allocations, UNIQUEREFERENCENUMBER, bill-wise
  AGST refs — receivables side; NOT a GRN.

## Gaps / ambiguities (owner-decision or missing-evidence items)

1. **No Goods Receipt Note (GRN) sample** — receipt_note.xml is money receipts.
   Whether the factory uses Tally Receipt Notes at all is UNVERIFIED; the ERP GRN
   flow must not assume a Tally GRN voucher exists.
2. **No LEDGER master export** (party masters, GSTIN, addresses) — vendor/customer
   Tally mapping can only be validated by name against voucher PARTYLEDGERNAME
   values until a Ledger master export is provided.
3. HSN missing on 339/624 items — compliance gap, owner/accountant to fill in Tally.
4. 12 ungrouped items + empty groups (BOPP TAPE, SHRINK ROLLS, PET) — classification
   conflicts to surface as warnings, not to silently fix.
5. Purchase invoices without POs are NORMAL in these books — the ERP procurement flow
   must allow receipt/invoice against no PO (or record an owner decision to forbid it).
6. Only ONE godown in Tally vs ERP's three-location model — location detail stays
   ERP-side; any Tally posting collapses to the single godown.
