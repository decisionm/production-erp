# Phase 0 — Current-state audit
Configurable factory production · 29 Jul 2026
Branch `feat/configurable-production-architecture` (from `ae808fd`)

Repository evidence only. Where the workbook and the code disagree, both
are quoted rather than reconciled by assumption.

---

## 0. The finding that governs the whole release

**The master workbook contains ZERO approved production configurations.**

| Sheet | Rows | Approved |
|---|---|---|
| Machine Product Map | 11 candidates | **0** — every row `To Confirm` / `Approval Required` |
| Packing Master | 11 candidates | **0** — every row `To Confirm` |
| Material Recipe | 11 candidates | **0** — every row `To Confirm`, resin/MB item codes blank |
| Downtime Reasons | 10 reasons | **0** — every row `To Confirm` |
| Machine Master | 10 machines | M1–M5, M10 `Discussion Confirmed`; M6–M9 `To Confirm` |

The brief's rule 5 is explicit: *"All workbook rows marked `To Confirm`
must remain non-production-ready."*

**Consequence to state plainly before any code is written:** building the
entire configuration domain will not, by itself, let a single product
start under an approved configuration. The domain is the machinery for
capturing Vincent's answers; the answers do not exist yet. Every one of
the 11 candidate mappings imports as **draft**.

This is not an argument against building it — the configuration UI is
precisely what turns the Onsite Checklist into data. It is an argument
against expecting the factory to run on it tomorrow.

### Cavity limits do not exist anywhere

Read with explicit column alignment, `Machine Master` columns
`Minimum Cavities`, `Maximum Cavities`, `Allowed Cavity Set` and
`Max Supported Cavities` are **null for all ten machines**. The `8` and
`14` visible on each row are `Cycle Time Min (s)` / `Cycle Time Max (s)`.

So the brief's requirement that a configuration store "permitted cavity
choices and machine min/max limits" has **no source data at all**. The
columns must be built and left empty. Machine 10's "around 6/7/8
cavities" is described in prose in the brief and is explicitly pending —
it must not be seeded.

---

## 1. What already exists and is directly reusable

Shipped today in `ae808fd` (PR #39), live, watch-only:

| Capability | Where | Reuse verdict |
|---|---|---|
| Readiness gate, 11 checks, configurable severity | `ProductReadinessService` | **Extend, don't replace.** Its check/severity/finding shape is exactly what the new "precise readiness error listing missing fields" (rule 6) needs. Add checks for mapping-approved, recipe-approved, mould, colour. |
| Estimation engine | `BatchEstimationService` | **Reuse — its core formula already matches the new contract.** It computes `floor(hours×3600÷CT)×cavities`, which is the brief's full-shift target verbatim. |
| Duplicate-start behaviour | `MachineBusyException` | **Already satisfies** the brief's line 347: returns batch number, product, shift, date rather than a generic state error. |
| Voucher preview | `VoucherPreviewService` | **Already satisfies** rule 10 (read-only journal preview, no posting). |
| Immutable standards snapshot | `startBatch()` snapshots `standard_cycle_time` / `standard_cavities` onto the entry | Precedent for the required §8 batch snapshot — extend to configuration id/version, unit weight, packing, recipe, planned downtime. |
| Approval chain, 3 desks | `ShiftProductionEntryService` | Keep. Add the new metrics to the approval payload. |
| Effective-dated + approval-status pattern | `Bom` (`is_active`, `version`, supersede-on-create in `BomService::create`) | Closest existing precedent for §4 effective-dated configurations, but it has **no date columns** — see §3. |
| Module/service/request/resource layout, `DomainException`→422 + `errorCode()` + `payload()` | `app/Modules/*`, `bootstrap/app.php` | The `payload()` hook added yesterday is how field-level readiness errors already reach the client. |

**Formula fixture check.** The brief's acceptance fixture full-shift
target — 8 h, CT 12 s, 5 cavities → 12,000 pieces — is *already* produced
by the shipped engine and was verified live this afternoon
(`expected_cycles 2400 · expected_pieces 12000 · expected_kg 378`).

---

## 2. Contract conflict — two different "expected pieces" formulas

This is the most important engineering conflict and it must be decided
before implementation, not during.

| | Formula | Rounding | Pinned by |
|---|---|---|---|
| `ShiftProductionEntryService::productionMetrics()` | `3600 × cavities × hours ÷ CT` — **no floor** | `expected_boxes` = **half-up ROUND** | `ExpectedOutputEngineTest`, asserting WB2 cell-for-cell (`13584.91`, `16` boxes) |
| `BatchEstimationService::estimate()` | `floor(hours × 3600 ÷ CT) × cavities` | containers per `production.packing_rounding` (ceil) | `ControlledProductEndToEndTest` |
| **New master workbook** | `FLOOR(...)` — matches BatchEstimationService | "explicit rounding rules from configuration" | `Calculation Example` |

They disagree. CT 10.6, 5 cavities, 8 h: unfloored `13584.91` vs floored
`13580`. Small, but it propagates into efficiency and every downstream
percentage.

The brief says *"Do not duplicate independent formulas in multiple
screens"* and asks for **one** authoritative engine. But
`productionMetrics()` is deliberately pinned to the older WB2 workbook so
it reconciles with the sheet Vincent already uses, and its tests assert
that cell-for-cell.

**Recommended resolution (needs a decision, not an assumption):** build
the one authoritative engine on the FLOOR contract, and keep
`productionMetrics()` alive as an explicitly-named legacy reconciliation
view until Vincent confirms the new workbook supersedes WB2. Silently
switching it would change historical figures on already-approved entries
— the same "unexplained moving number" risk flagged this morning.

---

## 3. What is missing entirely

No table, model or migration exists for any of these:

| Brief §| Concept | Nearest existing thing |
|---|---|---|
| 1 | Typed factory settings (key/type/scope/effective date/changed-by/reason) | `config/production.php` — **file-based, needs a deploy to change**, which is exactly what the brief forbids |
| 2 | Machine capabilities (min/max/permitted cavity set, CT bounds, capacity class) | `work_centers` has only `code`, `name`, `display_sequence`, `capacity_hours_per_day`, `is_active` |
| 3 | Product variant / mould / colour as separate identities | `items.colour` is a plain string; `molds` + `mold_change_logs` exist but are not part of any configuration key |
| 4 | **Machine–product configuration** (the controlling record) | **nothing** — CT/cavities live on `items` as universal product fields, which rule 2 explicitly rejects |
| 5 | Packing profiles with mode (Loose/Tray/Pouch) | `items.nos_per_tray/trays_per_box/nos_per_box/nos_per_pouch/pouches_per_box` — flat columns on the item, no mode, no profile entity |
| 6 | Recipe components with role + calculation basis (kg/kg, % of polymer, per-1000, per-box) | `boms` + `bom_lines` have only `quantity_per`; no role, no basis, no loss %, no effective dates, no approval |
| 7 | Downtime reason config + planned-before-start events | `machine_downtime_logs` exists but has **no reason master and no planned/unplanned distinction**; `mold_change_logs` has no type column |
| 8 | Full batch configuration snapshot | only `standard_cycle_time` + `standard_cavities` are snapshotted |
| — | Planned downtime affecting the estimate | not modelled at all |
| — | Actual entry by tray/pouch/piece basis with conversion | completion takes `quantity_produced` + box/tray counts, no basis selector, no conversion audit |
| — | Override reason + actor on CT/cavities | `active_cavities` is editable with **no reason capture** |
| — | Import/dry-run | nothing |

---

## 4. Migrations and data affected

- **120 migrations** applied; the shipped readiness gate added **zero** —
  it is pure code + config, which is why it deployed with no DB risk.
- Everything in §3 is **additive**: new tables plus nullable FKs on
  `shift_production_entries` pointing at the new configuration snapshot.
- **No destructive change is required or acceptable.** `items.standard_*`
  and the packing columns stay where they are and keep working for
  unmigrated records — the brief's "keep existing working paths
  functional for unmigrated records, but clearly mark them as
  legacy/unconfigured" is satisfied by resolution order: approved
  configuration first, item master second (flagged legacy).
- Live production rows: unknown count, but every existing
  `shift_production_entry` predates configurations and must keep
  rendering. Backfill writes a synthetic "legacy/unconfigured" marker,
  never a guessed configuration.

---

## 5. Safest additive path

1. Settings + machine capabilities + downtime reason master — pure new
   tables, nothing reads them yet.
2. Product variant / mould / colour / packing profile / recipe component
   — new tables; resolution still falls back to the item master.
3. Machine–product configuration with effective dates and an exclusion
   constraint against overlapping active rows for the same key.
4. One calculation engine on the FLOOR contract + typed contract shared
   with the SPA; `BatchEstimationService` is its seed.
5. Snapshot expansion at `startBatch()`.
6. Readiness gate gains configuration-aware checks — severity
   configurable exactly as now, so nothing starts blocking on day one.
7. Import with dry-run; the 11 workbook candidates land as **drafts**.

Rollback: every step is additive and flag-gated; reverting the merge
leaves orphan tables that nothing reads. No down-migrations needed.

---

## 6. Decisions required before implementation

1. **The formula conflict (§2)** — one engine on FLOOR, with
   `productionMetrics()` kept as a named legacy view? Or migrate WB2's
   tests to FLOOR and accept that already-approved entries' displayed
   figures shift?
2. **Scope.** The brief is 9 configuration screens, 8 new domain areas,
   a rewritten 3-step Start flow, a rewritten Complete flow, an import
   pipeline and 17 categories of QA. That is not one day's work at the
   quality bar the last PR was held to. Recommended cut for the first
   PR: settings + machine capabilities + machine-product configuration +
   downtime reasons + the one calculation engine, with the existing
   Start Batch reading configurations when present. Packing profiles,
   recipe components, import dry-run and the full 3-step redesign follow.
3. **Nothing can be marked approved by me.** Every `To Confirm` row
   imports as draft, and the factory cannot run on approved
   configurations until Vincent signs the Onsite Checklist.
