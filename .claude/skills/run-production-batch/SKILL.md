---
name: run-production-batch
description: Use for questions about the batch lifecycle — start, complete, QC, approve, what reaches Tally and from which store. The invariants, not the button clicks.
---

# The production batch, start to Tally

Screens change weekly; these invariants do not. For exact current behaviour
read the code — `ShiftProductionEntryService` and `TallySyncService` are the
contract, their tests the proof.

## The chain

START — freezes a config snapshot (weight, cavities, cycle time, colour:
configuration → standard → item master). A batch started wrong can be
withdrawn; a running machine can stop/resume; backdating is supported.

COMPLETE — the counts (boxes/trays/pouches/loose) and every consumption
line: resin, masterbatch, packing. Suggestions PREFILL, people DECIDE — a
figure typed by the floor always outranks a computed one. Nothing here may
invent a quantity for an unweighed material (blank, never a guess).

QC then APPROVE — four eyes at both steps (FC-05). Approval shows the
voucher preview: what posts, what is withheld and why, any stock shortfall
(which warns, never blocks — see tally-sync-reconcile).

TALLY — one Stock Journal per batch via the agent's queue. IN: produced
bottles + PET Scrap (FC-02). OUT: resin and masterbatch from the day bin,
packing from the packing store (FC-04). What is withheld
is DATA, not doctrine: read FC-03 and the packing mappings for the current
answer (tape's metres-vs-Nos question, standing lines with no item named) —
the day the owner answers, the data changes and this file must not be the
stale copy that says otherwise.

## Hard rules when touching this flow

- A resin bag belongs to no machine and no batch; a scan is a pour record
  (FC-01). Consumption is calculated, never claimed as bag provenance.
- Clear bottles take no masterbatch (FC-07).
- Material moves at COMPLETION, not approval — a later stock fix does not
  reprice already-completed batches.
- Never create or cancel a real batch, or post a voucher, as a test. Test
  through `php artisan test` fixtures.
