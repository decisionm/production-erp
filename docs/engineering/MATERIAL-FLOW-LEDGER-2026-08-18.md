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
