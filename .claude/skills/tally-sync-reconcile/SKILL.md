---
name: tally-sync-reconcile
description: Use when ERP stock disagrees with Tally — negative balances, "consumed more than the recorded stock" warnings, or a monthly stock sync. Matching, never adding.
---

# Reconcile ERP stock to Tally

Tally is this factory's book of record for stock. When the ERP disagrees,
the ERP moves — by the DIFFERENCE, never by re-applying a balance.

## The one distinction that prevents silent double stock

- Opening-stock apply (`TallyOpeningStockService`) **adds** Tally's closing
  balance. Correct exactly once, on an empty ledger. A later snapshot
  applied this way = double stock, no error anywhere.
- Reconcile (`tally:reconcile-stock`, workflow "Match stock to Tally")
  **matches**: reads what the ERP holds, moves only the difference. Safe to
  repeat on every fresh snapshot. This is the one you want (DEC-20260806-009).

## Procedure

1. **Factory PC:** sync-agent tray → "Read Stock Summary (preview only)".
   Read-only against Tally; creates a snapshot in the ERP. Without a fresh
   snapshot there is nothing to reconcile — stop and ask for this first.
2. Workflow "Match stock to Tally", `write=false`. Read EVERY line: item,
   direction (receive/issue), ERP → Tally figure.
3. Anything surprising (a negative in Tally, an unmatched item) — stop,
   put it to the owner. Negatives in Tally are reported, never copied.
4. `write=true`, then dry-run again: expect zero movements remaining.
5. Every movement carries reference `TALLY-RECONCILE-<snapshot id>` — cite
   it when reporting.

## Boundaries

- One snapshot reconciles once (re-matching an old photograph would undo
  the production since). A fresh snapshot may reconcile again.
- Shortfall warnings on approval do NOT block signing and are not "cleared"
  by inventing receipts — they are cleared by this reconcile, and material
  issued before the reconcile keeps its recorded (possibly zero) cost.
