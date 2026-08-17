# Phase 8 — browser E2E on DEV (18 Aug 2026, 01:50–02:30 IST)

## How this was run, and what it does and does not prove

- Target: the **phase-8 worktree's own code** (`feat/phase-8-acceptance` @ 7e37261), built with
  `npm run build` into `backend/public/build` and served by Laravel on `127.0.0.1:8010` — the
  production topology (one Laravel serving the built SPA), not the Vite dev server.
- Database: a throwaway sqlite dev DB, `migrate:fresh --seed` + `AcceptanceFixtureSeeder`.
- Logins: the fixture desks (`pm@`, `supervisor@`), password from the LOCAL-ONLY seeder constant.

**Method caveat, stated so nothing here is over-claimed.** The Chrome tab the automation drives
reports `document.visibilityState === "hidden"`. A background tab has its CSS animations throttled,
so Ant Design modals never finish their enter transition and sit at `opacity: 0` — invisible to a
screenshot, and covering the page with an invisible mask that swallows coordinate clicks. This is a
TOOLING condition, not a product defect: the same modal opens correctly when the click is
dispatched in page context. Two consequences:

1. Interactions below were driven by `element.click()` in page context. React handlers, the network
   requests, and the server responses are all **real** — this is genuine browser evidence.
2. It is **not mouse-level evidence.** It cannot catch an overlay, a z-index defect, or an element
   that is visually unreachable. Anything of that class remains UNTESTED.

A visible tab was attempted once (`tabs_create_mcp`); the new tab was also hidden, so the method
above stands.

## Results

| # | What | Result | Evidence |
|---|---|---|---|
| 1 | Login / authorization | **PASS** | `pm@example.com` → dashboard renders, full nav, session cookie honoured |
| 2 | Warehouses (config master) | **PASS** | Edit · Reactivate (disabled, all rows Active) · Archive · Delete — where the pre-D-WIRING baseline had **Edit only** |
| 3 | Delete **refused** with counts | **PASS** | RM Store → `Cannot delete warehouse "RM Store" — used by 3 stock balances, 3 stock movements, 2 material bags and 1 Tally godown identity. Deactivate instead.` plus the itemised list. This is the contract's required shape, verbatim |
| 4 | Delete **allowed** when unused | **PASS** | A throwaway warehouse created via the UI was hard-deleted; row gone from `warehouses` entirely (`deleted_at` not set — a true hard delete, confirmed in the DB) |
| 5 | Create (config master) | **PASS** | New Warehouse modal → row appears |
| 6 | Duplicate business code | **GAP, driver-dependent** | see below |
| 7 | Items / Product & SKU | **PASS** | 18 rows; Details · Barcode · Edit · Reactivate · Archive · Delete |
| 8 | Machine master | **PASS** | `/production/work-centers` → `/production/configuration`, 11 rows, full lifecycle actions |
| 9 | Mould master | **NOT EXERCISED** | page + Actions column render, but the fixture seeds **no moulds** ("No data"). Backend covered by the D-WIRING suite; the browser walk did not exercise it |
| 10 | Shift Floor | **PASS** | 10 machines, shift rail (A/Morning/B/Afternoon/C/Night), Report Down + Mold Change per machine |
| 11 | Material Requests | **PASS** | renders; states the rule on screen (below) |
| 12 | Store Issue Queue | **PASS** | renders, same rule stated |
| 13 | Store → Production/WIP model | **PASS** | Stock page lists `RM-STORE — Raw Material Store` and `WIP — Work In Progress` as **separate locations** with separate balances (e.g. PET-RESIN: RM-STORE 0.0000, WIP 860.0000) |
| 14 | Shift Summary | **PASS** | supervisor inputs + computed KPI block; honest note that only Target Production and Power Consumption are hand-entered |
| 15 | Purchase Orders / GRN | **PASS** | 4 POs; Tally column reads `Not sent to Tally — PO posting is disabled (owner gate Q35)` |
| 16 | Tally Sync Control Center | **PASS** | `No failed vouchers`, `4 vouchers still waiting for the agent to collect`, honest agent-dependency note |
| 17 | Sales visibility | **PASS** | honesty panel (DEC-20260809-003) + `Sales voucher builder: unvalidated, no GST` warning |
| 18 | Downloads / exports | **PASS** | catalogue renders with per-kind row caps and filter fields |
| 19 | **FC-06** | **PASS after a fixture fix** | see below |
| 20 | Duplicate posting / retry regression | **NOT EXERCISED in the browser** | no failed/retryable entry exists in the fixture to retry. Covered by the backend contract suite only |

## The two findings

### FC-06 — the gate is correct; the FIXTURE made the walk a false pass  (FIXED, 7e37261)

Logged in as the fixture supervisor, a **Procurement — Receipt Note** on the Tally queue returned
its supplier in the clear: `party: "Auro Print & Packaging"`.

The product gate is not broken. `AgentIdentity::mayReadPurchaseDetails()` opens FC-06 to anyone
holding `finance.view`/`finance.manage`, and `AcceptanceFixtureSeeder` granted the supervisor desk
every permission except `carton-trace.*`. So the supervisor held `finance.view`, and **every manual
FC-06 walkthrough had been passing for the wrong reason** — the same silent failure mode the seeder
already carried a comment about for carton-trace.

Fixed by generalising that one-off filter into a withheld-prefix list (`carton-trace.`,
`configuration-delete.`, `finance.`) and pinning it with a test that asserts through the product's
own predicates rather than permission names.

Re-verified in the browser afterwards, and the result discriminates correctly:

- Receipt Note (supplier party) → `party: null`, plus
  `party_withheld: "The supplier's identity is withheld on this voucher — supplier identity is
  Owner/Accounts only (FC-06)."`, and `party_ledger` / `party_gstin` **omitted from the payload**.
- Delivery Note (customer party) → party reads through, payload keeps `party_ledger`/`party_gstin`.
  A customer is not FC-06. Correct.

### Duplicate business codes — a GAP, and NOT the P0 it first looked like

The browser created warehouse `e2e-tmp` alongside an existing `E2E-TMP`. Every master uses a bare
`unique:<table>,<column>` rule (17+ of them: warehouses, items.sku, work_centers, molds, shifts,
scrap_reasons, employees, vendors, customers, gl_accounts, assets, …). There is **no shared
normalized-unique rule anywhere** in the codebase, so normalization is left entirely to the
database collation.

Measured on both drivers rather than assumed:

| | case-variant (`probe-1` vs `PROBE-1`) | whitespace (` PROBE-1 `) |
|---|---|---|
| sqlite (dev) | **not caught** | not caught |
| MySQL 8.0 (live) | **caught** | not caught |

So: **the guard is correct on live for case** — MySQL's default collation is case-insensitive — and
the browser observation was an artefact of the sqlite dev database. What is genuinely missing on
both drivers is whitespace/normalization, and the fact that the contract's "normalized comparison"
requirement is satisfied only by accident of collation rather than by anything in the code.

Recorded as a GAP, deliberately **not fixed tonight**: a case-insensitive `Rule::unique(...)->ignore()`
would start refusing EDITS to any live master pair that already differs only by case, making those
records uneditable. Whether live holds such pairs is a read-only query nobody has run. Shipping a
stricter validator against unknown live master data, unattended, is the wrong trade.

The contract's separate "warn on likely duplicate NAMES" requirement was **not tested at all** —
UNTESTED, not PASS.

### Day Bin (recorded, not actioned)

`/production/day-bin` still exists and still says "No day bin warehouse chosen yet — Pick which
warehouse is the factory day bin", and the dashboard still renders a `DAY BIN · Not configured`
tile. `UpdateDayBinWarehouseRequest` and the day-bin setting are still wired, so per the standing
instruction ("do not blindly delete data structures; determine what is currently relied upon") the
answer is that it IS still relied upon. Removing the surface is follow-up work, not tonight's, but
the copy contradicts DEC-20260817-001 ("There is no Day Bin") and should not survive to the floor.
