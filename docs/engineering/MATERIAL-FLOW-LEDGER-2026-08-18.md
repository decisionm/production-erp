# Store Issue is not Production Consumption — the ledger, step by step

Produced by `MaterialFlowChainTest` itself, not by a separate script that could drift from it:

    MATERIAL_FLOW_LEDGER_REPORT=1 php artisan test --filter=test_b2_2_to_b2_7

| Step | RM Store | Production/WIP | FG Store | **Consumed** |
|---|---|---|---|---|
| B2-1 the request | 1000.0000 | 0.0000 | 0.0000 | **0.0000** |
| B2-2 the store issue | 700.0000 | 300.0000 | 0.0000 | **0.0000** |
| B2-3 the bag scans | 650.0000 | 350.0000 | 0.0000 | **0.0000** |
| B2-4 the production receipt | 650.0000 | 350.0000 | 0.0000 | **0.0000** |
| B2-7a the reconcile with an issue open | 650.0000 | 350.0000 | 0.0000 | 0.0000 |
| B2-5 the batch consumption | 650.0000 | 230.0000 | 8000.0000 | **120.0000** |
| B2-6 the return | 730.0000 | 150.0000 | 8000.0000 | 120.0000 |
| B2-6b the refused return | 730.0000 | 150.0000 | 8000.0000 | 120.0000 |
| B2-7b the closing reconcile | 730.0000 | 150.0000 | 8000.0000 | 120.0000 |

## What the table proves

1. **Raising a request moves nothing.** 1000 / 0 / 0, consumed 0.
2. **A store issue is NOT a consumption.** 350 kg left the Raw Material Store and stands in
   Production/WIP, and the consumed figure is still **exactly zero**. The books hold it as
   stock the whole time.
3. **Consumption comes out of WIP, and the store is not touched again.** At B2-5 the WIP
   balance falls 350 → 230 and consumed rises 0 → 120, while **RM Store stays at 650**. The
   store cannot be charged twice for the same material — the chain test asserts this as a
   movement census (zero consumption movements against the store), not merely as a balance.
4. **Unused material comes back.** The return moves 80 kg from WIP to the store: 650 → 730 and
   230 → 150.
5. **Conservation holds at every row.** 730 + 150 = 880 = 1000 − 120 consumed.

The Phase 5 ledger invariant — `stock_balances == Σ signed stock_movements` — is asserted
after **every** step above, and `inventory:check-ledger` is exercised in the same walk.

## Where this ran, and where it did not

**On a local instance, not on live.** Walking this chain writes real stock movements and a
real production batch. `AGENTS.md` forbids exactly that as a side effect of a verification
task: *"Do not post a Tally voucher, create/cancel a production batch, or change production
stock as a side effect of any documentation or tooling task."* No figure on live was created,
altered or reconciled to make this pass.

What was checked against live instead, read-only: the Stock screen lists **RM-STORE** and
**WIP** as separate locations carrying separate balances, which is the same three-location
model this table walks.

## The steps this table does not cover

The chain above is Request → Issue → bag scan → WIP → Start Batch → consumption → Complete →
FG. The **Shift Summary** and **Tally visibility** legs are covered by their own acceptance
chains (chain A and the Tally voucher tests) rather than re-walked here; the Tally shift
voucher is asserted to contain exactly the approved entries, by inclusion AND exclusion.

---

# Resin, from the purchase order to the production floor

Produced by `ResinReceivingChainTest`, walked over HTTP:

    MATERIAL_FLOW_LEDGER_REPORT=1 php artisan test --filter=ResinReceivingChain

| Step | RM Store | Production/WIP | Bags |
|---|---|---|---|
| 0 · before anything | 0.0000 | 0.0000 | 0 |
| 1 · purchase order raised and sent | 0.0000 | 0.0000 | 0 |
| 2 · goods receipt — 4 bags scanned | **100.0000** | 0.0000 | **4** |
| 3 · request raised and submitted | 100.0000 | 0.0000 | 4 |
| 4 · store issue — 75 kg handed over | **25.0000** | **75.0000** | 4 |

## What the chain proves

1. **A purchase order must be SENT before goods can be received against it.** A draft is
   refused — "Cannot transition purchase order from draft to received".
2. **Receiving creates the lot and one identifiable bag record per physical bag.** Four
   supplier barcodes were scanned (`RELPET-B1..B4`) and those are the bags' identities. The
   no-barcode path is exercised by its own test rather than asserted here in prose: a receipt
   submitted with no barcodes yields four bags with four distinct generated identities and the
   same kilograms.
3. **Provenance is two hops, and both are asserted:** the lot names its goods receipt, and
   that receipt names the purchase order — which names the supplier.
4. **The receipt puts the resin in the Raw Material Store**, 4 × 25 kg = 100 kg.
5. **Raising a request moves nothing.** Step 3 changes no balance.
6. **A store issue is CUSTODY, NOT CONSUMPTION.** 75 kg moves RM Store → Production/WIP, and
   the consumed total is asserted to still be **exactly zero**. The floor panel, read from the
   Production/WIP balance, independently reports the same 75 kg.
7. **FC-01 holds at the moment a bag is created:** every bag is asserted to name no machine.

## The receiving screen

The GRN form collects, per supplier lot: **supplier lot number · bag count · kg per bag**, with
a running **bag total vs receipt line** check, and a **received date** on the receipt. Bag
barcodes are scanned per lot, one after another, with the four figures the receiver watches on
screen throughout:

    Expected bags | Scanned | Remaining | Total weight

**The barcode contract, exactly as implemented and tested** (`frontend/src/features/procurement/lotScan.ts`,
10 vitest cases):

| Scans taken | Submittable? | What happens |
|---|---|---|
| none | yes | the server generates one identity per bag |
| all expected | yes | the scanned barcodes ARE the bags' identities |
| some (0 < n < expected) | **no** | refused, naming how many are left |
| more than expected (bag count reduced under the scans) | **no** | refused, naming both figures |

A part-scanned lot is never silently converted to generated identities. The only route from
part-scanned to generated is the explicit, confirmed **"Discard scans"** action, which states
how many will be lost. Reducing the bag count below the scans is refused rather than
discarding the extras — the screen used to read "all bags scanned" in that case, because the
remaining count floored at zero.

An unfinished receipt survives a refresh: it is saved under its own receipt key on every scan
and offered back when the form reopens, to carry on or to start fresh.

`/inventory/material-lots` is the resin traceability register — each lot with its GRN, its
purchase order, its received date and time, the price paid and its bags.
`/inventory/serial-numbers` is generic per-unit serial tracking and is **not** part of this
chain; it lists only items configured as serial-tracked.
