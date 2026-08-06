# Go-Live Plan — 28 Jul 2026

Status as of the night before go-live (27 Jul). Source: factory demo/meeting of 27 Jul
(recorded; formal summary agreed) plus the flow doc the factory annotated on 25 Jul.
Owner: Muthukumar (deployment), Sendhil (review/merge), Vincent (factory/accounts).

## The live flow (proven end to end on 27 Jul)

Supervisor (phone) → Start Batch on the machine → Complete Batch at shift end
→ Plant Manager approves → **Accountant approves = posts to Tally** (no MD stage;
reserved for future "big approvals") → Manufacturing Journal voucher SPE-{id}
in Tally Day Book ~90 s later via the local agent. Failures are visible on the
Approve page (Failed tab) with the exact Tally error and are retryable from
Tally Sync — nothing needs re-entry.

Approval screen also shows **Material Usage vs Norm** per batch: expected
(item weight now; BOM once recipes exist) vs actual, with rejection / scrap /
unaccounted breakdown. Unaccounted is the number management investigates.

## Shipping the night before go-live (this branch)

| Change | Behaviour |
|---|---|
| Packing master on product | `nos_per_tray`, `trays_per_box`, `nos_per_box` on items (nullable). No values hard-coded — loaded from the factory's Excel when it arrives. |
| Packing auto-fill | Selecting the product prefills per-tray/per-box; trays/boxes suggested from quantity (ceil). Always editable; with no standards set the form behaves exactly as before. |
| Helper name | Optional free-text on Complete Batch, shown on approval. |
| FG warehouse default | Start Batch defaults to the first FG-matching warehouse ("FG Store" exists in prod); user choice always wins. |
| Inactive machines hidden | Shift Floor machine picker filters `is_active` — makes machine cleanup a pure data operation. |
| Packing vs standard | Approval drawer shows expected trays/boxes next to entered counts when standards exist. |

Already live from earlier waves: accountant-gate chain, consumption variance,
failed-sync visibility, 401→login redirect, 12 h sessions, inactive items hidden
(demo items deactivated in prod), shift auto-select with night-date rule + grace.

## Blocked on factory data (loads, not builds)

1. **Machine names** — Vincent to confirm the real ~10. Current prod: generic
   MC-01…MC-10 plus five seeded stations (EBM-01, INJ-01, BLOW-01, LABEL-01,
   PACK-01). Plan: rename MC-xx to real names, deactivate the stations.
2. **Packing/consumption Excel** ("auto-formulated") — feeds the packing master
   and the consumption norms (future BOM source). Load via API on arrival.
3. **Ambiguous weights** — 200Ml Round & Brute (18 vs 20 g), 400Ml Round
   (26 vs 31.5 g), 500Ml Round & Jli (31.5 vs 36 g): which Tally item is which.
4. **Physical opening stock** — item-wise, to replace provisional figures.
5. **Warehouse duplication** — seeded warehouses overlap Tally godowns
   (RM-STORE vs "RM Store", FG-STORE vs "FG Store"). Consolidate after go-live;
   vouchers must reference godown names that exist in Tally.

## Open confirmations (small, non-blocking)

- Helper name: stays free text, or employee picker later?
- Voucher granularity: per batch (current) vs per shift per machine — Vincent's
  preference for his Day Book.
- Rounding rule for rejected-bottle weight, if the factory has one.

## Day-1 runbook

1. Tally PC + agent ON before Shift A; agent status green in Tally Sync.
2. Supervisors log in with own IDs (12 h sessions; PWA installed).
3. Every batch: Start at machine, Complete at shift end with consumption,
   rejection (nos), lumps (kg), packing counts, helper name.
4. Plant Manager then Vincent approve; Vincent checks the variance block.
5. Voucher appears in Day Book ~2 min; anything in the Failed tab → read the
   error in the drawer, fix cause, Retry from Tally Sync.
6. Parallel run: paper worksheet continues alongside the app until the factory
   declares confidence.
