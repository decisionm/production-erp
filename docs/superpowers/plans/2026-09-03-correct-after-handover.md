# A handed-over batch can still be corrected — DEC-20260903-007

## Why

Quality tried to send batch 20260902-M03-002 back for a quantity mismatch and
was refused: *"this batch was handed over to the next shift, and that shift
opened from its closing weights — it can no longer be corrected on its own"*.
The floor then had no route at all: the machine has moved on, only the
paperwork is wrong, and nothing goes back to a machine.

The guard is **wider than its own reason**. What a following segment inherits
is exactly ONE number — the counted closing bin weight in kilograms:

- `DayBinLedgerService::openingFor()` → `closingFor($parent, $item)` → the
  latest `day_bin_movements` row of `type = count` for that segment and item.
- Everything else a child carries (`batch_number`, cycle time, cavities,
  `calculation_version`, `configuration_gaps`, `opening_day_bin_basis`) is
  **copied at creation and frozen** — amending the parent cannot move it.
- `returnToProduction()` writes no count row at all. It never had business
  behind that guard.

So the pieces produced, the rejects, the reason, the downtime — none of them
reach the next shift, and all of them were being refused.

## The rule (owner, 03-Sep-2026)

> supervisor can edit, refuse if closing weight changes

Recorded as **DEC-20260903-007**. Expressly not built: correcting a chain —
reopening the following segment and restating its opening — because that
rewrites a shift somebody has already signed.

## What changes

**One file, one guard moved and narrowed.**

1. `rowOpenForCorrection()` loses the `childSegments()->exists()` refusal.
   Both callers — `amendCompletion()` and `returnToProduction()` — stop
   refusing on handover. Every other gate there stands unchanged: still
   running, already approved, already synced, failed, rejected, already on a
   Tally voucher.
2. `amendCompletion()` gains `refuseRestatingHandoverOpening()`, run before
   any mutation beside `refuseStaleMaterialLines()`: when a following segment
   exists AND an incoming `closing_day_bin` line differs from the recorded
   `closingFor()`, refuse and name the figure the next shift opened from.

**Compared against `closingFor()` itself**, never against the entry's stored
completion figure — the same function `openingFor()` reads is what keeps the
guard exactly as wide as its reason. A closing derived at handover (basis
`ledger`, written as a count row at `ShiftProductionEntryService` ~1947) is
therefore the baseline too.

**No frontend change.** The Edit door is already offered on every completed,
pending, unchecked row (`canAmendCompletion`), the drawer already reads
"Correct — sent back" for a returned batch, and the server's refusal sentence
is already shown. The correction drawer prefills closing weights as `null`
and drops blank rows at submit, so a pieces-only correction sends no
`closing_day_bin` at all and the new guard never fires on it.

## Tasks

### Task 1 — narrow the guard (backend, test-first)

Rewrite `test_a_segment_that_handed_over_can_no_longer_be_corrected` into the
new contract, citing DEC-20260903-007:

- Quality **can** return a handed-over batch (200), and the supervisor then
  corrects the piece count (200) — the chain the owner asked for, end to end.
- An amendment resubmitting the SAME closing weight is allowed.
- An amendment changing the closing weight is refused, message names the
  recorded figure. (`correctFigures()` already moves the closing 80 → 75, so
  the old fixture is exactly this case.)
- The child's inherited opening is unchanged after the allowed correction —
  asserted through `DayBinLedgerService::openingFor()`, not by reading a row.

Then make them pass.

## Out of scope, deliberately

- **Cancelled following segments.** A cancelled child stands on nothing, so
  the guard arguably over-blocks there too — but `cancelTestBatch()` refuses a
  parent that handed over, no owner asked for it, and unreachable narrowing is
  scope on a live refusal change.
- **Chain correction.** Refused today by decision; the factory decides later
  whether it should exist.
