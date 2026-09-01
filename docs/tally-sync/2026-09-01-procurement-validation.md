# Procurement ↔ Tally — re-validation against the real exports, 01-Sep-2026

Re-measured because the procurement workflow was walked end to end and the
Tally half of it rests entirely on `PO-VOUCHER-CONTRACT.md`, whose structure
was measured once, on 12-Aug-2026. A contract nobody re-reads is a contract
nobody knows is still true.

**SHAPES AND COUNTS ONLY.** Not one value from the exports appears here — no
rate, amount, vendor, GSTIN, item name, ledger name or voucher number
(FC-06, Q38). The exports stay outside the repo; they were read in place,
decoded from UTF-16LE, and nothing was copied in.

## What was read

| Manifest id / file | What it is |
|---|---|
| `tally-daybook-po-20260812` · `DayBook_1.xml` | live Day Book, part 1 |
| `tally-daybook-po-20260812-part2` · `DayBook_2.xml` | live Day Book, part 2 |
| `test2_purchase_order.xml` | Testing company, Purchase Order vouchers |
| `test2_purchase.xml` / `test_purchase_invoice.xml` | Testing company, Purchase (invoice) vouchers |

## 1. The Purchase Order voucher — every structural claim still holds

Measured over the two Day Book files, against `PO-VOUCHER-CONTRACT.md` §2:

| Claim in the contract | Re-measured | Agrees |
|---|---|---|
| 107 `Purchase Order` vouchers | 107 | ✅ |
| 2 cancelled (`ISCANCELLED=Yes`) | 2 | ✅ |
| 201 inventory lines (199 live + 1 each on the 2 cancelled) | 201 | ✅ |
| 232 batch allocations | 232 | ✅ |
| line `AMOUNT` negative 199/199 | 199 negative, 0 positive | ✅ |
| line `ISDEEMEDPOSITIVE` = Yes 199/199 | 199 | ✅ |
| batch allocation `AMOUNT` negative 232/232 | 232 | ✅ |
| accounting allocation `AMOUNT` negative 199/199 | 199 | ✅ |
| party `LEDGERENTRIES` `AMOUNT` positive 105/105 | 105 | ✅ |
| `PARTYGSTIN` / `PARTYNAME` / `PARTYLEDGERNAME` / `PLACEOFSUPPLY` present 105/107 | 105 each | ✅ |
| `BASICBASEPARTYNAME` 105/107 · `BASICDUEDATEOFPYMT` 92/107 | 105 · 92 | ✅ |
| `VOUCHERNUMBER` / `REFERENCE` / `EFFECTIVEDATE` / `ISINVOICE` / `USETRACKINGNUMBER` 107/107 | 107 each | ✅ |
| `NARRATION` 0/107 (the accountant types none) | 0 | ✅ |
| no `ISORDER`, no `ORDERTYPE` anywhere — order-ness is the TYPE | 0 of each | ✅ |

The sign rule is therefore unchanged and the builder still emits it verbatim:
goods and purchase ledger debit (negative), the vendor credit (positive), and
the voucher balances.

**The emit side:** `tally-sync-agent` — **224 tests, 0 failures**, including
the byte-for-byte `purchase-order.golden.xml` comparison. The cloud staging
half (`PurchaseOrderTallyStagingTest`) is green in the backend suite.

`purchase_orders_enabled` remains **OFF**. Nothing here changes that, and
nothing here was posted anywhere: every measurement is a read.

## 2. Direction — the ERP raises, Tally records

`ISINVOICE` separates the two documents cleanly, which is what makes an ORDER
voucher safe to post (DEC-20260812-002):

| Voucher set | `VOUCHERTYPENAME` | `ISINVOICE` |
|---|---|---|
| Purchase Order (7, Testing) | `Purchase Order` | `No` 7/7 |
| Purchase invoice (17, Testing) | `Purchase` | `Yes` 17/17 |

## 3. Receipt Notes — the decision is confirmed by absence

**Zero `Receipt Note` vouchers exist in any export held locally** — both live
Day Book files and all twenty Testing-company exports were searched by
`VOUCHERTYPENAME`. This is consistent with DEC-20260830-001 (the factory does
not use Tally Receipt Notes) and with `receipt_note.xml` in the 26-Aug batch
holding MONEY receipts rather than goods receipts.

`tally-sync.receipt_notes_enabled` stays OFF as a decided state. The ERP's
own goods receipt is unaffected and remains the factory's arrival record.

## 4. The purchase-invoice side — still an open question, and why

Of the 17 purchase invoices, **6 carry a line `ORDERNO` (a PO reference) and
11 carry none** — direct purchases without a purchase order are the majority
in these books, exactly as the 26-Aug reading found.

Two consequences, neither of them decided by this measurement:

- **Q64** (may material be received with no PO behind it) stays open. The
  ERP's goods receipt starts from an OPEN purchase order today.
- **Q68** (should ERP-recorded supplier bills post to Tally as Purchase
  Invoices) stays open. No enqueue path exists, and none was added.

Nothing in this document is a decision. It records that the structure the
builder was written against is still the structure the factory's own books
have, six weeks and one company later.
