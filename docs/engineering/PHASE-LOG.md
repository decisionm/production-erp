# Phase Log — append-only

One entry per phase, amended only by dated addendum, never rewritten. Each
entry carries the completion contract: scope · changes · migrations · APIs ·
UI · tests · browser evidence · data verification · known limitations · open
decisions · commits · PR · verdict.

Verdicts: `PASS` · `NOT READY` · `BLOCKED (Q-nn)` · `HELD`.

---

## PHASE 0 — Discovery + audit

**Dates:** 2026-08-16 · **Basis:** `main` @ `9a9cbe3` (deployed, live)

| Field | Value |
|---|---|
| Scope | Establish what the ERP actually is; audit the master prompt against it; rebuild the phase plan from evidence |
| Method | 6 read-only discovery agents (Tally sync · Production · Procurement/Inventory · Sales/CRM/Finance · SKU/product master · factory decisions) + direct inspection. Nothing modified in `backend/`, `frontend/`, `tally-sync-agent/`, `docs/factory/` |
| Changes | `docs/engineering/MASTER-PROMPT-AUDIT.md` (new) · `MASTER-PLAN.md` (new) · `PHASE-LOG.md` (new) · `TEST-MATRIX.md` (new) |
| Migrations | none |
| APIs | none |
| UI | none |
| Tests | none run beyond `scripts/factory-knowledge/check.sh` → `sound`, exit 0 |
| Browser evidence | none — read-only phase |
| Data verification | Counts are from code and migrations, not the live DB. Live-role exposure (§4.1) **deliberately not read** — requires a read-only live read, listed as P1-01 |
| Known limitations | Tally XML evidence is not in the repo (Q31 lost, Q38 external) — every XML-dependent claim is marked blocked, not answered. Statistics census is from a Tally company named "Testing" and shows no Sales voucher type; named as a gap, not resolved |
| Open decisions surfaced | Q35(d) gates PO live-writes · "clean configuration" reading (a) assumed · in-app assistant needs its own brainstorm · P1 approval to proceed |
| Defects found | 19 (§4 of the audit) — 4 × P1, 7 × P2, 8 × P3 |
| Commits | branch `docs/phase-0-engineering-baseline` |
| PR | see branch — documentation PR |
| **Verdict** | **PASS** — Phase 0 approved and complete 2026-08-16 (owner-side lead). Plan shape Phases 2–8 approved as the working plan |


### Addendum 2026-08-16 — approvals and authorization received

- Phase 0 **approved and considered complete**. The four `docs/engineering/` files are the engineering baseline.
- Phase 1 **approved**: FC-06 purchase-rate leakage · procurement-only authorization test · LOCAL fixture contamination in shift vouchers · editable item-name / wire-identity risk · configuration inconsistencies · dead/unreachable configuration where removal is demonstrably safe · other P1 correctness defects. Surgical fixes only, each with regression coverage.
- "Clean configuration" clarified = **technical/configuration files** (contradicting-live values, dead keys, unreachable routes, misleading defaults). **Not** factory business data (490/520, pouch/tray, cycle times, moulds, standards, SKU/Tally identities, Q18/Q25/Q26/Q32).
- Autonomous execution authorized phase-by-phase through `implementation → tests → Sonnet QA → independent review → PR ready`. The repository's protected merge/release chain (Builder → Cursor → Codex → owner) is **not** bypassed; nothing unfinished is pushed to `main`.
- Hard owner stop-gates in force: real stock/production/accounting data changes · new class of live Tally write · ambiguous master mappings · overturning an immutable owner decision · irreversible operations.
- Non-blocking uncertainties → safe reversible path (flag / dry run / read-only / test / deferred activation) and **recorded here**, not escalated.
- Baseline test run on this machine, `main` @ 9a9cbe3: **993 passed / 5,520 assertions / 32.5s**.

### Addendum 2026-08-16 — MASTER-PLAN rev 2 (directive received mid-Phase-1)

**Change:** Sales and Downloads promoted from implied sub-features to first-class
deliverables — new **Phase 3.5 (Sales visibility)** and **Phase 4.5 (Download / Export
Center)**; **Phase 8** redefined as the end-to-end chain regression
(Product → Purchase → Inventory → Production → CEC → Tally → Sales visibility →
Downloads) with Sonnet independent QA and browser proof; the product is not called
complete until that chain passes.

**Reason:** owner-side lead directive. Sales remains Tally-originated
(DEC-20260809-003 preserved); the ERP's job is to make every Sales / Sales Order
transaction it can see visible, searchable, filterable, traceable and downloadable.

**Evidence constraints written into the plan rather than around it:** (1) CEC has no
sample or format authority anywhere in the repo — the Center ships its slot visibly
BLOCKED, never an invented layout; (2) the ERP has no read path from Tally, so
Tally-side sales are a stated gap until a sanctioned deliberate read exists (Q36);
(3) every existing export is client-side over a fetched page — the Center is the
one fix for that systemic gap; (4) FC-06 applies to exported files exactly as to
screens.

---

## PHASE 1 — Live-safety fixes

**Dates:** 2026-08-16 · **Branch:** `fix/phase-1-live-safety` (stacked on
`docs/phase-0-engineering-baseline`, PR #179) · **Base:** `main` @ 9a9cbe3

| Field | Value |
|---|---|
| **Status** | **PASS WITH DEFERRED ITEMS** — gate closed 2026-08-16: Sonnet re-gate PASS with zero findings after the fix loop; every reviewer P1/P2 fixed; deferred items are named below with reasons |
| Goal | Close the four P1 correctness defects the audit found (FC-06 rate leakage · LOCAL-fixture contamination of shift vouchers · editable Tally wire-name · misleading config default), each surgically, each with red-before/green-after regression coverage; clean technical configuration; no workflow change |
| Method | 4 file-disjoint implementers in parallel (WS-A rate gate · WS-B fixture sweep + scrap · WS-C name guard · WS-D config) → +1 focused implementer (WS-A2: four more rate carriers found by WS-A's sweep) → integration (pint, full suite, typecheck, build) → **independent Sonnet QA** (§70 matrix, own throwaway probes) → **two adversarial reviewers** (Opus: FC/DEC/accounting; Fable: races/regressions/tests) → fix loop → re-gate |
| What changed | **FC-06 (P1):** `unit_price`/`unit_cost`/`average_cost`/`parts_cost`/`total_cost` gated `finance.view\|finance.manage`, keys OMITTED not nulled (MaterialLotResource contract), on: `PurchaseOrderLineResource`, `GoodsReceiptNoteLineResource`, `StockMovementResource`, `StockBalanceResource`, `FactoryDayBinMaterialResource`, `MaintenanceWorkOrderPartResource`, `MaintenanceWorkOrderResource`, and (fix loop) `ShiftProductionEntryResource.material_cost.lines[].unit_cost`. Frontend hides the column rather than blanking it (MaterialLotsPage precedent); GRN create omits `unit_cost` when unseen → server defaults from PO line (`GoodsReceiptService:141`, provenance-noted). New read-only `roles:show` command + `show-roles.yml` (no inputs; mirrors `tally-sync-status.yml`). **Fixture sweep (P1):** shift-mate sweep now applies the SAME predicate as the top-level guard (`isLocalFixtureEntry` → `effectiveItem()->isLocalFixture()`, both signals) after `lockForUpdate`; soft-delete inclusion preserved; (fix loop) same predicate on both payload-rebuild paths so a fixture already merged under live Hole B stops riding its voucher; sweep now logs rejected member ids; a fixture is refused as a packaging identity (422). **Name guard (P1):** Tally-linked items (`tally_stock_item_guid` set) refuse a changed `name` via `PUT /inventory/items/{id}` — 422 *"…is Tally's name for this item; rename it in Tally and pull masters"* — same name resent OK; unlinked items editable; ItemsPage disables the field with that copy; `LOCAL-` sku refused on non-fixture items (create and update). **Scrap resolver:** one `ScrapItemResolver` (Inventory) replaces two byte-identical copies; NOT_NAMED vs NAMED_BUT_NOT_FOUND now distinguished in the withheld reason and the entry note. **Config:** `voucher_granularity` default `batch`→`shift` (matches live since 07-Aug, DEC-20260807-014; DEC-20260807-010); 7 tests that silently relied on batch now set it explicitly; `.env.example` documents it; dead `POST /tally-sync/items` route + `SyncItemsRequest` + `tally-sync:items` ability removed (`ItemSyncService` KEPT — `MasterSyncService` uses it); stale "MD final approval" comment corrected |
| Files / modules | Procurement (2 resources), Inventory (2 resources, 2 requests, new `ScrapItemResolver`), Production (2 resources, 1 request, `ShiftProductionEntryService`, model), Maintenance (2 resources), TallySync (`TallySyncService`, controller, `AgentTokenService`, config), Core (`ShowRoles` command), routes, `.env.example`, one workflow, frontend: procurement (2 pages, types), inventory (2 pages, types), production (types), maintenance (page, types) |
| Database changes | **none** — no migration in this phase |
| Tests | Baseline 993 / 5,520 → **1,034 / 5,740** at gate close (Sonnet re-ran twice, identical) (Sonnet reconciled exactly: 993 − 4 deleted with the dead route + 40 new). New files: `ProcurementRateVisibilityTest`, `StockRateVisibilityTest`, `ShowRolesCommandTest`, `LocalFixtureSweepTest`, `PackagingIdentityRefusesFixtureTest`, `ScrapItemResolverTest`, `ItemNameGuardTest`, `TechnicalConfigCleanupTest`; `MasterSyncTest` gains the Tally-side-rename escape-hatch case (found missing by QA). **Red-before/green-after proven** by the Fable reviewer in a scratch copy for every new file |
| Sonnet verification | Matrix all PASS on behaviour (pint · phpunit · frontend · agent untouched, 69/69 · factory-knowledge sound · FC-06 re-proved with own probes · sweep · name guard · config · scrap). Verdict **NOT_READY** on 2 × P2 (rename escape hatch untested → fixed; GUID cross-check at voucher build not shipped → deferred, see below) + 2 × P3 (fixed / deferred) |
| Independent review | Opus (rules/accounting): **FAIL** on 1 × P1 (`material_cost.lines[].unit_cost` still ungated → **fixed in loop**), 3 × P2 (LOCAL- guard used raw column → fixed; rebuild paths unfiltered → fixed; rename test → fixed), P3s (comment drift → fixed; deferred items → recorded). Fable (races/tests): **NOT_READY** on the same LOCAL- regression + P3s (third fixture predicate in `require_postable_voucher` gate → fixed; scrap lead-sentence contradiction → fixed; workflow/docblock drift → fixed; test env-dependence → hardened). Lock semantics of the sweep walked and confirmed unchanged (superset locked, PHP reject after; `isEmpty()` branch meaning intact; no deadlock; id-order locks) |
| Browser proof | **PO drawer as procurement-only user:** columns `Item · Quantity · Received`, no Unit Price/Amount/Total (`screenshot-1786899580818-1.jpg`). **API bytes as the same user:** `/purchase-orders` line keys `[id, item, quantity, quantity_received, schedules]`, `/goods-receipts` line keys `[id, item, material_lots, purchase_order_line_id, quantity]` — zero rate keys at any depth. **Control (Accounts, has finance):** same endpoints return `unit_price` / `unit_cost`. **Name guard against the live local API:** rename Tally-linked → 422 with the exact message; same name + description → 200; `LOCAL-` on real item → 422; DB unchanged. **NOT browser-proven:** the disabled Name input in the ItemsPage modal — the Edit click did not open the modal in this browser session after 3 attempts; the guard itself is proven at the API layer (the layer that protects Tally); the affordance is covered by typecheck only. Recorded honestly as NOT TESTED in the browser |
| Data / transaction proof | No voucher payload shape, `voucher_number` format, or item name that reaches Tally changed — confirmed by both reviewers (`withheld[].reason` text changed; the agent never reads that key). No live data touched; all proofs on the local dev sqlite DB with a purpose-made procurement-only role |
| Known limitations | (1) **P1-01 live role read is BUILT, not RUN** — `roles:show` + `show-roles.yml` land with this PR; running it needs the merge and a manual dispatch. Live exposure is therefore still *unmeasured*; the inconsistency is fixed regardless (§7.5). (2) A non-finance receiver can no longer *override* the rate at GRN in the UI (server defaults from PO); the API still *accepts* a blind `unit_cost` write from a procurement-only user — write not gated. StockPage manual receipts still ask every store user to type a rate (server requires it, `StoreStockReceiptRequest:19`; no PO to default from) — write-only, same as before. (3) Non-finance maintenance users lose `total_cost` (it embeds `parts_cost`) — a real, defensible capability removal, named here. (4) The packaging-identity picker still lists fixtures client-side; the server now refuses with a named reason (422). (5) `voucher_granularity` default flip: a **fresh instance now starts in shift mode and needs agent ≥ 0.3.5 from day one**. Live is unaffected (its `.env` already says shift) |
| Deferred items | **P1-05 second half — GUID cross-check at voucher build — deliberately NOT shipped.** The ERP cannot detect a *Tally-side* rename without a Tally read (removed after 08-Aug; Q36); a build-time check can only mean "does this item carry a GUID at all", and the payload carrying identity belongs with the Control Center's mapping-state surfacing → **P3-04**. Phase 1 closed the ERP-side edit surface only. · Read-only detector for pre-existing `production_standard_packagings.item_id` → fixture rows and for shift entries already vouchered under Hole B → **Phase 2 read-only command** (same shape as `roles:show`); on live, the owner decides what to do with any found. · Agent-side dead config key (`mastersPollIntervalSeconds`) → the phase that next releases the agent (Phase 4). · Standard-level identity attach stays permissive by design (fixtures exist so standards can be loaded before the Tally item exists, `Item.php:24-28`) |
| Owner decisions required | **None to merge this phase.** Named for the owner (not blocking): whether procurement-only users may *write* a rate at all; whether the importer should set `is_local_fixture=true` on the fixtures it fabricates (today prefix-only) |
| PR | see the Phase 1 PR line appended below |
| Next phase | Phase 2 — Sync Control Center foundation (read model over `tally_sync_entries`, server-side filters, server-side CSV, `needs_review`, journal categories, header counts, first frontend tests for tally-sync) |

**Phase 1 PR:** #180 (`fix/phase-1-live-safety` → stacked on #179 `docs/phase-0-engineering-baseline`). Seven commits, one concern each. Retarget to `main` after #179 merges. Merge goes through the repository chain (Cursor review → Codex verification → owner); nothing here is pushed to `main`.
