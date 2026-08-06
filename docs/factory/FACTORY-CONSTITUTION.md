# Factory constitution — durable boundaries every session must know

Only owner-confirmed physical and accounting boundaries live here — the facts
that are true regardless of which feature is being built this week. Temporary
status, roadmap items and speculative ideas do not belong in this file.
Point-in-time choices live in `decisions/`; this file is for the ground the
choices stand on. Changing an entry requires owner confirmation and a
superseding note — never a silent edit.

---

## FC-01 · One common resin input; a bag belongs to no machine and no batch

All machines draw from one common resin loading point. A resin bag must never
be represented as physically assigned to a machine or a batch, and scanning or
loading a bag is a **pour record, not Tally consumption**. Batch consumption
is *calculated*; the system must not claim physical bag-to-machine or
bag-to-batch provenance.

- **Source:** owner rulings 01–06 Aug 2026; implemented and evidenced in
  PR #93 (common resin input, weighted-average costing), PR #104 (a bag scan
  records a pour, not a stock movement), PR #105 (the intended-batch field
  withdrawn: "a bag belongs to no run"); reaffirmed verbatim in the owner's
  06 Aug architecture brief.
- **Confirmed:** 2026-08-03, reaffirmed 2026-08-06 · **Status:** active
- **Affects:** production entry, day bin, traceability, costing

## FC-02 · Scrap and lumps are real stock, booked inward

Rejected bottles and lumps are not discarded from the books: they are produced
`PET Scrap` (per colour) and post as an inward line on the stock journal.

- **Source:** 38 real Stock Journals (Transactions.xml, read 05 Aug 2026) —
  31 of 38 book Pet Scrap inward at ₹17–32/kg; PR #110 / commit 824def3.
- **Confirmed:** 2026-08-05 · **Status:** active
- **Affects:** production completion, tally-sync, costing

## FC-03 · Tally counts packing tape in Nos (rolls); metres never post as Nos

The factory doses tape in metres per box, but their Tally item is counted in
Nos. Until the factory states how many metres one Tally unit holds, a tape
figure is display-only and must not reach a voucher — 229 m filed as 229 Nos
is a different number about a different thing (this happened once, live).

- **Source:** Stock Journals (Packing Tape - Transparent, 720 Nos @ ₹37.53);
  `BatchVoucherShapeTest` (withheld-tape contract).
- **Confirmed:** 2026-08-05 · **Status:** active — the metres-per-unit answer
  is an open item in PENDING-OWNER-QUESTIONS.
- **Affects:** packing consumption, tally-sync

## FC-04 · The shape of a production Stock Journal

Produced goods and scrap go IN; resin, masterbatch and packing materials go
OUT. Resin and masterbatch issue from the factory day-bin location; packing
materials issue from the packing material store — never from the resin store.

- **Source:** the 38 Stock Journals; `TallySyncService` and its voucher-shape
  tests.
- **Confirmed:** 2026-08-05 · **Status:** active
- **Affects:** tally-sync, approval desk, stores

## FC-05 · Four eyes on production paperwork

The person who quality-checks a batch cannot be the person who completed it,
and the accountant who approves cannot be the plant manager who signed before
them. One person never carries a batch alone from floor to books.

- **Source:** `backend/tests/Feature/FourEyesApprovalTest.php`,
  `BatchQualityStageTest.php`, `ApprovalChainTest.php`.
- **Confirmed:** 2026-07 (Vincent meeting rules, implemented and tested)
  · **Status:** active
- **Affects:** quality gate, approval desk

## FC-06 · Purchase rates and supplier details are Owner/Accounts only

Floor and sales logins never see what a material cost or who supplied it.
Sales sees derived money; never the bags behind it.

- **Source:** `SalesCostInsightTest::test_a_sales_login_sees_the_money_but_never_the_bags_behind_it`
  and the permission model it pins.
- **Confirmed:** 2026-08-01 guardrails · **Status:** active
- **Affects:** procurement, sales, costing, RBAC

## FC-07 · Clear bottles take no masterbatch

A colourless bottle consumes no colourant. No dose, no percentage, no
pre-selection — a masterbatch line on a clear run is a wrong voucher waiting
to happen.

- **Source:** `MasterbatchPercentageTest::test_clear_still_takes_no_masterbatch`;
  PR #129, PR #134.
- **Confirmed:** 2026-08-06 · **Status:** active
- **Affects:** production completion, tally-sync
