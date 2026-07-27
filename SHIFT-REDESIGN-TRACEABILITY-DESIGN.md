# Phase 6 Design — Lot/Barcode Traceability & Shift Continuity

Design only — no implementation until this is reviewed and the Vincent
questions at the end are answered or explicitly deferred to configuration.
Formulas and constraints come from the master brief + addendum; nothing here
contradicts the deployed baseline (PRs #20–#31).

## The traceability chain

GRN → Supplier lot → Bag (one barcode each) → RM Store → Machine day-bin →
Production batch (shift segment) → Tally voucher.

## Data model (all additive)

**material_lots** — one row per supplier lot on a GRN:
`id, grn_id, item_id (resin/MB), supplier_lot_no, received_date, bag_count,
bag_weight_kg (nominal), total_received_kg, notes`.

**material_bags** — one row per physical bag:
`id, material_lot_id, barcode (unique), original_kg, remaining_kg,
status (in_store | in_day_bin | consumed | returned), current_warehouse_id,
day_bin_work_center_id (nullable)`.
Barcode = supplier's when scannable, else app-generated
(`LOT{lot}-B{seq}`, printable). Uniqueness enforced by the column;
duplicate scan = constraint hit = clear error, not silent double-count.

**day_bin_movements** — the shift-side ledger per machine per material:
`id, work_center_id, item_id, shift_production_entry_id (nullable — segment),
type (load | return | count), material_bag_id (nullable), quantity_kg,
recorded_by, recorded_at`.
Loading a bag decrements `remaining_kg`; partial loads allowed (weighed);
returns flow back to the bag (or to a regrind/return item — Vincent Q4).

**shift_production_entries** gains `parent_entry_id` (nullable) — a **shift
segment**: when a run crosses the shift boundary, the outgoing segment is
completed and a new entry is opened with the same batch number, product,
mold standards, machine, and the day-bin closing balance carried in as the
opening. `batch_number` stays the run's identity; segment = entry row.

## The consumption formula (per machine, material, segment)

`actual_consumed_kg = opening_day_bin + Σ loaded − closing_day_bin − Σ returned`

- `opening_day_bin`: previous segment's closing (0 for a fresh run).
- `loaded`: bag scans (full bag = its remaining_kg; partial = weighed entry).
- `closing_day_bin`: weighed/estimated at segment end (Vincent Q3: scale or
  visual estimate?).
- The computed figure PRE-FILLS the dedicated Resin/MB consumption rows in
  Complete Batch (deployed in PR #29) — supervisor confirms or corrects;
  a correction beyond the configured tolerance requires a remark.

## Allocation rules

- **FIFO by received_date, then bag sequence** — the pick list suggests the
  oldest open bag; scanning a newer bag while an older one stays open needs
  the override permission (`production.override-fifo`, new) and records who.
- **Over-consumption guard**: a load can never exceed the bag's
  `remaining_kg`; a closing count can never exceed opening+loaded.
- **Multi-bag per batch / one bag across shifts** both fall out of the model
  naturally (movements reference bag AND segment).

## Supervisor experience (design target)

Start Batch (or shift takeover): scan bag(s) into the machine's day bin —
camera scan on the phone (PWA, `BarcodeDetector` with manual-entry
fallback), shows lot + remaining kg per scan. Complete Batch: enter closing
day-bin kg; the app shows the computed consumption beside the weighed
figure. Takeover screen (new): incoming supervisor sees the running batch,
its lots, and the day-bin balance they inherit — one confirm button.

## Tally impact

None until Phase 8's tracer: vouchers keep posting item+godown totals.
Lot detail stays app-side (Tally batch allocations already carry the
production batch number; extending them to carry supplier lots is a
separate Vincent/Sendhil decision — not assumed here).

## Rollout

1. Schema + masters + API (no UI) — invisible.
2. GRN bag intake + printable barcodes; backfill open stock as one
   "opening lot" per material (needs the physical count they promised).
3. Day-bin scanning on Shift Floor behind a config flag, one machine pilot.
4. Prefill of consumption rows from the formula; manual entry stays the
   fallback throughout — the factory can ignore scanning entirely and
   nothing breaks (config-first, per the brief's "don't block on Vincent").

## Vincent confirmations needed before build

1. Bag sizes and whether supplier barcodes exist on resin/MB bags today.
2. Day-bin closing: weighed on a scale, or estimated? Which scale capacity?
3. Is FIFO mandatory policy or preference (who may override)?
4. Returned material: back to the bag, or into a separate return/regrind
   stock item?
5. Cross-shift: confirm same batch number continues (addendum implies yes)
   and who owns the handover confirmation (incoming or outgoing supervisor).
6. Partial-bag measurement practice (weigh the bag, or weigh the loaded
   quantity?).
