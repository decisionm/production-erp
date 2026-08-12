# What the 12-Aug Tally export settles

The owner exported from the LIVE Tally (SWAASHPET POLYMERS PVT LTD **Testing**
company, 1-Apr-26 to 10-Aug-26) on 2026-08-12: the Day Book, the Purchase Order
pending register, a Receipts export, and the Statistics screen.

**These are FINDINGS, not decisions.** They are what the files say. What the
factory should do about them is the owner's, and the questions are named at the
end.

Every figure below was counted from the files, not taken on trust. The files are
registered in `sources/manifest.json` (keys beginning `tally-`) with sha256 pins.

> **The exports themselves are NOT in this repo, deliberately.** They carry
> purchase rates and private Tally contents, and AGENTS.md forbids putting those
> in documentation (FC-06). They are held durably outside the repo, hash-pinned,
> with `SHA256SUMS.txt` beside them. Committing them is a one-line status flip
> the day the owner says the repo is the right home — see the question at the
> end. Nothing in THIS document carries a rate.

---

## A · What the accountant actually does in Tally

From the Statistics screen, 1-Apr-26 to 10-Aug-26:

| Voucher type | Count |
|---|---|
| Payment | 925 |
| Receipt | 553 |
| Journal | 418 |
| **Purchase** | **351** |
| Purchase Order | 92 (1 cancelled) |
| Contra | 60 |
| Credit Note | 17 (2 cancelled) |
| Debit Note | 5 |
| **Total** | **3,811** (6 cancelled) |

**Purchase bills and payments live entirely in Tally today.** 351 purchase
vouchers and 925 payments, against an ERP that has neither.

## B · Bill-wise detail is ON — per-invoice ageing is achievable

The Receipts export carries **395 `BILLALLOCATIONS` blocks**:

- **176** with `BILLTYPE` **`Agst Ref`** — a receipt knocked off against a
  specific existing bill
- **28** with `BILLTYPE` **`New Ref`** — a receipt opening a new reference

against bill references of the form `473/26-27`, `510/26-27`, `2185/25-26`.

**This answers the "is bill-wise detail on?" half of Q30.** Per-invoice
outstanding and ageing are therefore achievable rather than speculative, so
Phase 2 of the finance pull is viable. *(Q30 lives on the unmerged PR #155
branch, not on main, so it cannot be marked resolved here — it closes on that
branch, citing `tally-receipts-20260812`.)*

## C · Tally is not a source for the staff list

Statistics reports **3 Employees** and **5 Employee Groups** for a factory that
runs three shifts across ten machines. Combined with the code finding that no
employee read path exists in the integration at all, this is settled: **the
staff list must come from the factory**, not from Tally.

## D · Masters scale, for sizing any read

**1,742 ledgers · 624 stock items · 83 groups · 19 stock groups · 27 voucher
types · 6 units · 1 currency.**

Relevant to the finance pull's chunking: the ledger count is ~2.7× the stock
item count the existing probe machinery was sized against.

---

## The four design changes this forces

### 1 · One "Purchase" ledger slot is wrong — there are FOUR

Counted across the Day Book export:

| Purchase ledger | Lines |
|---|---|
| Local Purchase Taxable @ 18% | 99 |
| Interstate Purchase Taxable | 58 |
| Local Purchase Taxable @ 5% | 30 |
| Interstate Purchase Taxable 5% | 12 |

with **CGST 55 / SGST 55** on local vouchers, **IGST 36** on interstate, and a
**Rounding Off** line on 52.

The Tally Settings screen offers a **single** Purchase mapping
(`TallyLedgerRole::Purchase`), which cannot express this. The shape it needs is
a mapping **per (place-of-supply × GST rate)**, not one slot:

```
purchase_ledger[ local | interstate ][ rate ]  ->  Tally ledger name
tax_ledger[ cgst | sgst | igst ]               ->  Tally ledger name
rounding_ledger                                ->  Tally ledger name
```

#### The local/interstate half is MEASURED, not assumed

Parsed from the 92 vouchers (the files are **UTF-16 LE** — that matters for any
reader built against them):

| purchase ledger | tax on the same voucher | party state |
|---|---|---|
| Local Purchase Taxable @ 18% (47) | CGST+SGST | Puducherry |
| Local Purchase Taxable @ 5% (8) | CGST+SGST | Puducherry |
| Interstate Purchase Taxable (31) | IGST | Tamil Nadu, Maharashtra, Rajasthan, Gujarat |
| Interstate Purchase Taxable 5% (4) | IGST | Tamil Nadu |

**90 of 92 conform** to `Local ⇔ party state is Puducherry ⇔ CGST+SGST` and
`Interstate ⇔ any other state ⇔ IGST`. So the company's own state is
**Puducherry**, and local-versus-interstate is decided by the party's state
against it. That half needs no guess.

The two that do not conform:

- **Voucher 57, Auro Packaging** — ledger `Local Purchase Taxable @ 5%` on a
  **Tamil Nadu** party carrying **IGST**. The tax is right for an interstate
  purchase; the ledger is not. A mis-keyed ledger in Tally. Worth knowing
  because **an ERP enforcing the rule would refuse to reproduce this voucher** —
  correctly, but the owner should hear it now rather than discover it later.
- **Voucher 72** — no purchase ledger, no party, no tax. Consistent with the
  Statistics screen's "92 (1 cancelled)".

#### The RATE half is NOT determined by the item — the obvious rule is wrong

This is precisely why it was not guessed. Measured:

- **9 of 43 items appear under BOTH 5% and 18%** — 200 Ml Brute Tray,
  100/170/200 Ml Master Box, 60 Ml Tray, 100 Ml Tray among them. The same item
  is bought at both rates, so the rate is not a property of the item.
- **3 of 20 vendors use both rates**, so it is not the vendor either.
- **5% appears only in 2026-04, -05 and -06. July and August are 18% only.**

The time pattern is the strong one and is consistent with a GST rate change
effective around July 2026 — **but that is an inference, recorded as one.** It
could equally be a reclassification, or earlier mis-keying since corrected. Only
the accountant can say, and Q39 asks.

**What follows for the build either way: the ERP must NOT hold one GST rate per
item.** Whatever the cause, the rate that applied in April is not the rate that
applies now, so a per-item constant would silently misprice either history or
the present.

### 2 · Dual units are real

**28 of 382 lines** carry a second unit, e.g. a quantity expressed both by
weight and by piece count — trays and covers are bought by weight and counted in
pieces.

The ERP's PO line has **one** quantity and shows **no unit at all**. Holding a
dual unit honestly would need: a second quantity + unit on the line, a recorded
conversion factor per item, and a decision about which side is authoritative for
receipt matching and for stock. That is a schema change and a real design
question, not a display tweak.

**In the meantime — and this is already biting, the owner hit it on the live
form today — the single unit must be VISIBLE everywhere a quantity is entered
against an item.** The data exists; the GRN code already branches on
`isMassUom`. That work is first in the queue after the GRN weight fix.

### 3 · Purchase orders DO belong in Tally — the sync is worth building

92 purchase orders, entered by `admin`, carrying full GST, payment terms
(45 days on 51, 60 days on 17, plus 30 and 15), Door Delivery, Party Vehicle,
and — importantly — an **`ORDERNO` on each line that is DISTINCT from the
voucher number**.

So DEC-20260812-002 (POs raised in the ERP, sent to Tally) is worth building,
and the payload must carry those fields. A real resin line for the builder to
model on, from the export: vendor **Shivmith Polymer Pvt Ltd**, item
**"Relpet"**, **15,000 Kgs**, IGST applied, **ORDERNO 146**. *(Rates and totals
are in the pinned source, deliberately not reproduced here — FC-06.)*

Note the per-line `ORDERNO` directly informs Q35(c): whose PO number is
authoritative. Tally already carries a line-level order number that is not the
voucher number.

### 4 · The ERP has no purchase invoice and Tally has 351

That is the entire payable side, and it is the only document a three-way match
can compare a GRN against. **Design now, do not build:**

- a supplier bill captured in the ERP against the GRN
- matched PO ↔ GRN ↔ bill, with the variances named
- posted to Tally as a **Purchase** voucher on the correct ledger from change 1

This is what the earlier audit classified as **NOTHING** — no model, no screen,
no Tally path — and it is now measured at 351 vouchers of real work.

---

## Questions this raises for the owner

1. **May the raw Tally exports be committed to the repo?** They carry purchase
   rates and private Tally contents, which AGENTS.md forbids in documentation
   (FC-06). They are currently held outside the repo, hash-pinned and durably
   copied, which protects them from the silent loss that destroyed the 30-Jul
   `Transactions.xml` — but "outside the repo" is the same status the dose-sheet
   photos have been sitting in since 06-Aug. The repo is private; that may make
   it fine. It is the owner's call, not an agent's.
2. **How is the purchase ledger chosen per line** — is it the vendor's state
   versus the company's for local/interstate, and the item's GST rate for the
   rate? Confirm before any mapping is built (change 1).
3. **Which unit is authoritative on a dual-unit line** — the weight or the piece
   count — for receipt matching, and for what reaches stock? (change 2)
4. **Should the ERP's purchase voucher carry the same per-line `ORDERNO`** Tally
   uses, or its own? (change 3, and Q35(c))
