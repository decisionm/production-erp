# The acceptance chain — the script Phase 8 is walked by

**Status:** Phase 8 · WS-A pass · 2026-08-17 · branch `feat/phase-8-acceptance`
**Rule:** the product is not complete until the CHAIN passes. Module-by-module green is
not the bar. Every assertion below is on the **transaction model** — what the database
and the API say happened — never on a screen.

---

## 0 · The rules this script is run under

| Rule | What it means here |
|---|---|
| **Dev fixtures only** | Every walk runs against the DEV database (`php artisan test`, `RefreshDatabase`, in-memory SQLite locally / MySQL on the CI leg). The LIVE instance is never read from, never written to, and never counted on. |
| **No Tally write** | No walk opens a connection to Tally. The agent leg exercises the ERP's own `/api/v1/tally-sync/entries/{id}/ack` and `/snapshot` endpoints against a test token — the cloud side of the contract, with no Tally at the other end. |
| **The PO→Tally flag stays off** | `TALLY_SYNC_PURCHASE_ORDERS_ENABLED=false` in `phpunit.xml`. Chain B asserts the staged entry and that no live write path is reachable; it never flips the flag. |
| **Never invent a factory value** | Every number in every fixture is an **arbitrary test constant**, chosen so the arithmetic can be checked by hand. None of them is a measurement of anything in SWAASHPET POLYMERS. Fixtures are prefixed `ACC-` so a later reader cannot mistake them for factory data (the PR #128 scar: a *derived* bag weight once reached live). |
| **A missing figure is reported missing** | Where the ERP cannot know something it must answer `null`, not `0`. Where the OWNER has not given something (the CEC layout), the link is recorded **BLOCKED** and no layout is invented. |

### The verdict grammar (used in this document and in PHASE-LOG)

| Word | Meaning |
|---|---|
| **PASS** | Walked, and the assertion held. |
| **FAIL** | Walked, and the assertion did not hold. Any FAIL ⇒ the phase is `NOT READY`. |
| **NOT TESTED** | Not walked in this pass. Any NOT TESTED ⇒ the phase is `NOT READY`. |
| **BLOCKED** | Cannot be walked because a **named owner gate** is open. Result becomes `PASS WITH OWNER-GATED ITEMS`; the product is **not** called complete while a BLOCKED link remains. |

---

## 1 · The chains

```
A · OPERATOR WORKFLOW           ← this document walks it end to end (WS-A)
B · ACCOUNTING TRACEABILITY     ← WS-B writes AccountingChainTest against §4
C · SALES VISIBILITY + DOWNLOADS← WS-C writes SalesVisibilityChainTest against §5
```

**Not in this worktree.** Chains **B2** (store → production material flow, Phase 7.5,
DEC-20260817-001) and **D** (configuration lifecycle, Phase 7.6, DEC-20260817-002) are
written after the integrator rebases this branch onto 7.5/7.6. They are deliberately not
stubbed here — an empty step list would read as a walk that happened.

---

## 2 · How to run the walk

```bash
cd backend
export PATH="/opt/homebrew/bin:$PATH"

php artisan test --compact --do-not-cache-result tests/Feature/Acceptance/OperatorChainTest.php
./vendor/bin/pint tests/Feature/Acceptance
```

The whole acceptance directory, once every workstream has landed:

```bash
php artisan test --compact --do-not-cache-result tests/Feature/Acceptance
```

**`OperatorChainTest.php` has NOT been run on MySQL.** It was written in this pass and no
MySQL is installed on the machine that walked it — the runs recorded below are SQLite only.
The CI MySQL leg's last green run (PR #190) predates this file and says nothing about it.
WS-D's "all suites green on BOTH drivers" criterion is therefore **not** satisfied by this
document: the MySQL leg must execute `tests/Feature/Acceptance` before that box is ticked.

---

## 3 · CHAIN A — the operator workflow

Executed by **`backend/tests/Feature/Acceptance/OperatorChainTest.php`**.

### 3.1 The named fixtures

Masters (all created in `setUp()`, all prefixed `ACC-`):

| Fixture | Name / code | What it is for |
|---|---|---|
| Shift | `ACC Shift A` 06:00–14:00 | the shift the day is read for |
| Shift | `ACC Shift B` 14:00–22:00 | proves a voucher does not over-collect across shifts |
| Machine | `ACC-M1` (seq 1) | has an **approved machine configuration** |
| Machine | `ACC-M2` (seq 2) | has **none** — the product standard governs there |
| Item | `ACC-BTL-1` | the SKU that is configured once |
| Item | `ACC-BTL-GAP` | nothing recorded about it — the falsifier for "asks nothing" |
| Item | `ACC-RES-1` | the consumed material |
| Warehouse | `ACC-FG` / `ACC-RM` | finished-goods and raw-material godowns |
| Downtime reason | `ACC-DT-POWER` | unplanned, reduces runtime, note required |

The **three configuration tiers carry different figures on purpose**, so "the run used
the recorded standard" is distinguishable from "the run fell back":

| Tier | Cycle time | Cavities | Unit weight | Pack |
|---|---|---|---|---|
| machine configuration (`ACC-M1` only) | 12.30 | 4 | 12.0000 g | — |
| product standard (approved) | 16.00 | 3 | 15.0000 g | 500 / box |
| item master | 20.00 | 2 | 18.0000 g | 490 / box |

> These are **arbitrary test constants.** They are not cycle times, cavity counts, bottle
> weights or carton sizes of any real product. Nothing in this table may be quoted as a
> factory value.

### 3.2 The day

Factory day **2026-08-03**, clock frozen at **2026-08-17 10:00 UTC** — so every shift's
end has passed and shift-end is never the variable under test. Settings are the **packaged**
ones: quality stage on, `voucher_granularity = shift`, `release_idle_minutes = 15`,
`factory_timezone` at its default.

| Batch | Shift · machine | State reached | Why it is in the fixture |
|---|---|---|---|
| **ACC-B1** | A · `ACC-M1` | completed → quality-checked → PM → **accountant** | a member of Shift A's voucher |
| **ACC-B2** | A · `ACC-M2` | completed → quality-checked (QC rejects 50) → PM, **no accountant** | must appear in Completed Today and the Shift Summary and be **absent** from the voucher |
| **ACC-B3** | B · `ACC-M1` | completed → checked → PM → accountant | its own voucher; must not contaminate Shift A's |
| **ACC-B4** | A · `ACC-M2` | still running | contributes nothing to any total |
| **ACC-B5** | A · `ACC-M1` | cancelled | never happened |
| **ACC-B6** | 2026-08-02 · A · `ACC-M1` | completed | "today" has to mean today |
| **ACC-B7** | A · `ACC-M1` | completed → checked → PM → **accountant** | the second member of Shift A's voucher, so "exactly the approved entries" must get **inclusion** right, not only exclusion |

Four different accounts sign: supervisor, quality checker, `Plant Manager`, `Accounts`.
Four-eyes is real, not neutralised.

### 3.3 The links, and what each one asserts

| # | Link | Assertion on the transaction model | Verdict |
|---|---|---|---|
| **A1** | SKU configured once → **Shift Floor asks nothing already known** | With `production.readiness.enforced = true`: the preview for `ACC-BTL-1` on `ACC-M1` returns `warnings == []` and `readiness.blocking == []`, quoting 12.30 / 4 (the configuration). On `ACC-M2`, still silent, quoting 16.00 / 3 (the standard). **Falsifier:** `ACC-BTL-GAP` blocks on `cycle_time`, `cavities`, `weight` — the floor *does* ask when nobody recorded the answer. | **PASS** |
| **A2** | **Start Batch** | The started entry freezes `cycle_time_source = configuration` (12.30 / 4 / 12.0000 g) on `ACC-M1` and `product_standard` (16.00 / 3 / 15.0000 g) on `ACC-M2`; neither takes the item master's 20.00 / 2 / 18.0000 g. `calculation_version` is stamped `production_v3_unified` at Start. | **PASS** |
| **A3** | **Complete Batch** — expected · actual · good · reject · packs · downtime · efficiency | 8 h typed, 30 min recorded → `net_running_hours 7.50`, `downtime_minutes_total 30.00`. Expected pieces are **recomputed from the entry's own stamp**: `ProductionCalculationEngine::targetPieces(net hours, frozen CT, frozen cavities, entry.calculation_version)` — and equal the metric (FLOOR(7.5×3600÷12.30)=2195 cycles × 4 = **8,780**). Actual 8,000; packs 16 boxes; expected boxes ROUND(8780÷500)=18; good 96.0000 kg (8,000 × the **configuration's** 12 g); rejection 1.2000 kg (100 × 12 g); efficiency 8000÷8780 = **91.1 %**. | **PASS** |
| **A3b** | the stamp is **load-bearing** | Same run, same frozen standard, same hours; only `calculation_version` is flipped to `production_v2_floor` — and the expected figure **moves**. Without this, A3 would pass even if the code ignored the stamp entirely. | **PASS** |
| **A4** | **Completed Today** | The date's `batch_status=completed` read returns exactly `{B1, B2, B3, B7}` — the running batch, the cancelled batch and yesterday's are absent — and that set is identical to the completed rows the database holds for the date. | **PASS** |
| **A5** | **Shift Summary == completed production** | `ShiftSummaryService::report(shift, date).actual_production_kg` equals Σ `quantity_produced_kg` of that shift's completed entries, read off the rows themselves; the day equals the sum of its shifts. The **quality reduction flows through**: ACC-B2 counted 5,000, QC rejected 50, so the shift is credited 4,950 × 15 g = 74.2500 kg — never the 75.0000 the machine ejected. Shift A total 206.2500 kg (96.0000 + 74.2500 + 36.0000). | **PASS** |
| **A6a** | **CEC == Shift Summary** (composition) | `GET /production/cec?date=…` carries the Shift Summary **verbatim** per shift and for the day, lists exactly the completed batches, and every batch figure it shows (`expected_pieces`, `actual_pieces`, `good_production_kg`, `efficiency_pct`, `calculation_version`, `packs`, `approval_status`) is byte-identical to the Completed Today row it was read from. No arithmetic of its own. | **PASS** |
| **A6b** | **CEC layout** | `CecReportService::FORMAT` is `BLOCKED — SOURCE DOCUMENT REQUIRED`, and the endpoint says so about itself. **No CEC sample exists in the repo and none is invented here.** The golden harness `tests/Feature/Production/CecGoldenTest.php` already owns the sample-presence check and will assert `owner sample == CEC == Shift Summary == completed production` the day a sample lands. | **BLOCKED** — named owner gate: the CEC source document |
| **A7** | **the Tally shift voucher contains exactly the approved entries** | One `Stock Journal` per (date, shift). `SJ-20260803-S{A}` carries `entry_ids == {ACC-B1, ACC-B7}` — **both** accountant-approved entries of the shift (inclusion) **and no others** (exclusion): ACC-B2 is completed and PM-approved but not accountant-approved, so it is visible in A4/A5/A6 and absent here with `tally_sync_entry_id` still null. Shift B's batch sits in its own, different voucher. The produced line is the summed **net** figure 11,000 in the `ACC-FG` godown; the consumed line in `ACC-RM`. The voucher is reached through `shift_production_entries.tally_sync_entry_id`, never a `payload->…` JSON-path predicate, so the walk means the same thing on both drivers. | **PASS** |
| **A8** | **release gate → agent ack → snapshot** | Under the packaged 15-minute idle hold the voucher is **not** offered immediately after its last merge; 16 minutes later `pending()` offers it and stamps `delivered_at`. The agent's `ack` flips the voucher to `synced` and fans out to its member entry only — the non-member is untouched. The agent's `snapshot` upload is stored beside the entry and **moves nothing** on it. No Tally connection is opened at any point. | **PASS** |

### 3.4 Deviations and honest limits of chain A

- **No browser proof was taken.** The Chrome extension was not exercised in this pass;
  every claim above is pinned by `OperatorChainTest.php` against the API and the database.
  Nothing here should be read as evidence about a rendered screen.
- **Traceability (`production.traceability_enabled`) is OFF** for this walk, which is the
  packaged test default. Material reaches the batch as an explicit
  `material_consumptions` line, not through the day bin. The bag/day-bin path is chain
  **B2**'s subject and is walked there — not silently claimed here.
- **MySQL is not exercised at all in this pass** (see §2). Chain A is proven on SQLite only.
- **A5 does not falsify the exclusion of non-completed batches.** ACC-B4 (running) and
  ACC-B5 (cancelled) both carry a null `quantity_produced`, so neither could move
  `actual_production_kg` even if the summary wrongly counted them. That exclusion is
  pinned by `tests/Feature/Production/ShiftSummaryReportTest.php`, not by this walk — A5
  proves the summary equals completed production, not that it rejects a non-completed row
  carrying a quantity.

---

## 4 · CHAIN B — accounting traceability (owned by WS-B)

```
Purchase (PO → GRN → lot) → Inventory (ledger == balance) → Production consumption
[PO→Tally staged only; the live write stays OFF — Q35(d)]
```

Written as `backend/tests/Feature/Acceptance/AccountingChainTest.php`. The conventions of
§0 bind it: `ACC-` fixtures, dev database, arbitrary test constants, `inventory:check-ledger`
green at every step, and the PO→Tally entry asserted **staged with the flag off** — the walk
proves no live write path is reachable, it never flips the flag.

**Chain B2** (Production Request → Store Issue → Bag Scan → Production Receipt → Batch
Consumption → Remaining/Return → reconciliation) is Phase 7.5 and is **not in this
worktree**; it is written after the rebase.

| Link | Verdict |
|---|---|
| every link of chain B | **NOT TESTED in the WS-A pass** — filled in by WS-B |

---

## 5 · CHAIN C — sales visibility and downloads (owned by WS-C)

```
Sales documents traced to their Tally entries (Layer A; Layer B stated)
→ one export per kind from the Center, FC-06 on every file
```

Written as `backend/tests/Feature/Acceptance/SalesVisibilityChainTest.php`. FC-06 binds
every export: a procurement-only reader must receive the **withheld cell**, never a blank —
a blank is indistinguishable from "there was no rate", and the withheld marker is the honest
answer. Both halves of FC-06 apply: purchase rates *and* supplier identity.

**Chain D** (configuration lifecycle: Create → View → Edit → Activate/Deactivate → Safe
Delete → Audit for every applicable master) is Phase 7.6 and is **not in this worktree**.

| Link | Verdict |
|---|---|
| every link of chain C | **NOT TESTED in the WS-A pass** — filled in by WS-C |

---

## 6 · Where the verdict is computed

WS-D computes the phase verdict in `docs/engineering/RELEASE-READINESS.md` and records it
in `PHASE-LOG.md`, applying the rule in §0: any FAIL or NOT TESTED ⇒ `NOT READY`; a link
BLOCKED by a named owner gate ⇒ `PASS WITH OWNER-GATED ITEMS`, and the product is not
called complete while a BLOCKED link remains.

**Chain A carries one BLOCKED link into that computation: A6b, the CEC layout, gated on the
owner's source document.**
