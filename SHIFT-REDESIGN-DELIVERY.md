# Shift Redesign — Delivery & QA Pack (28 Jul 2026)

Final deliverable for the shift-redesign programme. Companion docs:
`SHIFT-REDESIGN-FORMULAS.md` (formula dictionary + fixtures),
`SHIFT-REDESIGN-TRACEABILITY-DESIGN.md` (Phase 6 design),
`GO-LIVE-PLAN.md` (go-live baseline), `DEPLOY.md` (pipeline).
Owners: Muthukumar (deployment), Sendhil (review/merge), Vincent (factory/accounts).

---

## 1. End-to-end workflow

**Default flags (today's live behaviour):**

1. **Start Batch** (supervisor, phone/PWA) — machine picker in business
   sequence (Machine 1 … Machine 10, inactive machines hidden). Batch number
   minted automatically: `{Ymd}-M{machine}-{seq}`, unique per machine per
   production date; supervisors never type it. The item's molding standards
   (`standard_cycle_time`, `standard_cavities`) are **snapshotted onto the
   entry** at this moment — later master edits never rewrite a running batch.
   `active_cavities` defaults to the standard and stays editable (blocked
   cavity mid-run). Night shift files under the date the shift **started**
   (22:00–06:00 + grace window).
2. **Running** — the floor sees the live expected-output figure (frontend
   mirrors the engine formula: `3600/CT × active cavities × running hours`).
   Downtime and mold-change logs follow the same date convention.
3. **Complete Batch (box-first)** — the supervisor enters what was physically
   counted: boxes (+ per-box pack), trays, pouches, loose pieces (any
   combination; unused families stay null), running hours (0 < h ≤ 24
   enforced), actual cycle time, rejection (nos → kg via piece weight),
   QC-weighed rejection kg, lumps kg, material consumption lines (each with
   its **own source godown**), helper name. Standards are never writable
   through this request. Completion submits the entry as `pending`.
4. **Plant Manager approves** — verifies the shift's figures; no voucher yet.
5. **Accountant approves = posts** — the posting gate (MD stage dormant,
   reserved for future "big approvals"). The approval screen shows the two
   judgement blocks: *Material Usage vs Norm* (BOM norm first, else item
   weight; variance %, bands from config) and the *expected-output metrics*
   (expected pieces/boxes, boxes-based efficiency, QC-vs-production rejection
   difference, reconciliation `issued − good − confirmed rejection − lumps`).
   Optional hard gate: `PROD_TOL_UNACCOUNTED_BLOCKING` refuses approval
   beyond the threshold.
6. **Tally** — approval enqueues one voucher (`SPE-{id}`, default granularity);
   the local Windows agent polls, posts to Tally's XML gateway, and acks.
   Voucher appears in the Day Book ~90 s–2 min. Failures land in the
   **Failed tab with the exact Tally error** and are retryable from Tally
   Sync — no re-entry, no duplicate voucher.

**With `PROD_TRACEABILITY=true` (dormant today):** GRN → supplier lot →
barcoded bags → day-bin scanning per machine (`load`/`return`/`count`, FIFO
with permissioned override) → per-segment consumption
`opening + loaded − closing − returned` prefills the consumption rows.
Shift **handover** completes the outgoing segment and opens a child entry
(same batch number, same standards snapshot, `parent_entry_id` set); the
day-bin closing carries into the child as its opening.
**With `TALLY_VOUCHER_GRANULARITY=shift`:** approvals of the same
(date, shift) merge into one Stock Journal `SJ-{Ymd}-S{shift_id}`
(follow-ups `-2, -3…` after sync/delivery). Unproven against real Tally.

---

## 2. QA matrix

Statuses: **AUTO** = existing automated coverage · **AUTO(new)** = gap filled
in this pack · **TRACER** = only provable against live Tally (tracer batch,
§7) · **MANUAL** = human script. Tests are `File::method` under
`backend/tests/Feature/`.

| # | Item | Status | Covering tests / script |
|---|------|--------|------------------------|
| 1 | Machine 1–10 natural ordering | AUTO | MachineOrderAndAutoBatchTest::test_machines_list_in_business_sequence_not_name_order |
| 2 | Automatic unique batch generation | AUTO | MachineOrderAndAutoBatchTest::test_start_batch_mints_a_unique_readable_batch_number, ::test_completion_without_a_batch_number_keeps_the_minted_one |
| 3 | Product-standard auto-fill | AUTO | ExpectedOutputEngineTest::test_start_batch_snapshots_the_item_standards_and_defaults_active_cavities, ::test_item_master_round_trips_colour_and_molding_standards; PackingMasterTest (4 packing-master tests). Form-side prefill: inspection §9 step 4 |
| 4 | Cycle-time/cavity calculation | AUTO | ExpectedOutputEngineTest::test_wb2_row_6…, ::test_wb2_row_7…, ::test_partial_shift_four_hours…, ::test_addendum_fixture_expected_15_actual_13… (workbook fixtures verbatim) |
| 5 | Changed active cavity count | AUTO | ExpectedOutputEngineTest::test_standards_are_never_writable_through_start_or_complete_requests (override to 4 cavities drives the engine: 13 expected boxes) |
| 6 | Missing standards | AUTO | ExpectedOutputEngineTest::test_missing_cycle_time_yields_null_expectations_never_fake_numbers; ReportEndpointsTest::test_rows_with_null_expected_contribute_to_neither_sum; PackagingModelTest::test_expected_pouches_is_null_safe |
| 7 | Zero/invalid running hours | **AUTO(new)** | RunningHoursValidationTest (4 tests: 0 → 422, −3/25 → 422, 24 = boundary OK, missing hours → null expectations with actuals intact) |
| 8 | Tray-only packaging | **AUTO(new)** | TrayPackagingTest::test_tray_only_completion_persists_trays_and_leaves_box_and_pouch_null, ::test_negative_tray_counts_are_rejected |
| 9 | Pouch-only packaging | AUTO | PackagingModelTest (8 tests: standards round-trip, persist, null-safety, packing_rounding modes, exact division) |
| 10 | Tray-and-pouch packaging | **AUTO(new)** | TrayPackagingTest::test_tray_and_pouch_completion_persists_both_container_families |
| 11 | Partial box and loose pieces | **AUTO(new)** | TrayPackagingTest::test_partial_box_counts_as_whole_boxes_plus_loose_pieces (2 boxes + 257 loose, no fabricated third box); PackagingModelTest::test_complete_batch_persists_no_of_pouches_and_loose_pieces |
| 12 | Resin and masterbatch consumption | AUTO | ConsumptionVarianceTest::test_active_bom_overrides_item_weight_and_excludes_non_kg_components; TallySync/ShiftVoucherGranularityTest::test_two_approvals_in_the_same_shift… (resin+MB summed item+godown-wise); TallySync/OutboundVoucherTest::test_approved_production_enqueues_a_manufacturing_journal |
| 13 | Partial-bag and multi-bag consumption | AUTO | TraceabilityTest::test_full_and_partial_loads…, ::test_consumption_formula_on_the_multi_bag_partial_fixture; SegmentHandoverTest::test_day_bin_closing_carries… (one bag across two segments) |
| 14 | Shift handover | AUTO | SegmentHandoverTest (5 tests: flag-off 404, identity+snapshot inheritance, day-bin carry, in-progress guard, atomic abort on bad closing count) |
| 15 | Rejection and QC reconciliation | AUTO | ExpectedOutputEngineTest::test_qc_difference_wb2_row_7…, ::test_reconciliation_wb1_row_62…; ReportEndpointsTest::test_reconciliation_report_orders_worst_unaccounted_first (QC-wins rule) |
| 16 | Lumps/scrap | AUTO | TallySync/ProductionSyncWritebackTest::test_scraps_ride_the_voucher_payload_and_narration; lumps legs of #15's tests; ConsumptionVarianceTest scrap_kg assertions |
| 17 | Variance calculations | AUTO | ConsumptionVarianceTest (7 tests incl. new Kgs. regression); ApprovalToleranceTest::test_bands_follow_configured_tolerances; ReportEndpointsTest variance_pct/band assertions |
| 18 | Approval and rejection correction | AUTO (partial) | ApprovalChainTest (5 tests: order, no skipping, dormant MD, rejection, role gates); ApprovalToleranceTest blocking-gate tests. **Correction after rejection is a product gap** — no resubmission flow exists (see §6), so nothing to test yet |
| 19 | Tally success | AUTO + TRACER | Queue side: ProductionSyncWritebackTest::test_agent_ack_marks_the_entry_synced; OutboundVoucherTest payload assertions. Live Day Book proof: tracer batch §7 |
| 20 | Tally failure visibility | AUTO | SyncFailureVisibilityTest::test_failed_entry_lists_with_its_latest_sync_error; ProductionSyncWritebackTest::test_agent_failure_marks_the_entry_failed…; ShiftVoucherGranularityTest::test_agent_ack_and_failure_fan_out_to_every_member_entry |
| 21 | Retry without duplicate voucher | **AUTO(new)** + TRACER | ProductionSyncWritebackTest::test_retry_requeues_the_same_voucher_row_and_never_mints_a_duplicate (new); ShiftVoucherGranularityTest::test_re_enqueueing_a_vouchered_entry_is_idempotent, ::test_a_delivered_voucher_is_never_merged_into. Tally-side dedupe: tracer §7 step 10 |
| 22 | Item/godown/UOM mismatch | TRACER + **AUTO(new)** | The rejection itself only live Tally can produce (tracer §7 abort criteria); error surfacing is #20's tests. App-side UOM leg now covered: ConsumptionVarianceTest::test_tally_kgs_trailing_dot_uom_still_counts_toward_the_bom_norm (new, regression for PR #33) |
| 23 | Mobile-screen usability | MANUAL | Script: on a supervisor's actual phone (installed PWA), run one full Start → Complete → view-on-Approve cycle in portrait. Pass = every field reachable without horizontal scrolling, keypad type matches field (numeric for counts), and the machine picker + variance block are readable at arm's length. |

**Counts:** 15 items fully automated pre-existing · 4 gap items filled now
(**10 new tests**: RunningHoursValidationTest ×4, TrayPackagingTest ×4,
ProductionSyncWritebackTest +1, ConsumptionVarianceTest +1) · 2 items with a
live-Tally half deferred to the tracer (19, 22; also 21's Tally-side dedupe) ·
1 manual (23) · 1 product-gap noted (18's resubmission half).

**Suite after this pack: 124 tests, 626 assertions, all passing**
(`./vendor/bin/pint --dirty` clean; `php artisan test` exit 0).

---

## 3. Master-data requirements per product

What each finished-good item needs for the full feature set to light up
(everything degrades gracefully to manual entry when absent):

| Field(s) | Enables |
|---|---|
| `nominal_weight_grams` | pcs→kg conversions, rejection kg, item-weight consumption norm |
| `nos_per_tray`, `trays_per_box`, `nos_per_box` | packing auto-fill at Complete Batch, expected boxes (with CT), packing-vs-standard notes |
| `nos_per_pouch`, `pouches_per_box` | pouch fields on the form (shown only when set), `expected_pouches` |
| `standard_cycle_time`, `standard_cavities` | expected pieces/boxes, efficiency %, the whole expected-output engine |
| `colour` | masterbatch suggestion, colour-specific scrap item mapping (Pet scrap amber/clear) |

**Current coverage (27 Jul exception report + load logs):**

- 649 active items, of which 410 finished goods.
- **CT + cavities: 46 items** (WB2 standards load, 24/28 workbook rows
  matched) — the expected-output engine is live for exactly these; 364 FG
  items without (mostly dormant products).
- **Packing standards: 89 items** loaded from the factory's
  "Daily Production sample data.xlsx"; ~42 multi-match + ~30 no-match pending
  (`~/Downloads/packing-standards-pending.txt`); ~329 FG items still without.
- **Pouch standards: 0 items** — no pouch evidence in either workbook
  (formula dictionary #29, NEEDS-VINCENT); pouch fields therefore invisible
  everywhere today.
- **Weight:** ~48% of item names embed the grams (auto-backfillable); 117 FG
  items missing weight per the report.
- **Colour: 364 FG items missing** — MB suggestion inert for them.
- **The 4 unmatched products** (customer-name mapping needed from Vincent):
  *500ml long neck Green, 750ml Kidney Clear, 500ml IFF Amber, 180ml PDL
  Clear.*
- **Ambiguous weight families** (which Tally item is which weight):
  *200Ml Round & Brute* (18 vs 20 g), *400Ml Round* (26 vs 31.5 g),
  *500Ml Round & Jli* (31.5 vs 36 g).

Priority rule: only products that actually run matter — the workbook set is
covered; extend as Vincent resolves the pending lists.

---

## 4. Tally mapping report

- **Batch granularity (default, `TALLY_VOUCHER_GRANULARITY=batch`):** one
  voucher per approved entry — voucher number `SPE-{entry id}`, voucher type
  `Manufacturing Journal` (posted into Tally's Stock Journal class by the
  agent), production line to the entry's FG godown, **each consumption line
  carrying its own source godown** (PR #27 — without it resin would book
  against the FG godown), batch number in the batch allocations, scraps in
  payload + narration.
- **Shift granularity (`shift`):** one aggregated `Stock Journal` per
  (production_date, shift) — `SJ-{Ymd}-S{shift_id}`, consumption summed
  item+godown-wise, production item-wise; entries approved after the voucher
  synced/was delivered open follow-ups (`-2`, `-3`…); membership tracked on
  `shift_production_entries.tally_sync_entry_id` (exactly one voucher per
  entry); ack/failure fans out to every member entry. **Never flip the flag
  with a non-empty queue** — the guard against sweeping batch-era entries is
  tested, but the documented procedure is: drain, then flip.
- **Known blockers (voucher-fatal, pending remediation approval — summary of
  `~/Downloads/tally-exception-report.txt`):**
  - **7 seeded items** with no Tally identity still active (PET Resin
    (Virgin Grade) — holding 9,000 kg provisional opening stock, HDPE Resin,
    Colour Masterbatch Clear/Blue, Label 500ml, Corrugated Carton 24, PET
    Preform 28g). Any voucher naming one fails ("Stock Item does not
    exist"). Fix awaiting approval: deactivate the 7, move opening stock to
    the real Tally items during the physical-count load.
  - **3 seeded warehouses** without a Tally godown (RM-STORE "Raw Material
    Store" — holds the opening stock, FG-STORE "Finished Goods Store", WIP).
    A consumption line issued from them posts an unknown godown → voucher
    fails. Fix awaiting approval: consolidate onto the Tally godowns
    ("RM Store", "FG Store", "Main Location", "Dispatch Bay"), deactivate
    the seeded three.
  - Until remediation: supervisors must pick **Tally-named materials and
    godowns only**.
- **Failed-voucher ledger (all known-cause, no unknowns):** SPE-7 (demo
  item), SPE-2 (stale master), SPE-1 / DN-3 / GRN-4 (pre-builder agent
  versions), INV-1 (missing voucher date — old sales bug predating current
  code). Safe to leave failed or delete the sync rows once go-live settles.
- The "Kgs." trailing-dot UOM (90+ live items) is handled app-side since
  PR #33 (now regression-tested).

---

## 5. Feature flags & config reference

All read via `backend/config/production.php` / `config/tally-sync.php`;
override per deployment in `.env`.

| Env var | Default | Effect | When to flip |
|---|---|---|---|
| `PROD_TOL_VARIANCE_OK` | `2` | consumption variance % ≤ this → band `ok` | Vincent tightens/loosens the norm judgement |
| `PROD_TOL_VARIANCE_WATCH` | `5` | ≤ this → `watch`, above → `investigate` | with the above |
| `PROD_TOL_UNACCOUNTED_KG` | `0.5` | abs unaccounted kg above this → `investigate` | if the factory adopts the workbook's looser ~1–2 kg practice (formula dictionary gap #9) |
| `PROD_TOL_EFFICIENCY_OK` | `95` | efficiency % ≥ this → `ok` | once real efficiency baselines exist |
| `PROD_TOL_EFFICIENCY_WATCH` | `85` | ≥ this → `watch`, below → `investigate` | with the above |
| `PROD_TOL_VARIANCE_BLOCKING` | unset (off) | when set: accountant approval refused while variance % exceeds it | only after the factory wants a hard gate, not just a flag |
| `PROD_TOL_UNACCOUNTED_BLOCKING` | unset (off) | when set: accountant approval refused while abs unaccounted kg ≥ it | same — start observational, gate later |
| `PROD_PACKING_ROUNDING` | `ceil` | rounding for packing **suggestions** (`expected_pouches`, prefills): `ceil`/`round`/`floor`. Never touches the WB2 expected-boxes half-up ROUND | if the factory counts part-filled containers differently |
| `PROD_TRACEABILITY` | `false` | master switch, Phase 6: off = every lot/bag/day-bin/handover route 404s, feature invisible; schema stays applied | one-machine pilot, **after** Vincent's six answers and the opening-lot backfill |
| `PROD_TRACE_FIFO_ENFORCED` | `true` | newer-bag scan needs `production.override-fifo` + explicit override; `false` = pick list still sorts oldest-first but any bag loads | if Vincent rules FIFO is preference, not policy (Q3) |
| `TALLY_VOUCHER_GRANULARITY` | `batch` | `batch` = one `SPE-{id}` voucher per entry; `shift` = aggregated `SJ-{Ymd}-S{shift}` per (date, shift) | after Vincent confirms his Day Book preference **and** the per-shift voucher passes a tracer; drain the queue first |

---

## 6. Known limitations (honest list)

1. **Pouch standards unloaded** — the pouch model is built and tested but no
   product has `nos_per_pouch`, so pouch fields appear nowhere; no workbook
   evidence exists to load from (NEEDS-VINCENT).
2. **Traceability dormant** — designed, built, flag-gated off pending
   Vincent's six confirmations (bag barcodes, closing measurement, FIFO
   policy, returns destination, cross-shift ownership, partial-bag practice)
   plus the physical-count opening lots.
3. **No QC actor in the approval chain** — QC rejection kg is a field the
   supervisor enters at completion; Kumaraguru (Quality) has no stage or
   sign-off of his own (formula dictionary gap #6).
4. **Rejected entries have no resubmission flow** — a rejected entry parks
   with its reason; correction currently means a new entry. (QA item 18's
   untestable half.)
5. **Packing stock not auto-consumed** — tape/cartons/trays are not decremented
   by production (confirmed backlog: packing consumption feature); tape
   formulas exist in the dictionary but per-product metres/box is template
   lore, not master data.
6. **Per-shift voucher unproven against real Tally** — fully tested at queue
   level, never posted to a live Day Book; stays behind
   `TALLY_VOUCHER_GRANULARITY` until a tracer proves it.
7. Voucher-fatal seeded masters (§4) until the remediation is approved and
   executed.
8. Resin-vs-masterbatch expected split, expected trays/tape norms, canonical
   tray model, and the canonical "total RM consumed" definition all remain
   NEEDS-VINCENT (formula dictionary conflicts C3–C6) — the app ships the
   defensible subset (BOM/item-weight norm + reconciliation).

---

## 7. One-machine tracer-batch UAT script

**Purpose:** prove the full chain against a **live Tally** for one real
batch. Runs after remediation, before trusting the numbers.

**Preconditions (abort if any fails):**
- Remediation executed: the 7 seeded items and 3 seeded warehouses
  deactivated/consolidated (§4) — verified in Inventory: searching
  "PET Resin (Virgin Grade)" finds nothing active.
- Tally PC on, **Tally Sync Agent 0.1.7** running, tray icon + ERP → Tally
  Sync page both show the agent green/recent poll.
- The bound Tally company open in Tally (same company the instance is locked
  to).
- `TALLY_VOUCHER_GRANULARITY=batch` (default) — the tracer proves batch mode
  first.
- A test window agreed with Vincent (his Day Book will get one voucher).

**Steps:**
1. Pick one machine (suggest Machine 1) and one **Tally-named FG product
   that has CT+cavity standards** (one of the 46 — e.g. a WB2 workbook
   product; confirm in Inventory that it shows a Tally GUID, weight, pack and
   CT/cavities).
2. Log in as a real supervisor on the phone PWA. **Start Batch**: machine,
   shift (auto-selected), the chosen product, FG warehouse = the Tally
   "FG Store". Note the minted batch number (`{today}-M01-…`).
   *Expect:* standards visible on the running screen.
3. At completion time, **Complete Batch** with real counted figures: boxes +
   loose pieces, running hours, rejection nos, QC rejection kg, lumps kg.
   Consumption lines: **Tally-named resin** (e.g. Billion Pet Resin IV-0.8)
   and **Tally-named MB**, each issued from the Tally godown "RM Store".
   *Expect:* expected boxes + efficiency % appear in the response metrics.
4. As Plant Manager: approve. *Expect:* no voucher yet (Tally Sync queue
   unchanged).
5. As accountant (Vincent): open the entry — check the Material Usage vs
   Norm block and the reconciliation figures read sane — then approve.
   *Expect:* entry → approved; one pending voucher `SPE-{id}` in Tally Sync.
6. Wait ≤2 min, refresh Tally Sync. *Expect:* voucher `synced`; entry status
   `synced`.
7. **In Tally, Day Book (today):** exactly one new Stock Journal-class
   voucher. Open it and verify, field by field:
   - voucher number `SPE-{id}`; date = the production date;
   - **consumption side**: resin and MB lines, quantities exactly as
     entered, godown **RM Store** on each;
   - **production side**: the FG item, produced quantity, godown
     **FG Store**;
   - batch allocation carries the app batch number;
   - narration mentions the scrap/lumps line.
8. In the ERP reports (Reports → Production, today): the tracer row shows
   with expected boxes, actual boxes and efficiency; Reconciliation shows
   its unaccounted kg.
9. **Failure-path check (deliberate):** repeat steps 2–5 with one
   consumption line issued from a deliberately wrong/unknown godown *only if
   Vincent agrees*; expect the voucher to land in the **Failed tab with
   Tally's exact error**, fix the line's godown story, **Retry** — and
   verify the Day Book gains exactly **one** voucher for it, not two.
10. **Retry-dedupe check:** for the retried voucher, count Day Book entries
    with its `SPE-{id}` — must be exactly one.

**Abort criteria (stop, do not continue to per-shift):** voucher fails with
an *unexpected* error; consumption books against the wrong godown; any
quantity in Tally differs from the app; a retry produces a second voucher;
the agent goes offline mid-run. On abort: leave everything as-is (failed
vouchers are visible and retryable), report to Sendhil with the Failed-tab
error text.

Only after this passes: consider a second tracer with
`TALLY_VOUCHER_GRANULARITY=shift` (queue drained first), then Vincent's
Day Book preference decides the permanent setting.

---

## 8. Rollback / recovery

- **Code rollback:** every wave landed as a PR into `main`;
  `git revert -m 1 <merge-commit> && git push origin main` redeploys cleanly
  via GitHub Actions (build → rsync → `deploy.sh`). No manual server steps.
- **Migrations are additive** (new nullable columns/tables only) — reverted
  code simply ignores them; **no down-migrations needed in an emergency**.
- **Flag flips are instant disables** without any deploy:
  `PROD_TRACEABILITY=false` makes the whole Phase 6 surface 404;
  `TALLY_VOUCHER_GRANULARITY=batch` restores per-entry vouchers (drain the
  queue first); unsetting `PROD_TOL_*_BLOCKING` turns hard gates back into
  advisory bands. Edit the server `.env`, then rebuild caches
  (`php artisan config:cache` — `deploy.sh` does this on any deploy).
- **Failed vouchers** are never lost: they sit in the Failed tab with
  Tally's error, entry status `failed`; **Retry** re-queues the same voucher
  row (proven no-duplicate). Nothing needs re-entry.
- **Data safety:** deploys never touch `.env`, `storage/`, or the DB beyond
  `migrate --force`; approvals/vouchers are plain rows recoverable from
  MySQL backups.
- **Contact:** Sendhil (main developer/lead) — decisions via the WhatsApp
  group, fixes land as PRs he merges.

---

## 9. Owner inspection script (10–15 min, live erpdemo)

On https://erpdemo.amrtech.in with today's flags (traceability hidden, pouch
fields absent, reports live). Use your own login.

1. **Login & shell (1 min)** — log in on a phone if possible. Expect the
   PWA prompt/app feel and a 12 h session (no surprise logouts).
2. **Shift Floor (2 min)** — open the machine picker. *Look for:* machines
   listed Machine 1 → Machine 10 in that order (not 1, 10, 2), and none of
   the retired seeded stations (EBM-01, INJ-01, …) present.
3. **Start a batch (2 min)** — pick a machine and a workbook product (one of
   the 46 with standards). *Look for:* shift auto-selected; batch number
   auto-generated in the `YYYYMMDD-M{nn}-{seq}` shape; you never typed it.
4. **Complete the batch (3 min)** — choose a product with packing standards
   (one of the 89): *look for* per-tray/per-box prefilled from the master
   yet editable; **no pouch fields anywhere** (standards not loaded — by
   design); enter quantity, boxes, running hours, a rejection figure, a
   lumps kg. Submit.
5. **Approve page (3 min)** — as PM/accountant open the entry. *Look for:*
   the Material Usage vs Norm block (expected vs actual kg, variance band
   colour), expected boxes vs actual with efficiency %, QC-vs-production
   rejection difference, packing counts vs standard. Check the **Failed
   tab**: the old known-cause failures (SPE-7, SPE-2, …) each show an exact
   Tally error in the drawer — none is blank.
6. **Reports tabs (3 min)** — Reports → **Production** for a recent date:
   rows show expected/actual boxes and efficiency for standards products,
   honest blanks (not zeros) for products without standards; the totals row
   efficiency is the ratio-of-sums. **Reconciliation**: worst unaccounted kg
   sorts first, bands coloured. CSV export downloads.
7. **Tally Sync (1 min)** — agent status green with a recent poll; queue
   empty or explainable; Retry visible on failed rows.
8. **Absence checks (1 min)** — no Lots/Bags/Day-bin/Handover menus
   anywhere (traceability flag off), and Inventory item search shows Tally
   GUIDs on real items.

Anything that deviates: note the screen + batch number and send it to
Sendhil on the group.
