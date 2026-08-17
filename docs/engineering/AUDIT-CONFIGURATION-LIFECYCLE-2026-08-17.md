# The Configuration Lifecycle Contract — audit and design (17-Aug-2026)

P7.6-01. Read-only: nothing was implemented, migrated or committed. Commissioned by the
lead, who formalised the contract product-wide: every applicable master supports
`Create → View → Edit → Activate/Deactivate → Safe Delete → Audit`, with delete refused
in the BACKEND once anything references the record, one shared policy rather than
per-page logic, and duplicate guards. The duplicated warehouses are one test case of it.

## 0 · Four findings that change how the implementation must be run

**1 · The "append-only, no PUT and no DELETE anywhere" sentence is factually false today.**
`routes/api.php:192` says it; the same file carries **25 PUT, 18 PATCH and 4 DELETE**
routes (8 explicit `put`, 1 `patch`, 3 `delete`, plus 17 `apiResource(...)->only([…
'update'])` and one `'destroy'`). The sentence must be corrected, not worked around.

**2 · The contract already exists once, and is already tested.** `RoleService::delete()`
counts `$role->users()` and throws `RoleInUseException::forRole($name, $count)` → 422.
`AuthAndRolesTest` proves both the hard delete of an unused role and the in-use refusal.
This is the model to generalise — not a new idea.

**3 · THE CASCADE ASYMMETRY — the most dangerous fact in this audit.** Soft delete never
fires an FK cascade (it is an UPDATE), which is why nobody has been bitten. **A real hard
delete changes that.** Where an FK is `restrictOnDelete`, the database is a backstop if
the application guard is wrong. Where it is `cascadeOnDelete` there is **no backstop at
all** — the application check is the only thing between a mis-scoped delete and destroyed
factory history:

| Parent | Cascade-side child destroyed silently |
|---|---|
| `employees` | `attendances`, `leave_balances`, `leave_requests`, `salary_structures` — **statutory payroll and attendance history** |
| `items` | `stock_balances`, `production_configurations`, `masterbatch_dosings`, `packing_material_mappings` — **the whole production recipe for a product** |
| `work_centers` | `production_configurations`, `production_downtime_events` |
| `measuring_instruments` | `calibration_records` — a QMS record |
| `spc_characteristics` | `spc_measurements` |
| `downtime_reasons` | `production_downtime_events` (**and this table has no SoftDeletes — no archive to fall back on**) |
| `warehouses` | `stock_balances` |
| `production_standards` | `production_standard_packagings` |

Every one of these is a first-tier delete guard. A cascade-side count > 0 is a REFUSAL,
never a cleanup: the design forbids `forceDelete()`, cascading parent deletes, and any
statement that disables FK checks.

**4 · `spatie/laravel-activitylog` is installed, its table is migrated, and it is used
nowhere.** Zero references in `app/` or `config/`. There is also no `updated_by` column
anywhere in the schema. Audit is the weakest column in the matrix and the cheapest to fix.

## 1 · The matrix (35 configuration entities in scope)

Full per-entity table with file:line evidence is in the audit transcript; the column
totals are the actionable summary. PARTIAL counts as GAP for the Phase 8 gate.

| Column | PASS | PARTIAL | GAP | N/A |
|---|---:|---:|---:|---:|
| Create | 31 | 1 | 0 | 3 |
| Edit | 20 | 1 | **9** | 5 |
| Active/Inactive | 10 | 4 | **11** | 10 |
| Delete-unused | 4 | 0 | **20** | 11 |
| **Dependency guard** | **1** | 0 | **22** | 12 |
| Duplicate guard (code) | 26 | 0 | 3 | 6 |
| **Duplicate guard (name)** | **0** | 0 | **all** | — |
| **Audit** | **1** | 4 | **30** | 0 |
| Tests (full lifecycle) | **1** | 10 | 24 | — |

Two findings inside the matrix matter operationally right now, before any delete exists:

- **Eleven `is_active` / `status` flags are set but filtered nowhere.** Vendor, Customer,
  GLAccount, LeaveType, SalaryComponent, ScrapReason, Routing, SpcCharacteristic, Mold,
  Asset, MeasuringInstrument. **A retired mould and a withdrawn scrap reason are
  selectable on the floor today** — the completion path uses a bare `exists:` rule.
- **Item and Warehouse are unfiltered on eight stock/GRN paths** (stock receipt/issue/
  transfer, goods receipt, maintenance parts, material lots) even though production
  filters them properly elsewhere.

Closing these widens the refusal set on live data, so each gets its own test and its own
line in the PR body (the lesson from the refactor-gate work).

## 2 · The convention change, stated rather than slipped in

The SPIRIT of append-only is intact and stays: **no transaction, ledger row or posted
document may be mutated or removed**, pinned by `MaterialLotCostVersionTest` (PUT/PATCH/
DELETE → 405/404). Nothing here touches it. What changes is a stale sentence and a scope
line.

**Recommendation: `DELETE /api/v1/<module>/<resource>/{id}` for the hard delete, plus
`POST …/{id}/archive` and `POST …/{id}/activate` (each taking a reason).** Reasons:
it copies the one working implementation (`roles`) instead of inventing a second dialect;
a `POST …/delete` would preserve the letter of a sentence that is already false in 47
places, which is exactly the "slipped in" outcome to avoid; the permission middleware
treats DELETE and POST identically so nothing is lost; and archive/activate stay POST
because they carry a reason and are reversible, which a DELETE verb cannot express.

To land in the SAME PR as the first entity, called out in the PR body:
1. Correct `routes/api.php:192` to the scoped truth — transactions and ledgers are
   append-only; configuration masters carry Create/Edit/Archive/Delete-when-unused.
2. Amend the `CLAUDE.md` bullet to name both halves: soft delete for anything with
   history; hard delete only for configuration proven unreferenced, never by cascade.
3. A regression test (T16) pins that the append-only surfaces still answer 405/404.

## 3 · The shared mechanism

`app/Support/Configuration/` — beside the existing shared `app/Support/Tally/
HierarchyUpsert.php`, which is precedent for one mechanism serving two modules.

- `ConfigurationLifecycle` — the ONLY place delete/archive/activate are decided:
  `report()`, `abilities()`, `delete()`, `archive()`, `activate()`.
- `DependencyCheck` — a module declares its own checks declaratively (table+column, a
  callable for non-FK references, or an attribute such as a Tally identity), with
  `cascadeSide()` and `includeTrashed()` markers.
- `ConfigurationInUseException` — one 422 contract, reusing the existing `DomainException`
  renderer, carrying `blocking: [{code, label, count}]` and `alternative: "archive"` so
  the UI can render *"Cannot delete — used by 12 stock movements and 2 production
  batches. Deactivate instead."* from data rather than parsing prose.

Three invariants enforced in that one class: never `forceDelete()` or cascade a parent
delete or disable FK checks; count soft-deleted rows wherever the module says so (a
withdrawn dosing is exactly why a past shift's prefill is explainable); and re-run the
dependency report **inside the transaction under `lockForUpdate()`**, so a button and its
refusal cannot disagree under concurrency.

`can {edit, activate, archive, delete}` goes on every configuration resource, exactly as
`PurchaseOrderResource` already does. Honest N+1 note: a full sweep is 8–30 COUNTs per
row, so `show` and the actions return an authoritative `can`, while `index` returns the
cheap flags with `delete: null` meaning *undetermined — ask*, and the confirm modal
fetches `show` first.

Frontend: there is nothing to reuse today (five ad-hoc confirm dialogs across 70+ pages,
and two duplicated error handlers that discard field keys). Add
`components/configuration/`: one hook, one row-actions component that READS `can` and
never re-derives it, one delete modal that renders `blocking` with counts and offers
Archive, and one status tag replacing ~20 hand-rolled variants.

Permissions reuse `<module>.manage`; a separate `.delete` tier would change the
permission catalogue's shape and is recorded as a question, not built.

## 4 · Priority — what must close before Phase 8 can say PRODUCT READY

**Tier 0, the mechanism:** `app/Support/Configuration/*`, the 422 contract, the `can`
block, the shared frontend component, tests T16 (append-only unchanged) and T17 (the
error contract itself), and the two convention corrections.

**Tier 1, what the factory touches daily** (full row green): Item · Warehouse ·
WorkCenter · Shift · ScrapReason · Mold · ProductionStandard (+Packaging) ·
ProductionConfiguration · DowntimeReason · Employee. Plus two structural prerequisites:
extract Services for `FactorySettingController` and `DowntimeReasonController` (neither
can take the shared hook as written), and add `deleted_at` to `downtime_reasons`.

**Tier 2, before release:** Vendor · Customer · GLAccount · LeaveType · SalaryComponent ·
Asset · MaintenanceSchedule · MeasuringInstrument · SpcCharacteristic · Bom · Routing ·
GstRate · GstRegistration · Role (extend its test to assert the count) · User.

**Tier 3, after release or never:** the Tally-owned masters (ItemGroup, Ledger,
LedgerGroup — their only real gap is that none has a screen), the settings-style records
where Delete is N/A by design, and agent tokens (delete works; only a test is missing).

**Two cheap cross-cutting wins, early:** turn on activitylog (closes the entire Audit
column — the package is already installed and idle), and sweep the eight unfiltered
`exists:` rules for Item and Warehouse.

## 5 · What needs the owner

Already recorded, to be consumed not re-asked: **Q43** (duplicate names — block or warn?
also owns any move from global to active-only code uniqueness) and **Q51** (how many
stores, and may historical rows move to consolidate them?).

New, recorded as **Q52**: may configuration ever be hard-deleted at all, or is Archive
always the answer; should an archived master keep occupying its business code; who may
delete as opposed to edit; does archiving in the ERP mean anything Tally-side; and the
`MaintenanceSchedule` linkage that makes "was this ever used?" currently unanswerable.

**Merging stays out of scope.** Nothing in this design merges anything; any future merge
is a separate owner-gated act with a dry run.
