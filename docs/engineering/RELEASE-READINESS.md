# Release readiness — Phase 8 verdict (rev 2, 18-Aug-2026)

> **Rev 2 supersedes the 17-Aug verdict below in three places.** D-WIRING has landed, the
> suite has been run on MySQL 8.0, and a browser walk has been taken. The three items that
> were open for engineering reasons are closed; what remains open is owner-gated or
> explicitly recorded as a gap. **The ceiling remains `PASS WITH OWNER-GATED ITEMS` — never
> unconditional PRODUCT READY — while the CEC format and the first PO→Tally live write
> stand.** See "Rev 2 — what changed" at the end.

## VERDICT (17-Aug, superseded in part): **NOT READY**

Not because something is broken. Every one of the five acceptance chains passes, the full
backend suite is green at **1,904 tests / 1,903 passed / 1 skipped by design / 17,806
assertions**, Pint is clean, the frontend typechecks, tests and builds, and
`inventory:check-ledger` reports clean. The verdict is NOT READY because **one link of one
chain is NOT TESTED**, and the rule this programme set for itself says that is enough,
full stop:

> Any link FAIL or NOT TESTED → `NOT READY`, full stop. A link BLOCKED by a named owner
> gate is recorded BLOCKED and the result is `PASS WITH OWNER-GATED ITEMS` — the product
> is **not called complete** while a BLOCKED link remains.

## The link that is NOT TESTED

**D-WIRING — the configuration lifecycle contract applied to the in-scope masters.**

Phase 7.6 shipped the *mechanism* and deliberately wired no entity to a route. The
independent QA confirmed it by inspection: no module declares
`ManagesConfigurationLifecycle`, and the only lifecycle-shaped route in the application
(production configuration deactivate) bypasses the shared mechanism entirely. Chain D
therefore walks the contract against `warehouses` through routes that predate Phase 7.6 —
which proves the mechanism works, and proves nothing about the 35 in-scope masters.

This independently confirms both constraints the lead set: Phase 7.6 wired no entity, and
a material configuration gap remains. Neither is overridden by every individual test file
being green.

## What the audit still shows open

From `AUDIT-CONFIGURATION-LIFECYCLE-2026-08-17.md`, unchanged by Tier 0 because Tier 0 was
the mechanism: **dependency guard 1 PASS / 22 GAP · audit trail 1 PASS / 30 GAP ·
delete-unused 4 PASS / 20 GAP.** Tier 0 closed the live-facing flag half and built the
guard; it did not close these rows.

## The two owner gates, correctly recorded BLOCKED

| Link | Gate |
|---|---|
| A6b · the CEC layout/format | No source document exists. The composition is proved equal to the Shift Summary; the FORMAT stays BLOCKED until the owner supplies a sample. Never invented. |
| B8 · the PO → Tally live write | Q35(d). The flag is off, the staging is proved, and no egress path is reachable. The first live post is attended, never unattended. |

**Even with chain D walked and green, the best achievable state is `PASS WITH OWNER-GATED
ITEMS`, not unconditional PRODUCT READY**, while those two remain.

## What would change the verdict

1. ~~**Wire the Tier-1 masters through the contract**~~ — **DONE** (`55ab682`). Eleven
   masters wired: Warehouse, Item, WorkCenter, Mold, Shift, ScrapReason, DowntimeReason,
   ProductionStandard, ProductionStandardPackaging, ProductionConfiguration, Employee, plus
   the `configuration-delete` permission tier. Chain D re-walked through those entities'
   OWN routes.
2. **Close the audit's GAP rows** for the masters the factory touches daily. This is the
   material configuration gap the lead named as a Phase 8 blocker.
3. ~~**Run the acceptance chains on the MySQL leg.**~~ — **DONE.** A MySQL 8.0 container was
   stood up locally (matching CI's service image) and the WHOLE backend suite run against
   it: **2,113 tests · 2,111 passed · 1 skipped by design · 1 error** (that run predates the last three commits; the tip figure is in the checklist below). The single error was
   in the new D-WIRING work and was a real driver divergence — `MoldLifecycleTest` seeded a
   bare `'08:00'` into a `dateTime` column, which sqlite stores and MySQL rejects. Fixed in
   `fa35b83`; 13/13 on both drivers afterwards. This is the third time the MySQL leg has
   caught something sqlite hid, and the first time it caught it *before* CI did.
4. ~~**Take the browser proof.**~~ — **DONE, with a stated method limit.** Twenty screens
   walked against the phase-8 build served by Laravel on its own port (the production
   topology). Full record: `E2E-BROWSER-WALK-2026-08-18.md`. It is genuine browser evidence
   — real React handlers, real requests, real server responses — but it is **not
   mouse-level** evidence: the driven tab is a background tab, so Ant Design's modal
   animations are throttled and interactions had to be dispatched in page context. Overlay
   and z-index defects therefore remain UNTESTED.
5. Then the two owner gates above, which only the owner can lift.

## What IS proven

- **Chain A — the operator workflow**, end to end: configuration answers every Shift Floor
  question; the standard is frozen at Start under its own `calculation_version` and that
  stamp is load-bearing; Completed Today equals the completed batches and excludes
  running, cancelled and yesterday's; Shift Summary equals Σ completed production with the
  QC reduction flowing through; the CEC composition is byte-identical to the Shift Summary;
  the Tally shift voucher contains exactly the approved entries — proved by inclusion AND
  exclusion; release gate → agent ack → snapshot.
- **Chain B — accounting traceability**: PO → GRN → lot → movement → balance →
  consumption, traced backward to every GRN line, with FC-06 holding on every link in both
  halves (rates AND supplier identity, absence rather than null).
- **Chain B2 — the material flow**: the three states never collapse, proved by movement
  census rather than by a total — the store's outflows are exactly three
  `issue_to_production` rows and **zero** consumption rows, so it cannot be charged twice
  for the same material. Mutation-checked: forcing the consumption onto the store kills the
  chain twice over. An open issue reads as **no drift** in the reconciliation.
- **Chain C — sales visibility and downloads**: documents traced to their Tally rows, the
  Layer-B honesty statement stated plainly, one export per kind, FC-06 on every file, and
  the CEC slot catalogued BLOCKED with its reason rather than producing a file.
- **Chain D — the mechanism**: a referenced master's delete refused with integer counts and
  its cascade-side children surviving; a genuinely unused record really deleted and its code
  re-released; the fail-closed verdicts; the schema backstop refusing an incomplete
  declaration.

## Release-readiness checklist

| Item | State |
|---|---|
| No P0 | ✅ none open |
| No unresolved P1 data-integrity issue | ✅ every P1 raised by a gate was fixed and re-gated |
| Migrations accounted for and reversible | ✅ each phase's listed in DEPLOYMENT-RUNBOOK |
| All suites green | ✅ at the branch tip: **2,051 tests / 2,050 passed / 1 skipped by design / 18,668 assertions** on sqlite; MySQL 8.0 run at 2,113/2,111 before the last three commits, and CI's app-mysql leg re-runs it on push |
| Production build passes | ✅ |
| Browser smoke | ⚠️ **performed** (20 screens, `E2E-BROWSER-WALK-2026-08-18.md`) — genuine but **not mouse-level**; overlay/z-index class untested |
| Sync dry-run | ✅ reconciliation dry run exercised in chain B2 |
| Rollback documented | ✅ DEPLOYMENT-RUNBOOK |
| MySQL leg on the acceptance chains | ✅ whole suite on MySQL 8.0 — 2,111 passed / 1 skipped; the 1 error it found is fixed (`fa35b83`) |
| `DEVELOPMENT-PLAN.md` current or superseded | ⚠️ superseded in practice by MASTER-PLAN rev 3; say so explicitly before release |

Nothing in this programme has been merged. Live remains at `9a9cbe3`.


---

## Rev 2 — what changed on the night of 17→18 Aug

### Closed
- **D-WIRING** (`55ab682`) — the NOT TESTED link. Eleven masters, chain D re-walked through
  their own routes, and **proved in a browser**: deleting `RM Store` is refused with
  `Cannot delete warehouse "RM Store" — used by 3 stock balances, 3 stock movements, 2
  material bags and 1 Tally godown identity. Deactivate instead.`, itemised with counts;
  while a genuinely unused warehouse created through the UI was really hard-deleted (row
  gone from the table, `deleted_at` not set).
- **The MySQL leg** — see item 3 above.
- **The browser walk** — see item 4 above.

### Found and fixed
- **The local fixture granted the floor three tiers live withholds** (`7e37261`). The
  acceptance fixture gave its supervisor desk every permission except `carton-trace.*`,
  which meant it held `finance.view` — and `AgentIdentity::mayReadPurchaseDetails()` opens
  **FC-06** to exactly that. A supervisor login read a Procurement Receipt Note's supplier
  in the clear. The product gate was never broken; the fixture made every manual FC-06
  walkthrough pass for the wrong reason. Now withheld and pinned by a test that asserts
  through the product's own predicates rather than permission names.

### Recorded as GAPS — deliberately not fixed unattended
- **Duplicate business codes are not normalized in code.** All 17+ masters use a bare
  `unique:<table>,<column>`; there is no shared normalized-unique rule. Measured on both
  drivers: MySQL (live) **catches** a case-variant duplicate, sqlite does **not**; neither
  catches leading/trailing whitespace. So the contract's "normalized comparison" holds on
  live only by accident of collation. **Not fixed tonight on purpose:** a case-insensitive
  unique rule would begin refusing EDITS to any live master pair that already differs only
  by case, making those records uneditable, and whether live holds such pairs is a
  read-only query nobody has run. The separate "warn on likely duplicate NAMES" requirement
  is **UNTESTED**, not passing.
- **The Day Bin surface outlives the decision.** `/production/day-bin` and the dashboard's
  `DAY BIN` tile still exist, and `UpdateDayBinWarehouseRequest` plus the day-bin setting
  are still wired — so per the standing instruction, it IS still relied upon and was not
  blindly deleted. But the copy contradicts DEC-20260817-001 ("There is no Day Bin") and
  should not reach the floor in that state.
- **The mould master was not exercised in the browser** — the fixture seeds no moulds. Its
  backend lifecycle is covered by `MoldLifecycleTest` on both drivers.
- **Duplicate-posting / retry was not exercised in the browser** — the fixture contains no
  failed or retryable Tally entry to retry. Covered by the backend contract suite only.

### The gate on the D-WIRING delta

Two independent reviewers, both on the delta rather than on the whole branch.

**Sonnet independent QA — PASS WITH DEFERRED.** Wrote its own route-level tests for all
eleven masters, deliberately sharing no harness with the builder's, and reproduced every
claim: referenced-delete refused with an integer `blocking[].count` and
`alternative: 'archive'`; unused-delete succeeding and freeing the business code;
archive→activate round-tripping (including the two BackedEnum-status masters and the two
with no active flag at all, whose archive is a real soft delete); the audit row actually
written to `activity_log` with the right subject and causer; and the hard-delete tier
refusing a module manager who lacks it while still allowing archive — checked across BOTH
permission-check implementations and across WorkCenter's split permission groups.

**Opus adversarial safety review — FAIL, now fixed.** Migrated all 175 migrations into a
scratch database and diffed all 277 foreign keys against what the eleven services declare.
Its headline negative is worth recording: **zero uncovered foreign-key columns** across all
91 inbound FK columns, for every delete rule, not just CASCADE. The hole it found was one
level down — whether the covering check can SEE a soft-deleted child — and it is fixed in
`3df98bb`, together with the structural gap that let it through.

Its remaining P2s are recorded, not fixed: two authority classes name the same permission
string (`HardDeleteAuthority` and `ConfigurationDeleteTier`) and should be collapsed now
that the catalogue entry has landed; `activity_log` rows are orphaned by a hard delete
(nothing declares them, and id reuse is theoretically possible); the TOCTOU lock closes the
race for every FK-constrained child but not for the settings keys, the colour map, the
config snapshot or the Tally link; and six masters bind implicitly, so a trashed row 404s
where the mechanism documents that it should be found.

### P1 — Archive does not yet stop NEW work for Item and WorkCenter

`StartBatchRequest` scopes `shift_id` and `warehouse_id` with
`Rule::exists(...)->where('is_active', true)` but leaves `work_center_id` and `item_id` as
bare `exists:`. The readiness gate that would otherwise catch it ships in watch-only mode —
`PROD_READINESS_ENFORCED` defaults to false — so `item_active` and `machine_active` are
downgraded from blocking findings to warnings. A batch naming an archived item or a retired
machine is therefore ACCEPTED today.

**Severity is bounded by the picker, and this is the sentence that matters:** the Shift
Floor calls `listWorkCenters(true)`, so a supervisor cannot select a retired machine from
the dropdown. The gap is reachable by direct API call, or by a client holding a stale list —
not from the screen.

**Deliberately not fixed unattended.** Adding `is_active` scoping to those two rules would
begin REFUSING batch starts, and refusing a batch start stops the floor; there is a night
shift on it as this is written, and whether live holds an inactive machine or item that is
still in use is a read-only query nobody has run. It is also not really a new defect: making
archived-exclusion bite is the **readiness-gate rollout decision from 29 July**, which is the
owner's call and is already recorded as open. The two-line fix is named above for whenever
that call is made.

Until then, the Archive confirmation's wording — "stops being offered for new work" — is
accurate for the other nine masters and **aspirational for Item and WorkCenter**.
