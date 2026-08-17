# Warehouse master — audit before remediation (17-Aug-2026)

Read-only. Nothing was merged, deleted, deactivated or altered. Commissioned by the
lead: *"Do not merge or delete anything yet … Do not alter live stock or historical
transactions as part of this audit."*

**Data caveat, first and loudest.** Every count below comes from the **DEV** sqlite
database on the build machine, seeded locally on 2026-08-02. It is a rehearsal
database. **It is not the live factory's data and no count here may be read as a live
count.** The live instance was not touched. What the live answer would take is named
in §5.

## 1 · The five rows, and where each came from

| id | code | name | tally_guid | created | by |
|---|---|---|---|---|---|
| 1 | RM-STORE | Raw Material Store | — | 2026-08-02 | `BottleManufacturingDemoSeeder` |
| 2 | WIP | Work In Progress | — | 2026-08-02 | `BottleManufacturingDemoSeeder` |
| 3 | FG-STORE | Finished Goods Store | — | 2026-08-02 | `BottleManufacturingDemoSeeder` |
| 4 | RM | RM Store | `gd-rm-fixture` | 2026-08-02 | `AcceptanceFixtureSeeder` |
| 5 | FG | FG Store | `gd-fg-fixture` | 2026-08-02 | `AcceptanceFixtureSeeder` |

Introducing commits: the table itself `0822573` (18-Jul); `RM-STORE`/`WIP`/`FG-STORE`
in `bb87ecd` (19-Jul, the demo-data seeder); `RM`/`FG` ten days later in `5a3b0e9`
(29-Jul), whose seeder comments them as *"Godowns that exist in Tally — the readiness
gate refuses anything else."* A later migration `3b75bbd` (01-Aug, *"Stop asking the
floor which store — the factory is one place"*) deactivates exactly the three demo
codes.

**All five predate this autonomous engineering program** (whose commits begin
16-Aug). The only program-era commits that mention these codes create them inside
PHPUnit `setUp()` bodies against an in-memory database. **This program has never
created a warehouse row in any real database.**

Why the three demo rows are nonetheless active in the dev DB: the deactivating
migration ran on an empty table during `migrate --seed`, and the seeder re-created
them afterwards. That is a dev-DB artefact and says nothing about live.

## 2 · What references what (DEV DB ONLY)

Every column in the schema whose name contains `warehouse`/`godown` was enumerated.

| reference | RM-STORE | WIP | FG-STORE | RM | **FG** |
|---|---|---|---|---|---|
| stock_balances rows / non-zero | 6 / 0 | 9 / 7 | 2 / 2 | 3 / 3 | **0 / 0** |
| stock_movements | 12 | 19 | 3 | 3 | **0** |
| goods_receipt_notes (+ lines) | 3 (4) | 0 | 0 | 0 | **0** |
| material_bags | 0 | 0 | 0 | 2 | **0** |
| deliveries | 0 | 0 | 1 | 0 | **0** |
| work_orders | 0 | 3 | 0 | 0 | **0** |

Empty everywhere: `shift_material_consumptions`, `shift_production_entries`,
`serial_numbers`, `rework_orders`, `subcontract_orders`,
`maintenance_work_order_parts`; `app_settings` holds no row, so no setting names a
warehouse in this DB.

**Only `FG` (id 5) is referenced nowhere and holds no stock.**

## 3 · Duplicate, or two real places?

**Accidental duplicates — high confidence, and already known.** The archived
`GO-LIVE-PLAN.md` names it: *"Warehouse duplication — seeded warehouses overlap Tally
godowns (RM-STORE vs 'RM Store', FG-STORE vs 'FG Store'). Consolidate after go-live."*
`SHIFT-REDESIGN-DELIVERY.md` calls the seeded three *"voucher-fatal."* The provenance
agrees: one demo seeder, one acceptance-fixture seeder, ten days and two authors
apart. No importer name-matched them into existence.

**The awkward part:** the transactional history sits on the DEMO rows, while the
Tally-linked identity sits on the fixture rows. That inversion is what makes this a
*merge* problem rather than a *delete* problem — and merging rewrites historical
`warehouse_id`s, which is an owner gate, not an engineering decision.

## 4 · A live-relevant defect this audit surfaced

`TallyGodownResolver::soleLinkedWarehouse()` resolves a godown by falling back to
*the sole* warehouse carrying a `tally_guid`. **Two rows carry one** (`RM` and `FG`),
so that fallback returns null and the rule is effectively dead wherever it is relied
on. This is a consequence of the duplicate pair, not a cosmetic tidiness issue.

## 5 · What the code has today, and what a safe lifecycle needs

Today: `warehouses` exposes only `index`, `store`, `update` — **no delete, no
deactivate endpoint**. `is_active` is honoured by the pickers
(`UpdateFactoryWarehousesRequest`, `StartBatchRequest`, `FactoryWarehouseResolver`)
but NOT by `WarehouseService::paginate()`, so inactive rows still appear on the master
list. `code` is globally unique (index + FormRequest), including soft-deleted rows;
**`name` is not constrained at all**, which is exactly how "FG Store" and "Finished
Goods Store" coexist.

The lifecycle to build (design only — see Phase 7.6, of which this is one row):
delete permitted only when every reference in §2 is zero AND stock is zero AND the row
has no children, no `tally_guid`, and is named by no setting; otherwise refused with
the counts, offering deactivate. Duplicate prevention should move from "globally
unique code" to "no second ACTIVE row with this code" (so a retired code can be
reused), and duplicate NAMES should warn rather than block.

**What would settle FG-vs-FG-STORE for real:** the live instance's own `warehouses`
rows with `tally_guid`/`tally_company` populated, plus the owner confirming how many
godowns Tally actually holds. Until then the question is recorded, not answered.
