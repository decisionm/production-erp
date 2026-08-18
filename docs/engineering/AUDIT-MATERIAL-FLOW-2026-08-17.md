# Store → Production material flow — audit before refactor (17-Aug-2026)

Read-only. Commissioned by the lead's business-rule correction of 17-Aug: the target
workflow becomes

    Store Stock → Production Material Request → Store Issue → Scan/Handover
      → Issued-to-Production → Actual Consumption → Return unused

and the Day Bin leaves the TARGET workflow — *"do not blindly delete data structures;
determine what is currently relied upon, then refactor the operational workflow
safely."* This document is that determination. Nothing has been refactored yet.

## 1 · Where stock actually leaves the store today

**Only at batch completion, with purpose `Consumption`.** `ShiftProductionEntryService::
completeBatch` resolves the source warehouse per line, writes the
`shift_material_consumptions` row, then calls `recordIssue(..., purpose: Consumption)`.
Finished goods come back in with purpose `Output`.

**The bag scan at the common input writes no stock movement at all** — by design, and
pinned by `CommonInputLoadIsNotStockTest`. `FactoryDayBinService::loadBag` says so in
its own words: *"NO STOCK MOVEMENT. A scan is an OPERATIONAL record, not an accounting
one."* It decrements the bag, writes one day-bin Load row (with `work_center_id` NULL)
and folds the cost into the resin pool.

`StockMovementPurpose` today: Opening, Receipt, Consumption, Output, Dispatch,
Adjustment, Reconcile, Unknown. **There is no issue-to-production and no
return-from-production.**

## 2 · The three states the lead requires — what it takes

**Nothing today represents "issued to production but not yet consumed."** The day bin
is a kilogram ledger, not a stock state.

The important finding: **a new purpose pair alone would not do it.** Purposes are
metadata; balances only move when a movement names a warehouse, and
`inventory:check-ledger` signs by movement TYPE, not purpose — so a purpose-only change
would be invisible to the invariant and would create no second state.

**The smaller real change is a LOCATION:** one "Issued to Production" warehouse row,
with `store → production` transfer at issue, `Consumption` issue from production at
completion, and `production → store` transfer on return. The ledger invariant still
holds unchanged, because transfers are already a signed out/in pair the command
understands.

Touched by that change: the completion issue's source warehouse,
`FactoryWarehouseResolver::consumptionSourceOrFail`, the common-resin estimate's
numerator, the Complete Batch and Day Bin screens — **and the Tally godown named per
consumption line**, which would change the godown on NEW vouchers unless aliased.
Posted vouchers are untouched and must stay so.

## 3 · What the Day Bin is doing, and what breaks if it stops

**One writer** (`DayBinLedgerService::record`) with four callers: the live floor scan
(`day-bin/load-bag`), the machine-stamped `day-bin/load` (retired by DEC-20260807-006
but STILL ROUTED, with no page calling it), `day-bin/return` and `day-bin/count` (both
routed, no UI caller, and DEC-20260807-007 says no count will ever be taken), and
closing-count writes on every completion and handover.

**Readers that would break** if it stopped being written: the Complete Batch
consumption prefill, the handover opening basis, the Start Batch availability panel
(live), the common-resin estimate and its over-load acknowledgement gate, the
traceability report and its export, the PO trace drawer, the internal carton trace
(DEC-20260810-001), and the cancellation blocker.

**Tally: nothing.** Consumption lines are built exclusively from the entry's
`materialConsumptions`; no Tally payload derives from `day_bin_movements`.

**Therefore: retire the CONCEPT, keep the TABLE.** Historical rows stay (DEC-20260807-006
requires machine-stamped rows be preserved untouched) and the historical readers keep
reading them. The safe first step is closing the three already-dead doors, which have
no UI caller at all.

## 4 · What already exists to reuse

The bag scan endpoint (records bag + lot identity, kg, who and when — **missing only a
request id and a "received by"**, both additive); incoming QC on arrival bags, which
already refuses a bag on hold; lot identity and cost versions; a ready-made FIFO pick
list for store picking; and warehouses as locations with a parent hierarchy. The
Procurement `PurchaseRequisition` is a request to BUY and is unrelated — a useful
template for the header/lines/status shape, nothing more.

## 5 · The FC-01 collision, precisely

**Compatible, and being built:** custody transfer store→production; issue-level
traceability; the three states (which move the accounting event further from the scan
— FC-01's own direction of travel); return of unused material.

**Contradicts, and only for the common-input resin:**
- *A resin request naming a machine or area.* DEC-20260807-006 records the physical
  fact: one loading point, crane-fed, piped to all ten machines. For packing film,
  cartons and tape a machine or area is meaningful and will be carried.
- *Consumption tracing to the exact lot/bag.* FC-01 forbids claiming bag-to-batch
  provenance; DEC-20260810-001 fixes the wording as "the bin held these lots";
  DEC-20260807-007 records that the bin is never weighed, so a bag-level attribution
  would drift permanently with nothing to re-anchor it.

**How the code refuses today** (worth preserving): the day-bin consumption figure
returns `null` — not zero — when no closing count exists, and that null propagates
honestly to the screens; `loadBag` has no machine parameter at all (removed, not made
optional); the carton trace attributes by shift window and carries an
`unattributed_loaded_kg` with a plain-English reason.

Recorded as **Q50**. The workflow proceeds either way; only those two claims wait.

## 6 · Safest sequence

**Additive first:** material requests + lines; store issues + lines (partial, remaining,
cancelled, completed); bag-scan records carrying request, issued-by and received-by;
the "Issued to Production" location; the two new purposes; the store queue and request
screens.

**Behaviour change second, behind a flag:** the completion issue's source warehouse and
the godown alias for the new location.

**Must never move:** `stock_movements` (append-only by design), anything already posted
to Tally, and the historical machine-stamped day-bin rows. Forbidden: back-filling
`work_center_id`, deleting day-bin rows, rewriting consumptions on posted entries,
renaming a godown on a posted voucher.

## 7 · The tests that pin today's behaviour

`CommonInputLoadIsNotStockTest` (the central pin: a scan is not stock),
`FactoryDayBinBagLoadTest`, `BagCostTraceabilityTest` (a load names no machine; batch
cost carries no bag identity), `CommonResinEstimateTest`, `FactoryDayBinTest` (whose
store→bin transfer test is the one most likely to be rewritten),
`TraceabilityTest`/`TraceabilityContractTest`, `DayBinCrossBatchCarryoverTest`,
`HandoverOpeningBasisTest`, `SegmentHandoverTest`, `BinBayTest`,
`DayBinAttackRefusalsTest`, `CartonInternalTraceTest`, the ledger-invariant trio, and
the godown/voucher-shape tests. Any custody-transfer prototype runs the ledger trio
first.
