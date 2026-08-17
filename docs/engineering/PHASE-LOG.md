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

---

## PHASE 2 — Sync Control Center foundation

**Dates:** 2026-08-16 → 17 · **Branch:** `feat/phase-2-sync-control-center-foundation`
(stacked on `fix/phase-1-live-safety`, PR #180) · **Design:** `docs/engineering/TALLY-SYNC-CHAIN.md`

| Field | Value |
|---|---|
| **Status** | **PASS WITH DEFERRED ITEMS** — gate closed 2026-08-17: Sonnet first gate PASS (1 P3) → adversarial reviewers Opus FAIL (2 P1 · 4 P2 · 2 P3) + Fable PASS (2 P2 · P3s) → all valid findings fixed (F1–F9) → **Sonnet re-gate PASS** (2 non-blocking P3s, both addressed in the chain doc). Deferred items named below |
| Goal | Map the chain ERP transaction → voucher type → mappings → release state → agent payload → agent result → status/history; reuse existing tables/services; add schema only for a proven observability gap; make every real transaction category independently classifiable; durable history for the future Control Center UI; server-side filters; no UI polish, no duplicate sync model, no new status, no Tally read, Sales Tally-originated, shift release model preserved |
| Method | Chain mapped first (`TALLY-SYNC-CHAIN.md`, committed as the design). Two waves of file-disjoint implementers: **Wave 1** WS-A events history ∥ WS-B classifier; **Wave 2** WS-C query service + endpoints ∥ WS-D minimal frontend wiring. Integration → Sonnet independent QA → two adversarial reviewers → fix loop → re-gate |
| What changed | **The proven gap:** the event vocabulary already existed in `agentLog()` (`pending.delivered`, `voucher.synced`, `voucher.failed`, `voucher.failure_refused`, `masters.received`, `company.bound`, `companies.received`, `stock-summary.previewed`) but went to a 30-day FILE; on the entry, `error_message` was overwritten per failure, `delivered_at` nulled on retry, `attempts` a bare counter, no actor on retry/dismiss, no agent identity, `resolution_log` in mutable JSON — a voucher's timeline could not be reconstructed once touched twice. **Added — one table, `tally_sync_events`** (append-only, `created_at` only, model throws on update/delete): nullable entry FK, event, direction (`erp_to_tally`/`tally_to_erp`), `occurred_at`, actor (type/id/label — agent by TOKEN NAME never the token, user by name, or system), details JSON. Written by ONE recorder at every mutation (enqueue, shift merge, follow-up, delivery hand-out when `delivered_at` is newly stamped — not per re-poll, those are heartbeat — ack, fail, refused fail, retry, dismiss, release, fixture-excluding rebuild) and every inbound agent contact (counts only; never item lines or rates). Backfill migration reconstructs best-effort events for existing entries, every row `details.backfilled=true` + actor `backfill 2026-08-16`; idempotent; `down()` removes only its rows. **Classification, derived not stored:** `TallyTransactionCategory` (15 cases) + `TransactionClassifier` — six ERP-built categories (production Stock Journal per shift · per batch with `erp_label_differs_from_wire=true` since the ERP label says "Manufacturing Journal" and the wire says Stock Journal · Sales invoice · Delivery Note · Receipt Note · Journal), Purchase Order **planned** (DEC-20260812-002), and the Tally-only categories from the 12-Aug census (Purchase, Payment, Receipt, Contra, Credit/Debit Note) plus **Sales Order** (no such voucher type in the books; DEC-20260809-003) with `wire=null` — never invented; `unknown` for a mismatched pair, never guessed. Resource gains `category`, `business_date`, `document_number`, `party`, `item_summary`; every pre-existing key unchanged. **Query service + endpoints:** `GET /tally-sync/entries` gains validated filters (status[], category[], voucher_type[], from/to on `payload->voucher_date` — JSON path, both drivers, no denormalised column at ~3–10 rows/day; q = LIKE-escaped contains over voucher/party/batch number, never fuzzy; shift_id / work_center_id reaching shift vouchers via members; held via the REAL release gate evaluated over the small pending-shift set then `whereIn` — the gate is not rewritten in SQL; direction; `sort=status_rank`); new `GET /tally-sync/entries/{id}` with `history` (only on show); new `GET /tally-sync/summary` (today's counts on the **factory-tz business date** — proven red-before against app-UTC; all-time; full catalogue with integer counts for ERP-built and **null, never zero**, for planned/Tally-only; agent last contact, last synced, last masters pull). **Frontend, no redesign:** filter row bound to the params; client-side status sort replaced by `sort=status_rank` (identical order); today's counts line; Category column with "posts as Stock Journal" suffix where the label differs; the honesty line in three clauses — *"Lives in Tally, not mirrored: Purchase · Purchase Order · Payment · Receipt · Contra · Credit Note · Debit Note"* · *"Purchase Order: ERP-originated version planned (Phase 6)"* · *"Sales Order: no such voucher type in Tally — sales are invoiced there (DEC-20260809-003)"*; truncation-honesty compares against the filtered total; every existing action untouched; first vitest for the feature. **Fix loop (post-review):** payload `rate`/`amount`/`total_amount` gated on finance.view/finance.manage or the real agent token (`AgentIdentity`, ability-based — a plain PAT is a user), the agent still receives them byte-identical (proved); catalogue split into two axes `source: erp|tally|absent|unknown` + `erp_build: built|planned|none` (Purchase Order is IN the books, 92, and planned; Sales Order absent); `held_now` (state, not today's window — the night-shift voucher); `agent.last_action_*` (heartbeats record no event); `unknown` reachable in the category dropdown + "N unclassified" in the header; `pending()` and `retry()` each ONE transaction (stamp and events commit or roll back together, ids from this call's locked read); MySQL JSON-null guards on `from`/`to`/`q`; dead `paginate()` removed, `show()` load moved to the query service |
| Files / modules | TallySync only (2 migrations, 2 models, 2 enums, 3 services, 1 request, 2 resources, 1 controller, routes) + `frontend/src/features/tally-sync/` (api, types, page, new `filters.ts`) + `docs/engineering/TALLY-SYNC-CHAIN.md` |
| Database changes | `2026_08_16_100000_create_tally_sync_events_table` (new table) · `2026_08_16_100001_backfill_tally_sync_events` (data, idempotent, reversible). **No column on `tally_sync_entries`; no status added** |
| Tests | 1,034 / 5,740 → **1,087 / 6,357** (+53: SyncEventHistoryTest 14 · SyncEventBackfillTest 4 · TransactionClassifierTest 10 · SyncQueryFiltersTest 15 · SyncSummaryTest 6 · SyncEntryShowTest 4). Frontend 25 → 42 (`filters.test.ts` 17). Red-before proven per workstream: pending() recording hunk (A), honesty flag (B), factory-tz today (C), wire suffix (D). Agent untouched (`git diff --stat -- tally-sync-agent` empty). Pint clean, typecheck clean, build clean, factory-knowledge sound |
| Sonnet verification | **First gate: PASS** — every contract proven by behaviour incl. own throwaway probes (events per mutation, append-only, refused calls record nothing, backfill idempotent; classifier; every filter incl. held via the real gate; factory-tz today; show history / list without; frontend wiring; the 'does not' list; 4 adversarial probes incl. transaction-rollback of events). One P3 (pre-stamp read for the delivered event). **Re-gate after fix loop: PASS** — F1 proven with a real bearer token on /pending (bytes identical to storage) AND a real `TransientToken` probe (can() true for everything, `AgentIdentity` still false); deadlock walk of pending() vs merge (no cycle); F2–F9 each by test run + diff read. Two P3s: `events.details` not finance-gated (verified no site writes a rate — now a written rule) and lock-order undocumented (now §4 of the chain doc) |
| Independent review | **Opus (design + rules): FAIL → fixed.** [P1] raw `payload` leaked GRN rate/amount/total_amount to any tally-sync.view user and the new filters made it a searchable rate archive; [P1] Purchase Order typed 'planned' while 92 exist in Tally (two axes overloaded); [P2] Sales Order shown as 'lives in Tally' though no such voucher type exists; [P2] `held` bucketed under today's date → '0 held' every night; [P2] any PAT typed as agent + 'last contact' was really last action; [P2] `unknown` unreachable in the UI; [P3] pending() could 500 after stamping; [P3] MySQL JSON-null divergence. **Fable (correctness + tests): PASS** with [P2] pending() non-transactional, [P2] last-contact honesty, and P3s — and it ran the Phase 2 suites **on MySQL 8** (all pass) and found 6 pre-existing tests (LocalFixtureSweepTest ×3, ShiftVoucherGranularityTest ×3) that fail there on JSON key-order `assertSame` — CI is SQLite-only → Phase 7 item |
| API proof | After the fix loop, on the local dev API: `/summary` carries `held_now`, `agent.last_action_*`, `purchase_order` = source tally + erp_build planned + count None, `sales_order` = absent + wire None; a **tally-sync-only user** sees GRN line keys `['item','quantity']` and no `total_amount` (zero rate keys at any depth) while **Accounts (finance)** sees `['amount','item','quantity','rate']` + `total_amount` |
| Browser proof | Page renders the foundation with no redesign (`screenshot-1786906388584-4.jpg`): filter row, today's counts line, Category column, honesty line at the foot. **API bytes:** `/summary` returns integer counts for ERP categories and `None` (never 0) for planned/Tally-only, `sales_order` wire `None`; `/entries` carries `category`/`business_date`/`document_number`/`party`/`item_summary` and no `history`; `/entries/{id}` carries history flagged **BACKFILLED** with actor `backfill 2026-08-16`; filters `status[]=failed`, `category[]=…`, `from/to`, `q`, `held=1`, `category[]=purchase` (Tally-only → empty), `direction=tally_to_erp` (→ empty on entries) each return exactly the expected ids on the dev DB. **NOT browser-proven:** typing into the Ant `Input.Search` / opening the category `Select` through the harness did not reach React state (same harness limitation as Phase 1); the filter behaviour is proven at the API layer with exact ids and in `SyncQueryFiltersTest` |
| Data / transaction proof | Nothing that reaches Tally changed — payload builders, `voucher_number` formats, `pending()`'s hand-out set, `ShiftVoucherReleaseGate` all untouched (reviewers asked to confirm by diff). Local dev DB: both migrations `DONE`; 4 entries → 4 backfilled events |
| Known limitations | (1) ~~`voucher.rebuilt` on the retry path is written outside a transaction~~ — closed in the fix loop: `retry()` and `pending()` are each one transaction. (2) `q` does not search shift-voucher batch numbers: the shift payload writes `batch_number=null` and lists batches only in narration. (3) MySQL not exercised locally (SQLite in tests); JSON path, `lower(json_unquote(...)) like ? escape '!'`, `case status when` are standard MySQL — the JSON-null divergence (`json_unquote` → the string `'null'`) is guarded with `whereNotNull` on every JSON-path predicate, proven on SQLite, and the live migrate step's own output is the evidence at deploy (MySQL CI leg is Phase 7). (4) `held` filter evaluates the gate in PHP over pending Shift vouchers — bounded (~3/day) but stated. (5) ~~`TallySyncService::paginate()` unused and left in place~~ — removed in the fix loop |
| Deferred items | **`needs_review` status** — deferred with reason: retry is manual only (the agent never re-picks a failed entry), so there is no infinite-retry loop to break; the failure + `fix.sentence` already say what to do; a new status touches every guard and 15 test files for a classification the events table now records → revisit in Phase 3 with evidence from real failures. **CSV export** → built once in the Download Center (Phase 4.5) on this read model. **Detail drawer / per-type views / timeline UI** → Phase 3 (P3-01..05); `getTallySyncEntry` is wired but not yet consumed. **Read-only detector** for pre-existing fixture packagings / Hole-B-vouchered entries (Phase 1 deferral) → still open, next |
| Owner decisions required | None |
| PR | see the Phase 2 PR line appended below |
| Next phase | Phase 3 — Sync Control Center: every real transaction type (detail drawer per type over the normalized chain, human-readable summary, timeline from events, mapping-state surfacing, per-type tests) |

**Phase 2 PR:** #181 (`feat/phase-2-sync-control-center-foundation` → stacked on #180 → #179). Eight commits. **Deployment state:** not deployed — the stack #179 → #180 → #181 awaits the repository merge chain (Cursor → Codex → owner). Nothing pushed to `main`.

---

## PHASE 3 — Sync Control Center: every real transaction type

```
Phase:    3 — every real transaction type (MASTER-PLAN P3-01..05)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-3-sync-every-transaction-type (stacked on Phase 2 PR #181 → #180 → #179)
Dates:    2026-08-17

Goal:
  Inspect, trace, display, filter and test all real transaction types over the
  normalized chain — with mapping state surfaced per line WITHOUT inventing a
  conflict table, a human summary and timeline per type, honest flags (Sales
  builder unvalidated; GRN order reference not emitted), a detail drawer that
  walks the chain, and per-type lifecycle contract tests. Sales stays
  Tally-originated (DEC-20260809-003). No Tally read. Shift aggregation intact.

What changed:
  • LineMappingResolver — the ONE resolver of voucher-line names → mapping
    state: identity (a Tally GUID or configured ledger role — proves the ERP
    linked it once, not that Tally still has it) · name_only (row, no GUID —
    Tally would match by name if it exists; the ERP cannot know) · unmapped ·
    fixture (never postable) · ambiguous (>1 ERP row shares the name —
    items.name and warehouses.name carry no unique index; Tally would match ONE
    and the ERP cannot say which — nothing is chosen) · none. Exact names,
    memoised per request, no Tally read. VoucherPreviewService now CALLS it —
    its pre-approval sentences unchanged, its inline queries gone — so the
    approval verdict and the Control Center can never disagree. Show endpoint
    gains `mappings` (per line/dimension) + `mapping_summary`; list carries
    neither; no rate rides in them. Ambiguity is surfaced, not turned into a new
    approval gate (that would be a live-data blocker for the owner).
  • EntryPresenter — `summary` {headline, lines} per category, counts and
    quantities only, never a rate (GRN at 96.5 → '96.5' provably absent);
    `timeline` = events ⊕ entry timestamps de-duplicated by kind within 5 s,
    reconstructions flagged; `flags`: unvalidated_builder (Sales — quotes
    salesInvoice.ts:19-32; DEC-20260809-003), order_reference_not_emitted
    (Receipt Note carrying tally_order_no — receiptNote.ts emits neither),
    label_differs_from_wire (batch), held (the real gate). Flags ride on the
    list; summary/timeline show-only.
  • EntryDrawer replaces the JSON View modal — sections in chain order (ERP
    source → voucher → mappings with state badges → release → payload table
    per type with rate/amount columns ONLY when the server sent the keys →
    result → timeline with reconstructions muted). Banners for the flags.
    Footer reuses the row's Release now / Resync / Dismiss handlers (lifted,
    not duplicated). Sales rows wear an 'unvalidated builder' tag on the list.
  • Per-type lifecycle contract tests — six ERP types through the REAL
    endpoints: create → listed → real-agent-token delivery → fail → retry
    403/200 → deliver → ack → then every dishonest mutation refused; failed →
    dismiss → retry refused; 403 for strangers; duplicate enqueue → one row
    (guards live upstream in each domain; Delivery has NO replay key — a raw
    re-fired DeliveryDispatched would mint a second row: documented, not
    papered over → Phase 3.5).
  • DEC-20260807-013 end to end: quality-rejected carton refused at scan with
    the exact sentence, nothing moves, mixed scan rolls back; not-yet-QC'd not
    blocked; good scan dispatches once, one Delivery Note; OverDeliveryException
    covered on both paths (audit §4.6 gap closed).
  • tally-sync:audit-fixtures — READ-ONLY report (exit 0, DB byte-identical):
    packagings pointing at a fixture · entries vouchered under the old sweep
    hole · vouchers naming a fixture. The Phase 1 deferral delivered; on live
    the owner decides what to do with any found.

Files/modules:
  TallySync (LineMappingResolver, EntryMappingSurface, EntryPresenter,
  VoucherPreviewService, TallySyncEntryResource) · Console (AuditLocalFixtures)
  · frontend tally-sync (EntryDrawer.tsx, drawer.ts, types.ts, TallySyncPage.tsx)
  · tests: LineMappingResolverTest, SyncEntryMappingsTest, EntryPresenterTest,
  PerType/* (6 + base), Sales/DispatchRefusesQualityRejectedCartonTest,
  AuditLocalFixturesCommandTest, drawer.test.ts

Migrations:       none
Tests before:     1,097 / 6,503 (backend) · 45 (frontend)
Tests after:      1,193 / 7,914 (backend, +96) · 69 (frontend, +24)
                  agent untouched 69/69 · pint/typecheck/build clean · knowledge sound

Sonnet first gate:   PASS (workstreams integrated; no P0/P1) — then adversarial
                     review opened two P1s, so the gate was re-run after each fix
                     round rather than treated as final.
Findings:
  P1  Ambiguous mapping arm weakened the fail-closed preview: the resolver's
      STATE_AMBIGUOUS `break;` let a voucher naming a duplicated item/godown
      reach the pre-approval verdict without the "no identity" refusal the old
      `->first()` path gave (implementer framed it as "no new gate"; accepted
      too quickly — recorded as a lesson). Fixed: ambiguous arms fail closed
      (no-identity problem when no candidate carries a GUID + an ambiguity
      blocker; UOM and packing-store checks walk ALL candidates); the
      block-vs-warn policy itself is the owner's → Q43.
  P1  FC-06 second half. Every earlier FC-06 gate reasoned about MONEY only;
      supplier IDENTITY (party ledger, GSTIN) rode Receipt Note headline,
      mappings.party, payload.party_ledger/party_gstin, root `party` and the
      list `q=` search for a tally-sync-only reader. Fixed with ONE predicate:
      AgentIdentity::mayReadPurchaseDetails() ∧ TallyTransactionCategory::
      partyIsSupplier() → withheld state on mappings, headline without the
      party, payload keys stripped, root party null, q= excludes supplier-party
      categories from the party_ledger LIKE (SyncQueryFiltersTest had been
      PINNING the oracle — corrected to expect []).
  P1  (re-gate #1) Tally's own rejection text carries the supplier ("Ledger
      does not exist : <vendor>") — leaked via error_message, resolution_log
      .previous_error (root AND the copy inside payload), timeline detail,
      history[].details. Fixed across TallySyncEntryResource / EntryPresenter
      / TallySyncEventResource with an `error_withheld` note; PerTypeLifecycle
      TestCase now states the rule per type instead of asserting error_message
      readable for all.
  P1  (re-gate #2) my frontend commit rendering error_withheld exposed a new
      contradiction: the "Fixed after N failed attempts" banner was judged on
      `!error_message` — now null on a still-FAILED withheld voucher → green
      "Fixed" beside "withheld (FC-06)". Fixed: predicate lifted to drawer.ts
      showsFixedAfterFailures() gated on status first, used by both the row
      and the drawer, pinned by four vitest cases (5224cfc).
  P2  ambiguity note wording overclaimed ("Tally would match ONE") → softened
      to "this ERP cannot check"; identity notes say "linked once", not "Tally
      still has it".
  P3  fixSuggestion() has no arm for "Ledger does not exist" (observation, not
      a defect — the failure still lands in the drawer verbatim for readers
      with standing).
Fixes:               all P1/P2 above closed on the branch (commits 8f1a16f …
                     2985e00, 5224cfc); red-before/green-after per fix.
Sonnet final gate:   re-gate #1 FAIL (rejection-text leak) → re-gate #2 FAIL
                     (banner) → **re-gate #3 PASS** — no findings; banner truth
                     table pinned; no other surface infers health from a null
                     error (statusColor/holdCopy/timelineItems/button state
                     checked); status emitted unchanged when withholding;
                     1,193/7,914 · 69 · agent diff empty · knowledge exit 0.
Independent review:  Opus (rules/honesty/FC-06) FAIL → both P1s fixed;
                     Fable (correctness/tests) NOT READY → ambiguous arm fixed
                     + q= oracle closed → satisfied by re-gate #3.

API proof:
  Show #3 (Accounts, dev DB): keys flags/history/mapping_summary/mappings/
  summary/timeline present; headline "Receipt Note GRN-3 · Auro Print &
  Packaging · 31-Jul-2026 · 1 item × 10000 · waiting for agent"; mapping_summary
  {identity 0, name_only 3, unmapped 0, fixture 0, ambiguous 0} — HONEST on the
  dev seed (no GUIDs) with the party's no-FK note verbatim; timeline one row,
  source backfill, flagged.

Browser proof:
  Drawer opened for GRN-3 as Accounts: "GRN-3 — as it goes to Tally" → ERP source
  → Voucher (Posts to Tally as Receipt Note) → Party ledger badged 'name only' →
  Release "Not held" → Payload table Item · Quantity · Rate 0.8500 · Amount
  8500.0000 → Result → Timeline "Queued [reconstructed] · backfill 2026-08-16"
  (screenshot-1786914409631-7.jpg, -8.jpg). SAME drawer as the tally-sync-only
  user: payload table Item · Quantity ONLY — no Rate/Amount columns, no blanks
  (screenshot-1786914523486-8.jpg). Three-clause honesty line at the foot.
  Harness note: Ant Select/Input still not drivable via refs; View needed a
  second click on a fresh page — behaviour is proven at the API layer.

Data/transaction proof:
  Nothing that reaches Tally changed (payload builders, voucher_number,
  pending() hand-out, release gate untouched). No migration. No Tally read.

Security proof:
  Non-finance reader: mappings/summary/timeline/flags carry no rate at any depth
  (walked in SyncEntryMappingsTest + EntryPresenterTest); payload rate gate from
  Phase 2 intact; the agent still receives the whole payload (SyncPayloadRate
  VisibilityTest); 403 for users without tally-sync.view on list/show/summary
  (per-type tests). Audit command proven read-only.

Deferred items:
  • unvalidated_builder now covers all four builders that carry the "NOT YET
    VALIDATED" docblock (salesInvoice.ts:19, receiptNote.ts:17,
    deliveryNote.ts:17, journalEntry.ts:13) — validation of those XML shapes
    against real Tally is Phase 4/6 evidence work, not a flag change.
  • fixSuggestion() "Ledger does not exist" arm (P3) — add when the first
    real-failure text is on file; not invented from a guess.
  • Delivery has no replay key (raw re-fired DeliveryDispatched → second row)
    → Phase 3.5 P3.5-03 (the delivery/SO model work).
  • `ambiguous` surfaces but does not block approval — a new approval gate on
    live data is the owner's call.
  • needs_review status: still deferred (Phase 2 reasoning stands; no
    real-failure evidence yet).

Owner-gated items:  Q43 (duplicate master names: block or warn) — the ERP fails
                    closed until answered; audit-fixtures output on live → owner
                    decides what to do with any found.
PR:                 #182 (base: feat/phase-2-sync-control-center-foundation → #181 → #180 → #179)
Deployment state:   not deployed; stack #179 → #180 → #181 → this PR awaits the merge chain
Next phase:         3.5 — Sales and Sales Order visibility (first-class)
```

## PHASE 3.5 — Sales and Sales Order visibility (first-class)

```
Phase:    3.5 — Sales visibility (MASTER-PLAN P3.5-01..06; P3.5-07 downloads → Phase 4.5)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-3.5-sales-visibility (stacked on Phase 3 PR #182 → #181 → #180 → #179)
Dates:    2026-08-17

Goal:
  Make every ERP-originated Sales / Sales Order / Delivery / Invoice VISIBLE,
  SEARCHABLE, FILTERABLE and TRACEABLE — server-side — and make the pages SAY
  what is not there: real sales are invoiced in Tally (DEC-20260809-003), no
  Tally read exists, so Tally-side vouchers are NOT mirrored. The ERP does not
  become the sales system of record. Nothing that reaches Tally changes.

What changed:
  • Server-side filters on sales-orders / deliveries / invoices (FormRequest
    per list): customer, status, date range (order_date/invoice_date as plain
    dates; delivered_date as a factory-day range through the factory
    timezone), item, sales_order_id, `q` (document number in any spelling —
    "SO-12", "so 12", "SO#12", "12"; delivery reference; customer name/code;
    never notes), sort (allowlist, 422 otherwise), per_page 1..100 (422 outside).
    Paginator links carry the validated filters.
  • show endpoints per document with `trace`: SO → deliveries (with the
    cartons that physically left: carton_no, pieces, batch) → invoices → for
    each, ONE Tally link {entry_id, voucher_type, status, voucher_number,
    synced_at, flags, link "/tally-sync?entry=ID"} — no payload, no rate, no
    party, no error text — produced by TallySync's new TallySyncLinkService,
    the only cross-module hop; cartons via Production's FinishedCartonService.
  • POST sales-orders/{id}/cancel: draft or confirmed with nothing delivered
    and no invoice (draft included) → cancelled, under a row lock in one
    transaction; a cancelled order refuses confirm/delivery/invoice; no stock,
    no Tally side effect. InvoiceStatus::Paid deliberately NOT wired — the
    ERP never marks an invoice paid; receipts live in Tally — said on screen.
  • GET sales/tally-mirror — the honesty statement in the SERVER's words
    (mirrored:false · DEC-20260809-003 · headline/body · Sales XML builder
    unvalidated, no GST · payments not recorded here); the frontend panel on
    the Sales Orders and Invoices pages renders those sentences, never its own.
  • Frontend: filter bars in the URL, server pagination, Number/Tally/Cartons/
    Docs columns, one document drawer for the three kinds walking SO → lines →
    deliveries+cartons → invoices → Tally (status tag + unvalidated-builder tag
    + deep link), Cancel where the server says can_cancel; the Tally Sync page
    honours ?entry=ID. Empty text is a function of the query's STATE (error →
    the server's sentence + status; pending → "Reading …"; only an answered
    query speaks of filters) — found in the browser proof as a login without
    Sales access, where a 403 had rendered as "No sales orders match these
    filters."
  • TallySyncService::enqueue() (the generic path: Sales, Journal, Receipt
    Note, Delivery Note, batch-mode production) is IDEMPOTENT per (document,
    voucher type): a re-fired DeliveryDispatched or a re-saved issued invoice
    returns the live entry — the "Delivery has no replay key" gap named in
    Phase 3 is closed. Dismissed does not count (the write-off road stays open).

Files/modules:
  Sales (SalesDocumentQuery, SalesDocumentTraceService, TallyMirrorStatement
  Service, List*Request ×3, controllers/resources, SalesOrder/Delivery/Invoice
  models) · TallySync (TallySyncLinkService, TallySyncService::enqueue) ·
  Production (FinishedCartonService::forDeliveries/countForDeliveries) ·
  routes/api.php (sales group) · frontend sales (filters.ts, drawer.ts,
  SalesFilterBar, SalesDocumentDrawer, TallyMirrorPanel, useSalesListParams,
  three pages), tally-sync/pages/TallySyncPage.tsx (?entry= only) · tests:
  SalesSearchFilterTest, SalesDocumentShowTest, TallySyncLinkServiceTest,
  SalesOrderCancelTest, SalesTraceChainTest, TallyMirrorHonestyTest,
  GenericEnqueueReplayTest, filters.test.ts, drawer.test.ts

Migrations:       none
Tests before:     1,193 / 7,914 (backend) · 69 (frontend)
Tests after:      1,243 / 9,060 (backend, +50) · 105 (frontend, +36)
                  agent untouched 69/69 · pint/typecheck/build clean · knowledge sound

Sonnet first gate:   PASS (2 P3: q="-1" leniency; stale payload on the idempotent
                     return is contract-mandated and pinned)
Findings (adversarial: Opus rules/honesty PASS_WITH_DEFERRED · Fable correctness
PASS_WITH_DEFERRED; no P1):
  P2  N+1 on the orders list — withSum yields SQL NULL for an un-invoiced order
      and `?? null` read it as "not loaded" → one SUM per row (20 extra queries
      a page on this factory, where real invoices live in Tally). Fixed
      (array_key_exists; NULL → '0.0000'); DB::listen test, red-before proven.
  P2  "Delivered / Invoiced" counted DRAFT invoices and said so nowhere. Fixed:
      caption on the column header (Tooltip) and the drawer's Quantities line;
      arithmetic unchanged; the question itself → Q44.
  P3  documentId() read "-1"/"#1" as id 1 → separator only after the prefix;
      "SO#12" still a spelling; tested (red-before proven).
  P3  cancel() read-then-write without a lock → transaction + lockForUpdate.
  P3  paginator links dropped the filters → withQueryString; tested.
  P3  LIKE escaping and the exact factory-day boundary instant untested → tests.
  P3  (deferred) TallySyncLinkService ranks newest-live first — for LEGACY
      duplicate rows a synced older entry could sit behind a newer pending one;
      the guard makes new duplicates impossible; leave as contract-defined.
  P3  (deferred) the idempotent enqueue() returns a stale payload if the
      document is edited after queueing — retry() regenerates; a "queued
      payload predates the document" flag is a future TallyLink field.
  P3  (recorded) a sales.view login now reads carton/batch numbers on a
      delivery trace (no lot/GRN/rate/supplier — DEC-20260810-001 and FC-06
      intact) — a permission widening the contract sanctioned; named in Q44.
  P3  (deferred) cancel/confirm record no actor/reason → with Q44's answer.
  P3  (recorded) MySQL provenance: the suite runs on sqlite; the constructs
      (lower() like ? escape '!', whereDate, orderByRaw is-null, groupBy
      aggregate) are portable by reading, not by a MySQL run → Phase 7 leg.
Fixes:               7250255 (empty-text honesty), cb0198c (fix loop) — all
                     P2/P3 above except the four marked deferred/recorded.
Sonnet final gate:   re-gate PASS_WITH_DEFERRED (all seven fixes verified: N+1 test
                     non-vacuous, caption, documentId, cancel lock, links, tests,
                     Q44) — three P3s (frontend parseDocumentRef grammar parity
                     + comment; withQueryString comment overclaim; links pinned
                     on one list only) fixed on the branch after the re-gate,
                     suites re-run green: 1,243 / 9,060 · 105 vitest.
Independent review:  Opus PASS_WITH_DEFERRED · Fable PASS_WITH_DEFERRED →
                     P2s fixed, P3s fixed or recorded above.

API proof (dev API as Administrator, 127.0.0.1:8000):
  GET sales/tally-mirror → exact contract strings, mirrored:false, decision
  DEC-20260809-003. sales-orders?per_page=5 → 2 rows with document_number,
  totals, counts, can_cancel; q=SO-1 / "so 1" / 1 → [1]; 12abc → [];
  status=confirmed&sort=-order_date → [(2, 2026-08-08)]; sort=notes /
  per_page=0 / status=bogus → 422; unknown=x → 200. sales-orders/1 → keys
  incl. trace{deliveries[DN-1: tally pending, voucher DN-1, link
  /tally-sync?entry=4, flags[unvalidated_builder]], invoices[INV-1 issued,
  tally null — HONEST: the dev seed's invoice has no Sales entry]}.
  deliveries → sales_order{SO-1}, customer, carton_count 0, tally pending;
  invoices/99999 → 404.

Browser proof (Chrome MCP, vite dev, as Administrator):
  Sales Orders page: the mirror panel with the server's four sentences and the
  DEC tag; filter bar; Number/Status/Customer/Delivered-Invoiced/Docs columns
  (screenshot-1786922940002-9.jpg). ?open=SO-1 opens the drawer: header,
  Quantities, Lines, Deliveries → DN-1 with "Waiting for agent" +
  "unvalidated builder" tags and "Delivery Note DN-1 · Open in Tally Sync",
  "No cartons scanned — quantities were typed."; Invoices → INV-1 issued, Tally
  "—"; Cost & margin section intact (screenshot-1786923035241-10.jpg).
  /tally-sync?entry=4 opens "DN-1 — as it goes to Tally" with the
  unvalidated-builder banner (screenshot-1786923071939-12.jpg). Invoices page
  carries the panel and a Tally column; Deliveries page shows SO / Cartons /
  Tally columns with the tags and deep link (DOM-read). As a login WITHOUT
  Sales access the lists had read "No sales orders match these filters." over
  a 403 — fixed in 7250255 and re-proven by vitest.
  Harness notes: a hidden tab pauses TanStack retries (focusManager) — the
  paused state now reads "Reading …", never nothing; the drawer's mask covers
  the row's View button, so a click there closes it (not a defect).

Data/transaction proof:
  Nothing that reaches Tally changed (payload builders, voucher_number,
  pending() hand-out, release gate untouched). No migration. No stock change
  (cancel proven: zero StockMovement, zero TallySyncEntry/Event). No Tally read.

Security proof:
  Every sales list/show/cancel behind module:sales (sales.view read /
  sales.manage write; 403 without); TallyLink is exactly seven keys — no
  payload/rate/party/error text; no 'unit_cost'/'vendor' anywhere in the three
  show pages (SalesTraceChainTest); tally-mirror is a pure read (DB::listen).

Deferred items:
  • TallySyncLinkService ranking for legacy duplicate rows (synced older vs
    pending newer) — leave until such a row exists on live.
  • "queued payload predates the document" flag on TallyLink — future.
  • Cancel/confirm actor + reason — with Q44's answer.
  • Deliveries list still prints delivered_date as the raw ISO instant
    (pre-existing) — with Phase 4.5's date presentation sweep.
  • MySQL CI leg (Phase 7).

Owner-gated items:  Q44 (ERP sales-document lifecycle rules stated as
                    engineering defaults; carton/batch read for sales.view).
PR:                 #183 (base: feat/phase-3-sync-every-transaction-type → #182 → #181 → #180 → #179)
Deployment state:   not deployed; stack #179 → #180 → #181 → #182 → #183
Next phase:         4 — Agent-side sanitized XML + response snapshot
```

## PHASE 4 — Agent-side XML + Tally-response snapshot

```
Phase:    4 — what the agent sent, what Tally answered (MASTER-PLAN P4-01..05)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-4-agent-xml-snapshot (stacked on Phase 3.5 PR #183 → #182 → #181 → #180 → #179)
Dates:    2026-08-17

Goal:
  Close the observability gap: the cloud had no record of the XML the agent
  sent to Tally nor of Tally's answer beyond a one-line error. Now the agent
  uploads a snapshot after each post; the Control Center drawer shows "What
  the agent sent / What Tally answered" — gated by reader (FC-06), without
  moving XML generation to the cloud and without the cloud ever contacting
  Tally.

What changed:
  • Cloud: `tally_sync_snapshots` (one row per post report: xml mediumText,
    sha256, bytes, attempt, Tally {success, created, errors, message, raw},
    agent_version, payload_hash echoed, payload_matches computed against the
    cloud's CURRENT payload hash); POST tally-sync/entries/{id}/snapshot under
    the tally-sync:report ability — sha256 recomputed over the body (422 on
    mismatch), caps at request AND column, idempotent re-upload (same
    entry+sha+attempt within 60 s → the existing row), retention 90 days
    (TALLY_SYNC_SNAPSHOT_RETENTION_DAYS) pruned on write (no scheduler on the
    host); `payload_hash` on /pending for the agent's real token only;
    `snapshots` on show; a `snapshot.stored` event with counts/hash only and a
    one-line timeline sentence.
  • FC-06 on the XML — decided ONCE (TallySyncSnapshotResource::verdicts):
    the body is shown to a reader with finance standing or the agent, and to
    every tally-sync.view reader for a Stock Journal (rate-free, party-free by
    construction); for every other voucher type it is withheld WHOLE with a
    note — no partial redaction of XML text; Tally's message/raw follow the
    error_message rule. Every reader always gets sha256, bytes, version,
    attempt, when, payload verdict, and Tally's counts.
  • Agent 0.3.8: after every post — Tally rejection, success (either ack
    outcome), inconclusive timeout (tally null) — a fire-and-forget upload
    inside its own try/catch: sha256, raw capped 65535 bytes, body omitted
    over 2 MiB (size still sent), attempt = attempts+1, agent_version from
    package.json, payload_hash echoed. Never on refuse/report-only/ack-only/
    ECONNREFUSED/builder-throw. Voucher builders untouched. Built and tested;
    NOT published — publishing is a manual act (releaseContract.test.js).
  • Drawer: section 7 "What the agent sent / What Tally answered" between
    Result and Timeline — headline (attempt · agent · when · sha 12 · bytes ·
    payload verdict), pretty-printed XML with Copy (raw, so the sha stays
    checkable) or the withheld note, Tally's tags + message/withheld + raw
    toggle; "Snapshot uploaded" timeline label.

Files/modules:
  TallySync (TallySyncSnapshot, TallySyncSnapshotService, PayloadHash,
  StoreTallySyncSnapshotRequest, TallySyncSnapshotResource, TallySyncEntry
  Resource, TallySyncQueryService::show, TallySyncAgentController::snapshot,
  TallySyncEntry::snapshots, EntryPresenter, TallySyncEventKind) · routes ·
  config/tally-sync.php · migration 2026_08_17_100000 · docs/engineering/
  TALLY-SYNC-CHAIN.md · agent (snapshot.ts, version.ts, cloudApi.ts, sync.ts,
  package 0.3.8, tests/snapshot.test.js, check-tests-present) · frontend
  tally-sync (EntryDrawer.tsx, drawer.ts, types.ts, drawer.test.ts)

Migrations:       2026_08_17_100000_create_tally_sync_snapshots_table (additive; Blueprint only)
Tests before:     1,243 / 9,064 (backend) · 105 (frontend) · 69 (agent)
Tests after:      1,276 / 9,429 (backend, +33) · 127 (frontend, +22) · 94 (agent, +25)
                  pint/typecheck/build clean · voucherBuilders diff empty · knowledge sound

Sonnet first gate:   PASS (2 P3) — mutation-verified the sha recompute, the
                     verdicts table and the prune by editing source to red.
Findings (adversarial: Opus FC-06/rules NOT_READY · Fable correctness PASS):
  P1  (Opus) The new snapshot endpoint gated on tokenCan('tally-sync:report')
      alone — a browser session's TransientToken answers TRUE — so any
      logged-in staff member could (a) use payload_matches as a hash-
      comparison oracle to recover a withheld payload value (Sales rate:
      demonstrated in 26 unthrottled requests) and (b) author a snapshot
      readers see as the agent's own record. The AUTH property was inherited
      from ack/fail (documented in routes/api.php as accepted precedent —
      "staff can still exercise these from the browser regardless of their
      tally-sync role"); the consequences were new. FIXED for the WHOLE agent
      report surface — pending, ack, fail, snapshot now require a REAL agent
      token (AgentIdentity::isAgent ∧ ability); the precedent comment
      corrected; a web session holding tally-sync.manage AND finance.view is
      refused on all four (red-before proven; the only test that had relied
      on a session polling /pending was updated). Live agent unaffected (its
      PAT carries poll+report); the frontend has no callers of these routes.
  P2  (Opus) `attempt` meant three things (agent: attempts+1 ordinal; cloud
      fallback: entry->attempts, which markFailed increments and markSynced
      does not; docblocks: "as the agent saw it"). FIXED: one meaning — the
      1-based ordinal of THIS post as the agent counted it; an agent that
      sent none stores 0, never guessed; docblocks aligned (migration,
      request, client).
  P3  dead TallySyncSnapshotService::forEntry + unused injection → removed ·
      xml_bytes no max vs its column → max:4294967295 · snapshot_count typed
      but never emitted → dropped · chain doc retention without the
      "engineering default" qualifier → added · TrimStrings trimmed the
      byte-exact XML body (a trailing newline 422'd its own hash) → the
      snapshot route exempted from trimStrings/convertEmptyStringsToNull,
      byte-exact test · idempotency swallowed an ANSWERED post behind an
      unanswered one of the same XML/attempt inside 60 s → predicate
      distinguishes answered/unanswered, tested both ways · agent info-logger
      fault after a successful upload read as failure → own try · formatXml
      mis-split a quoted attribute containing '>' → quote-aware tokenizer +
      test · headline "payload matches" implied a live comparison → "payload
      matched at upload" / "had changed before upload".
  P3  (deferred) a voucher posted while the cloud was unreachable for its ack
      ends up synced with no snapshot (the ack-only re-cycle builds no XML) ·
      show returns every snapshot of an entry (bounded in practice; a
      newest-N cap with a true total is a later touch) · prune is one DELETE
      inside the write transaction (bounded by the index; a LIMIT is a later
      touch).
Fixes:               8cdb302 (all P1/P2/P3 above except the three deferred).
Sonnet final gate:   re-gate PASS_WITH_DEFERRED — P1 guard mutation-tested (revert
                     → 201, fix → 403), P2 and every P3 verified by diff/grep;
                     two doc nits (stale class docblock echoing the old
                     precedent; a dangling comment) + one theoretical edge
                     (an empty-string body after the trim exemption) fixed on
                     the branch after the re-gate; suites re-run green.
Independent review:  Opus NOT_READY → P1 + P2 fixed · Fable PASS.

API proof (dev API; real agent PAT with poll+report):
  /pending as the agent carries payload_hash per entry. POST entries/4/
  snapshot → 201 {id 1, attempt 1, agent 0.3.8, sha, 517 bytes,
  payload_matches true, tally {false,0,1,message,raw}, xml}; same body again
  → 200 (idempotent); sha mismatch → 422 "xml_sha256 does not match the
  sha256 of the xml body sent — the snapshot was not stored." Receipt Note
  snapshot on entry 1: Administrator sees xml + message + raw; the
  tally-sync-only login sees xml null + xml_withheld note, message null +
  message_withheld note, raw null, counts {false,0,1}, sha/bytes/payload
  verdict; the agent's own read-back is full. Delivery Note (customer) to the
  same login: xml withheld (uniform rate rule), message SHOWN (customer
  party). Timeline: "The agent uploaded what it sent and what Tally answered
  — attempt 1 · sha256 2d59946ac5c0 · 409 bytes · Tally rejected."

Browser proof (Chrome MCP, vite dev):
  As Administrator, /tally-sync?entry=1 → drawer "GRN-1 — as it goes to
  Tally" → "What the agent sent / What Tally answered": headline line with
  the rejected tag, Copy XML, the XML pretty-printed (PARTYLEDGERNAME, RATE,
  AMOUNT visible to finance standing) (screenshot-1786930223945-14.jpg). As
  the tally-sync-only login, the same drawer: "What the agent sent" → the
  withheld note; "What Tally answered" → rejected · created 0 · errors 1 +
  the response-text-withheld note; Timeline gains the snapshot row
  (screenshot-1786930270694-16.jpg). Two cosmetics found and fixed in the
  proof (run-on withheld line; raw event kind as the timeline label).

Data/transaction proof:
  Nothing that reaches Tally changed: voucher builders byte-identical (diff
  empty), pending() hand-out unchanged except an additive payload_hash for
  the agent, ack/fail untouched. Migration additive. No Tally read. The agent
  is NOT published.

Security proof:
  Store endpoint 403 without tally-sync:report (SnapshotStoreTest); four-
  caller visibility matrix per voucher type (SnapshotVisibilityTest); event
  details and agentLog carry counts/hash only; payload_hash only on the
  agent's real-token branch (PendingPayloadHashTest).

Deferred items:
  • A voucher posted while the cloud was unreachable for its ack ends up
    synced with no snapshot (the ack-only re-cycle builds no XML) — needs a
    journal-persisted pending snapshot on the agent; later.
  • `snapshot_count` on the list — not added (list kept light).
  • Retention window is an engineering default (90 days) — the owner has not
    been asked whether the factory wants a different window (recorded).

Owner-gated items:  publishing agent 0.3.8 to the factory floor (manual,
                    lead/owner; not part of this phase).
PR:                 #184 (base: feat/phase-3.5-sales-visibility → #183 → #182 → #181 → #180 → #179)
Deployment state:   not deployed; stack #179 → … → #183 → #184; the
                    migration lands with the deploy; the agent needs its
                    own release for snapshots to start arriving.
Next phase:         4.5 — Download / Export Center (CEC slot BLOCKED)
```

## PHASE 4.5 — Download / Export Center (first-class)

```
Phase:    4.5 — Download / Export Center (MASTER-PLAN P4.5-01..06)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-4.5-export-center (stacked on Phase 4 PR #184 → … → #179)
Dates:    2026-08-17

Goal:
  One server-side export subsystem: every download is the SAME query the
  list/report runs, with the SAME filters, for the SAME reader — never the
  rows rendered in the browser; FC-06 on the file exactly as on the screen;
  no silent caps; CEC visibly BLOCKED with the reason, never an invented
  layout; every ask audited.

What changed:
  • Core/Exports: ExportKind (key · label · module · permissionAny ·
    filterRules delegated to the list's own request · rowCap · status/
    blockedReason · columns(reader) · rows(filters, reader) built THROUGH the
    module's Resource · count), ExportRegistry (permission-filtered catalogue,
    blocked kinds listed with their reason, filter schema derived from the
    rules), CsvStreamer (BOM, CRLF, RFC 4180, the SAME formula guard as
    frontend/src/lib/csv.ts, one row at a time, sha256 accumulated), ExportRun
    (one row per POST — success, cap refusal, blocked), GET /exports · POST
    /exports/{kind} · GET /exports/runs (own runs).
  • DEVIATION FROM THE PLAN'S SHAPE, deliberate and stated: exports are
    SYNCHRONOUS streamed CSV with a STATED row cap ("N rows match; the cap is
    C — narrow the range", 422) because no queue worker or scheduler runs on
    the host (QUEUE_CONNECTION=database exists, nothing consumes it; TECHNICAL-
    DOCS §8 defers hosting). The enqueue/status/download shape stays the
    target once a worker exists.
  • Fourteen kinds: tally_sync_entries, tally_sync_history (details never
    carry Tally's text), shift_summary (one row per shift with records + the
    day), production_report (+ day_total as the screen), reconciliation_report,
    traceability_report (blocked with its reason when the flag is off), cec
    (BLOCKED — "CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED"),
    purchase_orders / purchase_order_lines / goods_receipts /
    goods_receipt_lines (rates on the LINE kinds iff the line resource's own
    showsCost() says so — the same static both call), sales_orders /
    deliveries / invoices (off the same builders as their lists). PO and GRN
    lists gained the Sales filter grammar (backward compatible).
  • FC-06 on files: columns are the same for every reader; where the screen
    withholds a cell the file reads "withheld (FC-06)" (the resource now says
    party_withheld beside the null as it did for error_withheld); a column
    whose key the resource omits for this reader (rate) is ABSENT from the
    file; production reports carry no rate/cost/amount/party (pinned).
  • Frontend: /exports "Downloads" (any authenticated user; the catalogue
    filters), cards per kind from the catalogue with a form generated from the
    server's filter schema, Download → POST → blob saved under the server's
    filename, the CEC card disabled with the reason verbatim, Recent downloads,
    the server's own sentence on cap/blocked/403; the three client-side CSV
    buttons on the production Reports page now POST the report's own filters
    to the Center; the client-side CSV builder is gone.

Files/modules:
  Core (Exports/*, Models/ExportRun, Services/ExportService, Providers/
  ExportServiceProvider, Http Export*), config/exports.php, migration
  2026_08_17_120000_create_export_runs_table · TallySync/Exports ×2 +
  QueryService cursor/count/history · Production/Exports ×6 + ShiftSummary
  Service::shiftsWithRecordsOn · Procurement (Exports ×4, List*Requests,
  ProcurementDocumentQuery, services paginate/cursor/count, controllers,
  *LineResource::showsCost) · Sales/Exports ×3 + services cursor/count ·
  frontend features/exports/*, lib/csv.ts, production/pages/ReportsPage.tsx,
  App.tsx, AppLayout.tsx · phpunit.xml memory_limit 512M · tests: Core/
  ExportCenterTest, Unit/Core/CsvStreamerTest, TallySync/TallySyncEntries
  ExportTest + TallySyncHistoryExportTest, Production/ProductionExportsTest,
  Procurement/ProcurementListFiltersTest + ProcurementExportsTest,
  Sales/SalesExportsTest, frontend exports/filters.test.ts

Migrations:       2026_08_17_120000_create_export_runs_table (additive)
Tests before:     1,276 / 9,429 (backend) · 127 (frontend)
Tests after:      1,352 / 11,879 (backend, +76) · 143 (frontend, +16)
                  agent untouched · pint/typecheck/build clean · knowledge sound

Sonnet first gate:   PASS_WITH_DEFERRED (P2: a reviewer's scratch tests in the
                     working tree — removed; P3s: report-button label wording,
                     double report compute, amount rounding vs the screen,
                     boolean form control tri-state, questions not in the Q list)
Findings (adversarial: Opus FC-06/rules PASS_WITH_DEFERRED · Fable correctness
PASS; no P1):
  P2  (Opus) shift_summary hand-copied its filter grammar (the ONE kind of 14
      that did) — "never a second grammar" unguarded. FIXED: a
      ShiftSummaryReportRequest read by BOTH ShiftSummaryController::report and
      the export kind.
  P2  (Opus) the Phase 4.5 log entry tripped scripts/factory-knowledge/check.sh:
      "RFC 4180" written with a hyphen matched the validator's unanchored FC
      pattern as a phantom constitution reference (the digits 41). FIXED both
      ways — "RFC 4180" in the docs/tests and a word-anchored regex in
      validate.py; check.sh exit 0.
  P3  a blocked kind was validated before it was refused (a bad body → 422,
      not the 409 reason) → blocked kinds now answer their reason first
      (ExportRequest::rules() → [] when blocked; test updated).
  P3  the report kinds computed the report twice (count + rows) → memoised per
      run (ExportService clones the kind per run so the memo dies with it).
  P3  CsvStreamer docblocks cited the deleted client-side csv.ts as the
      byte-for-byte authority → reworded: ported from the retired builder;
      CsvStreamerTest is the authority.
  P3  traceability export 409-with-reason vs the route's 404 when the flag is
      off → recorded as deliberate in the class docblock.
  P3  kinds order on the page (Tally first) → Production, Tally, Sales,
      Procurement.
  P3  (recorded) 403/404 write no ExportRun — right ("every AUTHORISED POST is
      audited"); mid-stream abort leaves completed=false/null reason and the
      page says "Not completed" — a trailer line in the file is a later touch;
      GRN export eager-load set is the list's (bounded by cap and per_page
      1000) — a lighter export profile is a later touch; instants in files
      are the resource's UTC ISO strings while the filename is factory time —
      documented, a rendering decision for later; PO/GRN per_page now 422
      outside 1..1000 (was clamped) — no frontend caller affected, external
      clients note; line `amount` bcmath half-up vs the screen's float
      toFixed(2) — the implementers' listed rounding question stands.
Fixes:               on the branch after the gate (this commit); suites re-run
                     green: 1,352 / 11,879 · 143 vitest · knowledge exit 0.
Sonnet final gate:   not re-run — no P1 was raised; the P2s are a grammar
                     delegation and a docs regex, both verified by the suite
                     and check.sh (recorded honestly here).
Independent review:  Opus PASS_WITH_DEFERRED · Fable PASS.

API proof (dev API, Administrator):
  GET /exports → 14 kinds with module/status/cap/filter schema; cec status
  blocked with the exact reason. As the tally-sync-only login the catalogue is
  [tally_sync_entries, tally_sync_history] only. POST sales_orders → 200
  text/csv, filename sales_orders-20260817-1101.csv (factory clock), header
  id,document_number,status,customer_code,… 2 rows; purchase_order_lines →
  unit_price + amount present for the Administrator (finance standing);
  goods_receipt_lines → unit_cost + amount; shift_summary(2026-08-01) → the
  day row; production_report → header only (no rows that day); tally_sync_
  history → 10 event rows, details JSON without error text; cec → 409
  {"message":"CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED","kind":"cec"};
  unknown kind → 404; GET /exports/runs → the caller's runs incl. the CEC
  refusal (completed false, reason) and each success (row_count, file_name).

Browser proof (Chrome MCP, vite dev, Administrator):
  /exports renders "Downloads" with the honesty line, cards grouped by
  module with generated forms (screenshot-1786944733970-17.jpg); the CEC card
  shows the warning "CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED" with a
  DISABLED Download button (screenshot-1786944756026-18.jpg); clicking
  Download on Sales orders POSTs and saves sales_orders-20260817-1103.csv
  (captured anchor download name); Recent downloads refreshes to show the
  completed run (2 rows) beside the CEC refusal with its reason.

Data/transaction proof:
  Read-only everywhere except export_runs (audit rows). Nothing that reaches
  Tally changed. No stock change. Migration additive.

Security proof:
  Catalogue permission-filtered (tests); 403 on POST without the kind's
  permission; runs are the caller's own; FC-06 per reader on tally, PO/GRN
  line and production files (tests); no hardcoded server sentence in the
  frontend.

Deferred items:
  • Shift KPI sub-tables (items manufactured, downtime, mould changes, power
    cuts, stock counts) as their own kinds; an all-zero row for a shift with
    nothing recorded (a question, listed).
  • The plan's enqueue/status shape once a worker exists.
  • traceability_report supplier_lot_no for production.view readers inherits
    the screen (a question, listed).

Owner-gated items:  CEC sample/format authority (HELD) — the slot ships blocked.
PR:                 #185 (base: feat/phase-4-agent-xml-snapshot → #184 → … → #179)
Deployment state:   not deployed; stack #179 → … → #184 → #185
Next phase:         5 — Product / SKU configuration (operator workflow, first slice)
```

## PHASE 5 — Product / SKU configuration (operator workflow, first slice)

```
Phase:    5 — Product / SKU configuration (MASTER-PLAN rev 3, P5-01..06)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-5-product-sku-configuration (stacked on Phase 4.5 PR #185 → … → #179)
Dates:    2026-08-17

Goal:
  Everything downstream reads configuration. Make the ERP able to HOLD one
  logical product → N SKUs/variants → each with its production configuration
  and exact Tally identity; name what is missing instead of asking the floor
  again; make ambiguity a review item, never a guess; keep every configured
  pack quantity behind ONE reader; make the stock ledger say why it moved and
  prove it against balances. The mapping VALUES stay the owner's (Q33, Q43,
  the SKU format programme).

What changed:
  • D1 (§4.12): psp_standard_mode_unique → psp_standard_variant_unique over
    the whole count tuple; a 490/box tray and a 520/box tray coexist on ONE
    standard (red-before: the old index refused); an exact twin is a 422
    domain error; at most one default per standard; the packaging store/update
    logic moved out of the controller into ProductionStandardPackagingService.
    With NULL count columns the DB unique never collides — the SERVICE's
    refusal is the twin guard; the index's real effect is removing the old
    (standard, mode) refusal (documented in the migration).
  • One pack-quantity reader (§4.14): PackQuantityResolver — frozen entry
    columns → the entry's packaging row → item master, with a named source;
    the metric path (~2907/2927) and BatchEstimationService read through it,
    byte-identical on every existing test; red-before proved the old metric
    reader ignored the packaging row for tray/pouch counts. `metrics.pack_
    quantities` and estimate `pack_quantity_source` additive.
  • packing_lines PERSISTED (§4.16): shift_production_entry_packing_lines —
    the full validated wire line, written in completeBatch's transaction,
    replaced on amend, on the resource.
  • Variant surface: GET production/products/{item}/variants — item →
    standards → packagings with the resolved Tally identity {sku, name, guid},
    state complete|incomplete with the missing pieces NAMED from a fixed
    vocabulary (standard, cavities, unit_weight, cycle_time, packaging,
    counts, tally_identity — tally_identity missing when the resolved identity
    is null, GUID-less or a LOCAL fixture), ambiguity via TallySync's
    LineMappingResolver (advisory); the same configuration_status rides the
    batch preview additively (+ sku/guid on tally_item).
  • Review queue: GET production/configuration/review — packagings without an
    identity, packagings whose Tally name is shared, items with a provisional
    SKU — each with candidate Tally-pulled non-fixture items to LINK through
    the existing PUT packaging endpoint (never a Tally-less item created,
    never a candidate pre-selected); `fix_target` names the affordance.
  • items.sku_provisional: the masters pull's CREATE path seeds a SKU from the
    Tally name and now says so; a manual SKU change clears it; no SKU format
    invented (HELD).
  • Ledger (§4.2/§4.3): stock_movements.purpose (opening | receipt |
    consumption | output | dispatch | adjustment | reconcile | unknown) set by
    the writer that knows (GRN, production consumption/output, delivery,
    Tally opening/reconcile) and back-filled ONLY where the reference shape is
    unambiguous (rule in the migration docblock; the rest 'unknown'; counts
    logged); a movement refuses update and delete (query-builder bulk deletes
    bypass by Eloquent design — production:reset-test-data relies on that,
    documented); inventory:check-ledger compares Σ movements to balances per
    (item, warehouse), read-only, exit 0/1; the invariant holds through the
    real GRN → issue → output → delivery paths (tested).
  • Frontend: Product Standards workspace shows each packaging's Tally identity
    (or "no Tally identity of its own — posts as the product's item"), an
    INCOMPLETE tag with the missing words, a "provisional SKU" tag; a
    "Needs review" panel fed by the review endpoint with the candidate picker
    (production.manage) that PUTs the packaging's item_id; the Shift Floor
    "How is it packed?" option for an incomplete packaging carries the
    server's words (8-line diff, disabling logic untouched).

Files/modules:
  Production (migrations ×2, ProductionStandardPackagingService,
  DuplicatePackagingVariantException, PackQuantityResolver + PackQuantities,
  ShiftProductionEntryPackingLine + resource, ProductVariantService,
  ConfigurationReviewService, controllers ×3, resources ×2, BatchPreview
  Controller, ShiftProductionEntryService, BatchEstimationService, models) ·
  Inventory (migration sku_provisional, migrations purpose + backfill,
  StockMovementPurpose, StockLedgerAppendOnlyException, StockMovement,
  StockMovementService, ItemService, Item, resources) · one-arg purpose
  additions in Procurement/GoodsReceiptService, Sales/DeliveryService,
  TallySync/TallyOpeningStockService + TallyStockReconcileService · Console/
  CheckStockLedger · routes · frontend production (productStandardsConfig.ts
  + test, ConfigurationReviewPanel.tsx, ProductStandardsPage.tsx,
  ShiftProductionEntryPage.tsx label, api.ts, types.ts; inventory/types.ts)

Migrations:       2026_08_17_130000 (index swap, reversible) · 130001 (packing lines) ·
                  131000 (sku_provisional) · 150000 (purpose) · 150001 (backfill; down nulls)
Tests before:     1,352 / 11,879 (backend) · 143 (frontend)
Tests after:      1,428 / 12,377 (backend, +76) · 190 (frontend, +47)
                  agent untouched · pint/typecheck/build clean · knowledge sound

Sonnet first gate:   PASS_WITH_DEFERRED (its P1: the importer keyed on
                     (standard, mode) — below)
Findings (adversarial: Opus NOT_READY · Fable NOT_READY — no invented value
found on any read surface; the two P1s were both WRITE paths):
  P1  An identity-only "Link" from the new review panel PUT mode + the
      unchanged inner counts + item_id, and the service re-derived
      nos_per_box = inner × containers on every update — an importer-flagged
      inconsistent row (105/pouch × 5 beside the sheet's 520, kept verbatim
      for a person to settle) silently became 525: a factory count the import
      refused to pick, picked by code (reproduced by two reviewers). FIXED:
      an identity-only PATCH …/packagings/{p}/identity that sets item_id and
      provenance and touches no count (fixture/inactive/GUID-less/foreign
      standard refused, production.manage); the service keeps a stored
      nos_per_box when the mode and inner counts are unchanged; the panel
      sends {item_id} only. Red-before 8/10 → 10/10.
  P1  D1 made `mode` non-unique per standard, but the completion drawer
      picked the run's packaging BY MODE (three sites) and the entry resource
      carried no packaging id — with a 490 tray and a 520 tray on one
      standard a batch started against the 520 tray got the 490 tray's counts
      pre-filled; quantity_produced is computed from the lines → FG stock and
      the voucher. FIXED: the resource emits production_standard_packaging_id;
      one helper packagingForCompletion (id first, mode fallback) at exactly
      the three sites (page diff 13+/5−); vitest.
  P2  ProductionStandardImportService updateOrCreate keyed on (standard,
      mode) → a re-import silently rewrote an arbitrary one of two same-mode
      rows (a person's 200/5/1000 tray became the sheet's 208/5/1040 —
      reproduced). FIXED: with more than one row of the mode the importer
      matches the inner-count tuple; exactly one → update; none/several →
      untouched and named in a warning (variant.packaging_warnings, summary,
      both import commands). Red-before 2 → 18/18.
  P3  candidates not filtered is_active → filtered · a shared Tally NAME
      cannot be cleared by linking → advisory (fix_target name_ambiguity, no
      Link, "catalogue duplicate — Q43") · packagingCountsSummary printed a
      false equation → the stored count shown separately and a mismatch
      named ("sheet says 520/box; 105/pouch × 5 = 525 — confirm which is
      right") · a packing line on a run without a standard could cite another
      product's packaging → nulled on write · report/active-batch queries
      lacked the packaging eager load → added · no lock on the standard row →
      lockForUpdate in store()/update() · migration docblocks (the new index
      is documentation of the variant key — the service refusal is the guard;
      the purpose backfill's down() reverses only with its column migration
      and only the backfilled values) · workspace showed the first-of-mode
      only → one line per same-mode packaging · soft-deleted tallyItem and
      migration round-trip pinned by tests · Q45 (must a standard keep one
      default packaging?) recorded.
  P3  (recorded) the resolver's packaging rung changes expected_boxes/pouches
      for entries completed WITHOUT a typed box count whose packaging differs
      from the item master — the intended §4.14 correction, byte-identical on
      every other rung and on the whole existing suite; Tally payloads read
      no pack count (TallySyncService::producedScrapLine reads rejection/lumps
      kg only) — stated precisely rather than "byte-identical" outright.
Fixes:               af8ad0c — all of the above; 1,428 / 12,377 · 190 vitest.
Sonnet final gate:   re-gate PASS — both P1 guards mutation-tested (revert →
                     the exact original bug reproduces, fix → green), the
                     importer's tuple match likewise; the page diff exactly
                     the named sites; agent untouched; knowledge exit 0. One
                     low note: on a pouch/tray PUT with unchanged inner
                     counts an explicit nos_per_box in the payload is ignored
                     in favour of the stored value (as before the fix — the
                     field was always derived for those modes); to settle a
                     disagreeing box count a person changes an inner count.
Independent review:  Opus NOT_READY → both P1s fixed · Fable NOT_READY → fixed.

API proof (dev API, Administrator; dev DB migrated — five migrations DONE):
  inventory:check-ledger → "VERDICT: clean — every stock balance equals the
  sum of its movements." (37 movements, 20 balances) exit 0.
  GET production/products/{1,2,3}/variants → configuration_status
  {complete:false, missing:[standard, tally_identity]} for the resins (no
  standards — honest), standards=0. GET production/configuration/review →
  103 rows on the dev seed (every product is a LOCAL- fixture): kind
  packaging_no_identity, missing [tally_identity], candidates [] (no Tally-
  pulled item bears the name), fix_target packaging_item / attach_item.

Browser proof (Chrome MCP, vite dev, Administrator):
  /production/configuration?tab=products → "Needs review — 103 packing
  identities still waiting on a person" panel with What / Product / Currently /
  Missing / Link an existing Tally item; the candidate cell reads "No Tally
  item matches this name. Tally has to carry the product first — the ERP
  never creates one." (screenshot-1786947771411-19.jpg).

Data/transaction proof:
  Nothing that reaches Tally changed (payload builders untouched; the pack-
  quantity reader is byte-identical on existing data). No historical
  production rewritten. Migrations additive/reversible (up/down/up proved on
  sqlite by the implementer). No stock change: purposes are labels; the
  backfill touches only `purpose`.

Security proof:
  New reads under module:production (production.view); the review panel's
  link action under production.manage; ledger command read-only.

Deferred items:
  • ProductionStandardImportService still updateOrCreates packagings keyed on
    (standard, mode) — with two same-mode rows a re-import updates the first
    found (noted; the import is the workbook path).
  • config_snapshot's start-frozen pack values are not a resolver rung (entry
    columns → live packaging row → item) — flagged for history preservation
    review.
  • The workspace endpoint carries no configuration_status yet — the table
    tag is derived client-side by the SAME rule; embed the server verdict
    later.
  • FinishedCartonService/Resource labels read config_snapshot (a different
    precedence) — not routed through the resolver; ProductReadinessService /
    SalesCostInsightService gates untouched.

Owner-gated items:  Q33 (490/box mapping), any ambiguous SKU→Tally link, the SKU
                    format programme, Q43 (block vs warn); "must a standard
                    keep one default packaging" — listed, not decided.
PR:                 #186 (base: feat/phase-4.5-export-center → #185 → … → #179)
Deployment state:   not deployed; stack #179 → … → #185 → #186
Next phase:         5.5 — Shift Floor → Start → Estimation → Complete → Completed Today
```

## PHASE 5.5 — Shift Floor → Start → Estimation → Complete → Completed Today

```
Phase:    5.5 — the operator's slice (MASTER-PLAN rev 3, P5.5-01..06)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-5.5-shift-floor (stacked on Phase 5 PR #186 → … → #179)
Dates:    2026-08-17

Goal:
  Make each of the audit's already-true Shift Floor facts (§49–§53) a tested
  contract and fix the real defects: two estimation formulas → one,
  versioned; completion durable with an event and its packing defaults;
  Completed Today served by the server (no client slice of page 1) with the
  approval/Tally state; Start Batch asks nothing configuration already knows
  and names what is missing. Owner-decided inputs untouched; historical
  entries never recomputed.

What changed:
  • ONE estimation formula, versioned (P5.5-03): ProductionCalculationEngine
    for both the preview and the entry metrics under production_v3_unified —
    cycles floored before cavities multiply; the entry side nets recorded
    downtime (engine targets(): running hours as scheduled, completion
    downtime as unplanned); efficiency piece-grain 1 dp; entries stamped
    production_v2_floor / legacy / null keep their previous inline computation
    byte-for-byte (LegacyEntryMetrics, golden-pinned from the divergence
    cases); new batches stamp v3 at start; the preview says calculation_
    version + downtime_netted:false; metrics gain calculation_version,
    downtime_netted, expected_pieces_gross, downtime_impact_pieces; the
    engine's dead targets()/efficiencies() are WIRED; the client's pre-submit
    mirror (features/production/expectedOutput.ts) follows the ENTRY's
    version (13330 under v3 where the unfloored legacy read 13333.33).
  • Completion durable (P5.5-02/04): ShiftProductionEntryCompleted raised
    after the OUTER commit (once per completion/amend, never start/cancel,
    dropped on rollback, no Tally listener — the approval event stays the
    Tally trigger); the resource carries packing_defaults (PackQuantity
    Resolver — the typed counts after completion) and configuration_gaps
    (computed at read time from the frozen standard/packaging via
    ProductVariantService::runStatus; a start-time snapshot outranks it when
    written); CompleteBatchRequest docblocks and attributes() name each field
    for what it is (nos_per_box = the carton actually packed on this run;
    quantity_produced = pieces; the "420 example" replaced) — rules byte-
    identical.
  • Completed Today server-side (P5.5-05): ListShiftProductionEntriesRequest
    (production_date · date_from/date_to · work_center_id · shift_id ·
    batch_status · status · per_page ≤ 100; a bad status is a 422 where it
    500'd; unknown keys ignored; the old positional paginate() still works);
    the resource carries `tally` — the shift voucher the entry rides
    (tally_sync_entry_id → TallySyncLinkService::forEntryIds) else its own
    batch voucher (forMany), null when neither — three tally reads a page,
    pinned; the page reads today's completed batches (production date of the
    effective shift, batch_status=completed, per_page 100, refetch 20 s) into
    CompletedTodayTable (machine · shift · SKU · expected · actual · good ·
    reject(+QC) · efficiency · Approval·Tally with deep link · config-
    incomplete tag) through a pure row mapper that recomputes nothing; the
    `.slice(0,15)` and the unfiltered entries query are gone; the pending-all
    loop remains for awaitingCorrection/correctableEarlier.
  • Start Batch asks nothing already known (P5.5-01/06): startBatchChoices()
    — one pure helper for the two radios, pinned against the PREVIOUS inline
    conditions carried verbatim in the test (0/1/N variants × 0/1/N
    packagings × complete/incomplete: "ask only when there is a real choice"
    preserved); the modal's loaded-from-preview Descriptions gain "Tally
    identity" (this packing's own item / posts as the product's item) and
    "Mould" (the preview's new configuration.mould from the resolved
    ProductionConfiguration; null when none); an incomplete configuration is
    named in an Alert with the server's words — Start stays enabled.

Files/modules:
  Production (EntryExpectedOutput, LegacyEntryMetrics, UnifiedEntryMetrics,
  ProductionCalculationEngine, BatchEstimationService, ShiftProductionEntry
  Service [ctor · stamp · productionMetrics · completeBatch afterCommit ·
  paginate], ShiftProductionEntryCompleted event, CompletionDefaultsService,
  ListShiftProductionEntriesRequest, controller index, resource [tally ·
  packing_defaults · configuration_gaps · calculation_version], Complete
  BatchRequest docblocks, BatchPreviewController mould, ProductVariantService
  runStatus) · TallySync/TallySyncLinkService::forEntryIds (additive) ·
  frontend production (startBatchChoices.ts + test, expectedOutput.ts + test,
  completedToday.ts + test, components/CompletedTodayTable.tsx, api.ts,
  types.ts, ShiftProductionEntryPage.tsx — hunks only at the named ranges)
  · tests: EstimationUnifiedTest, CompletionEventAndDefaultsTest,
  EntriesIndexFiltersTest, BatchPreviewMouldTest, + v3 arms in
  ExpectedOutputDivergenceTest / ProductionCalculationEngineTest /
  ExpectedOutputEngineTest, CompletionDowntimeTest pin (a NEW batch is v3)

Migrations:       none
Tests before:     1,428 / 12,377 (backend) · 190 (frontend)
Tests after:      1,502 / 13,167 (backend, +74) · 269 (frontend, +79)
                  agent untouched · pint/typecheck/build clean · knowledge sound

Sonnet first gate:   FAIL (its P1: the configuration_gaps N+1 on the list; P2
                     the snapshot with no writer; P3s)
Findings (adversarial: Opus formula/history NOT_READY · Fable correctness
PASS_WITH_DEFERRED — the history claim HELD: Opus reconstructed the old
inline arithmetic and compared LegacyEntryMetrics over 871,563 seeded cases,
zero divergence; every changed expected value in the diff is a NEW batch
(v3); Tally readers untouched; the radios' conditions verbatim):
  P0  (Opus) handover() created the child segment WITHOUT calculation_version
      — the second half of a run computed under the legacy formula while its
      parent and its own preview computed under v3 (parent 13580.00 vs child
      13584.91 for identical inputs): the exact preview-vs-completion split
      this phase exists to close, on a routine three-shift flow. FIXED: the
      child inherits the run's stamp; a guard test that every entry-creation
      path stamps.
  P1  (Sonnet/Fable) configuration_gaps on every list row cost +1 packagings
      query per distinct standard (no frozen packaging) and +2 items queries
      per row (identity + ambiguity lookups; the resolver's memo died with
      each per-row service instance) — a 100-row page polled every 20 s.
      FIXED: eager loads, a missing-only path for runStatus, and the snapshot
      written at Start so new entries compute nothing; the query pin now
      counts TOTAL queries per page.
  P1  (Opus/Sonnet) configuration_gaps had a reader with no writer — every
      read was 'live', so a finished batch's "config incomplete" tag would
      restate itself when a master was edited later — the retroactive
      restatement the version stamp exists to prevent. FIXED: startBatch
      writes config_snapshot['configuration_gaps'] (runStatus over the frozen
      standard/packaging); the handover child copies it; legacy entries stay
      'live' and say so.
  P1  (Opus) the client mirror's v3 floor used FLOAT division: CT 10.8 × 12 h
      → 3999 cycles (19,995) where the server stores 4000 (20,000) — the
      owner's own cycle time; and the page netted downtime as a float where
      the server truncates downtime hours to 6 dp. FIXED: integer-scaled
      cycle arithmetic and a netRunningHours mirror; vitest at CT 10.8 (6 h /
      8 h / 12 h), 2.7, 5.4, the 1-minute-downtime case, the legacy branch.
  P2  whereDate on the DATE column defeated spe_date_shift_wc_index → plain
      where. · The Start modal named the STANDARD's union of gaps (every
      sibling packaging) while the entry freezes the RUN's → the preview
      emits a top-level configuration_status at the run's grain (standard's
      verdict while a choice is open, `grain` stated). · efficiencyBandFor
      hard-coded the 100 % ceiling where the approval screen reads
      tolerances.efficiency_over → the ceiling is passed.
  P3  tooltip printed raw vocabulary keys → worded · types for the new keys ·
      stale comment · a handover-specific event test · paper-page ingest of a
      shift that already ran now stamps v3 (the ERP's figure may differ from
      the paper's WB2 arithmetic) → **Q46** recorded, behaviour unchanged.
Fixes:               ed0675a — all of the above (P0 · 3×P1 · 3×P2 · P3s); Q46.
Sonnet final gate:   re-gate PASS — the handover stamp mutation-tested (removed →
                     three tests red, restored → green); the snapshot's
                     `complete` derived from `missing`, never trusted from a
                     stored key; the N+1 pin flat 2 vs 8 rows; the mirror
                     probed live; agent untouched; knowledge exit 0. Two
                     informational P3s: the client mirror returns null (not
                     0) for zero hours (pre-existing guard) and its micro-unit
                     arithmetic degrades only past 1e15 h (unreachable —
                     running_hours ≤ 24 on the server).
Independent review:  Opus NOT_READY → P0/P1s fixed · Fable PASS_WITH_DEFERRED →
                     P2s fixed.

API proof (dev API, Administrator):
  GET shift-production-entries?batch_status=completed&per_page=100 → 200
  (the dev DB holds no entries — rows 0, meta.total 0, honest); status=bogus
  → 422 "The selected status is invalid."; per_page=101 → 422; unknown=1 →
  200. Preview for item 15 (BTL-100-RND, two standards × two packagings):
  estimation.calculation_version production_v3_unified, downtime_netted
  false, expected_pieces 11705, expected_boxes 14; per-variant/packaging
  configuration_status complete; configuration.mould null (no configuration
  row on the dev seed — honest); the variants endpoint agrees (identity via
  the product's GUID'd item).

Browser proof:
  The Chrome extension disconnected mid-session before the Start modal /
  Completed Today screenshots; the behaviour is pinned instead by vitest —
  startBatchChoices 27 cases including the previous inline conditions
  carried verbatim, expectedOutput 5 (13330 / 13333.33 pair), completedToday
  13 — and by 18 backend index tests. To be re-proven in Phase 8's chain walk.

Data/transaction proof:
  Nothing that reaches Tally changed (TallySyncService::producedScrapLine
  reads confirmed_rejection_kg + lumps_kg only; TallySync suite green). No
  migration. Historical entries keep their stamped formula (golden pins).
  The one existing pin that moved — CompletionDowntimeTest 13333.33 → 13330
  — is for a batch started NOW (v3), stated.

Security proof:
  Filters validated; the entries index stays under module:production; the
  TallyLink on the entry carries status/flags/link only (Phase 3.5's shape).

Deferred items:
  • A start-time snapshot of configuration_gaps in config_snapshot (today
    computed live from the frozen standard/packaging; a snapshot outranks it
    when written) — one key in startBatch, later.
  • The pending-all 25-page loop still feeds awaitingCorrection/
    correctableEarlier — a server filter for those lists next.
  • frontend BatchEstimation/ProductionMetrics types could name the new v3
    keys (calculation_version, downtime_netted, expected_pieces_gross,
    downtime_impact_pieces) — additive.

Owner-gated items:  none new (cycle times, cavities, weights, pack counts and
                    the est-box / packing rounding policies stay as decided).
PR:                 #187 (base: feat/phase-5-product-sku-configuration → #186 → … → #179)
Deployment state:   not deployed; stack #179 → … → #186 → #187
Next phase:         5.7 — Shift Summary + CEC infrastructure
```

## PHASE 5.7 — Shift Summary contract + CEC infrastructure (format BLOCKED)

```
Phase:    5.7 — Shift Summary + CEC infrastructure (MASTER-PLAN rev 3, P5.7-01..03)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-5.7-shift-summary-cec (stacked on Phase 5.5 PR #187 → … → #179)
Dates:    2026-08-17

Goal:
  Make ShiftSummaryService::report() (zero tests before this phase) a tested
  contract — historical dates, Shift A/B/C, All Shifts, totals reconciling
  with completed production — and build everything AROUND the CEC that can be
  built without its format: the data endpoint, the golden-file harness, the
  preview. The CEC FORMAT stays BLOCKED — SOURCE DOCUMENT REQUIRED; no layout
  is invented anywhere; the export slot stays 409-BLOCKED.

What changed:
  • Shift Summary contract (P5.7-01): ShiftSummaryReportTest — 12 tests on a
    frozen clock (2026-08-17) over a historical day (2026-08-03) with three
    shifts, three machines, two items, batches through startBatch/
    completeBatch and logs through the log services: per shift
    actual_production_kg == Σ completed batches' quantity_produced_kg == Σ of
    GET shift-production-entries?production_date&shift_id&batch_status=
    completed; a running batch adds no kg; each downtime/mould/power/stock
    log lands in its shift only; the day == Σ three shifts to the digit for
    every kg figure (ratios recomputed from day totals); efficiency null
    without a supervisor target and the basis stated; empty date → honest
    zeros/nulls; a past date is served. Honesty keys, additive only:
    efficiency_basis ('supervisor_target'|null), kpi_inputs {target_
    production_kg, power_consumption_units: 'supervisor'|null}, machines_
    running_now / machines_down_now (the old keys kept as aliases one release;
    the docblock says they read CURRENT machine state, not the date's). Zero
    arithmetic change to any existing key. No aggregation defect was found —
    day == Σ shifts held before the phase; idle hours per shift are truncated
    from minutes and the day from raw minutes (pinned as behaviour).
  • CEC infrastructure (P5.7-02): CecReportService — a THIN composition: per
    shift the Shift Summary report VERBATIM + the completed entries of that
    date/shift read through ShiftProductionEntryResource (paginate walked at
    100 a page), grouped by machine in picker order → batches {expected_
    pieces, actual_pieces, good_production_kg, rejection_kg (= the
    production-side figure the Shift Summary sums), rejection_kg_qc,
    efficiency_pct/band, expected_boxes, packs, downtime_minutes_total,
    calculation_version, approval_status, tally_status, tally}; sums per
    machine/shift/day are plain bcadd sums with nulls skipped and counted
    (skipped_nulls, always an object) and a basis sentence; efficiency never
    summed; the response says format: 'BLOCKED — SOURCE DOCUMENT REQUIRED'
    and figures_from. GET production/cec (date required Y-m-d, shift_id
    optional; module:production). CecGoldenTest reads tests/fixtures/cec/
    *.golden.csv with a sibling .golden.json reading guide + .seed.php and
    asserts sample == CEC == Shift Summary == entries index; with no sample
    it SKIPS with exactly "CEC sample not on file — format authority is the
    owner's"; a csv without its guide, a guide naming a missing seed, an
    unknown key, or ANY unrecognised file in the directory FAILS loudly.
    README.md says only the owner's sample goes there. CecExport stays
    409-BLOCKED (ProductionExportsTest pins the reason). paginate() gained
    an optional $page (every existing caller byte-identical).
  • Page (P5.7-01/02): "Completed batches vs summary" line — Σ completed
    entries (the entries index walked to last_page) vs the report's
    actual_production_kg at 4 dp (BigInt, no float): '✓ equal (N batches)'
    or 'differs by X kg' with both figures, or 'not compared' when the walk
    hit its 25-page bound; machines (now) labels; efficiency title from
    efficiency_basis; CecPreviewPanel captioned EXACTLY 'CEC preview — format
    pending: owner sample required', the server's format string verbatim,
    shift → machine → batches from the server's figures, NO download; the
    panel's read waits for the shift to resolve.
  • P5.7-03 (reporting honesty sweep on the other Production reports) —
    NOT DONE this phase; carried to Phase 7's regression (recorded below).

Tests:
  Backend 1,502 → 1,526 (1 skipped: CecGoldenTest by design) / 13,602
  assertions: ShiftSummaryReportTest 12 · CecReportTest 11 · CecGoldenTest 1.
  Frontend vitest 269 → 304: shiftSummaryReconcile 18 · cecPreview 17.
  Typecheck/build clean. Knowledge validator sound (Q47 added).

Independent QA + adversarial review (Sonnet QA · Opus rules · Fable
correctness) — all three PASS_WITH_DEFERRED, no P0/P1/P2:
  Sonnet built its OWN fixture (amended entry, handover parent+child,
  awaiting-correction, cancelled, in-progress on one date/shift): report ==
  index == CEC at 125.0000 kg, cancelled/in-progress correctly excluded; 42/42
  field-for-field CEC-vs-index assertions on a discriminating fixture (real
  standard so expected_pieces/efficiency non-null, QC weight ≠ production
  rejection, a completion downtime line); the exact skip message captured
  byte-for-byte; a stray csv made the harness FAIL loudly; empty date → 200
  honest zeros. Opus confirmed the reconcile predicate parity (whereDate vs
  half-open range on the same DATE column, same status set, shift_id NOT
  NULL, no soft-delete scope), existing report keys byte-identical, and that
  the CEC's rejection_kg IS the field the summary sums.
  P3s fixed on the branch: reconcile line honest when the 25-page bound cuts
  the walk (was: a false 'differs'); CecPreviewPanel `enabled` gate (was: a
  day-wide read before the shift resolved); skipped_nulls always `{}`; golden
  harness fails on an unrecognised file; 'blank until a non-zero target is
  typed'.
  Recorded, not fixed (out of ownership / performance): the entries resource
  costs two queries per completed batch (materialCost stock_movements + bag
  allocations) — inherited by Completed Today (5.5) and the CEC; a
  batching/variant follow-up (Phase 7 P7-03).

API proof (dev API, Administrator; the dev DB holds no entries → honest):
  GET production/shift-summaries/report?production_date=2026-08-03 → keys
  efficiency_basis null, kpi_inputs {null,null}, machines_running_now 0 (old
  keys equal). GET production/cec?date=2026-08-03 → format 'BLOCKED — SOURCE
  DOCUMENT REQUIRED', figures_from [shift_summary, shift_production_entries],
  shifts [], day.sums nulls with skipped_nulls {}. Validation: no date → 422,
  03-08-2026 → 422, shift_id 999 → 422. GET exports → cec kind status
  'blocked', reason 'CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED'.

Browser proof:
  NOT DONE (extension still disconnected); the page behaviour is pinned by 35
  vitest cases and the endpoint by 24 backend tests + Sonnet's independent
  fixture. To be re-proven in Phase 8's chain walk (Shift Summary → CEC link).

Data/transaction proof:
  Nothing that reaches Tally changed (TallySync suite green; no payload
  touched). No migration. Historical figures never recomputed (the CEC reads
  the calculation_version-stamped metrics).

Owner-gated items:
  • CEC FORMAT — BLOCKED — SOURCE DOCUMENT REQUIRED (unchanged; the harness
    is ready for the sample + a reading guide the day it lands).
  • Q47 — which rejection figure the CEC sheet carries (production-side, QC-
    weighed, or confirmed): both exposed; the guide decides; NOT chosen here.

Deferred items:
  • P5.7-03 reporting honesty sweep (production / reconciliation /
    traceability reports server-side, filters honoured) → Phase 7 P7-05.
  • ShiftSummaryExport columns still read the alias keys (machines_running/
    down); rename to *_now with the header pin when the aliases are dropped.
  • Per-entry cost reads on the entries resource (2 queries/batch) → P7-03.
PR:                 #188 (base: feat/phase-5.5-shift-floor → #187 → … → #179)
Deployment state:   not deployed; stack #179 → … → #187 → #188
Next phase:         6 — Purchase → GRN → lot → inventory → consumption; PO → Tally staged, flag OFF
```

## PHASE 6 — Purchase → GRN → lot → inventory → consumption; PO → Tally staged, flag OFF

```
Phase:    6 — the purchase chain, and the first ERP-raised Tally voucher, staged
          (MASTER-PLAN rev 3, P6-01..03; MASTER-PROMPT-AUDIT §7.4)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-6-purchase-chain (stacked on Phase 5.7 PR #188 → … → #179)
Dates:    2026-08-17

Goal:
  Make the accounting half of the final chain a tested contract — PO → GRN →
  material lot → stock movement → balance → production consumption — give the
  purchase order the lifecycle it never had (amend, short-close, cancel, show,
  trace), and STAGE the ERP-raised Purchase Order voucher for Tally behind a
  flag that defaults OFF. The first live PO write is an owner gate (Q35(d)) and
  never happens unattended; it did not happen here.

What changed:
  • PO lifecycle (P6-01/02): amend is Draft-only and records the prior lines as
    a revision; close (Sent|PartiallyReceived → Closed) and cancel (Draft|Sent
    with zero receipts → Cancelled, bringing a dead enum case to life) each take
    a reason and record who and when; a Tally-originated mirror refuses all
    three ("change it in Tally") — the ERP never rewrites Tally's book. All are
    POST actions: no PUT, no DELETE, anywhere. send() now raises
    PurchaseOrderSent after the commit. GET purchase-orders/{po} carries the
    revisions, the receipts, the Tally link, the staging record and `can`
    {amend, close, cancel, send} computed by the service, so the screen never
    re-derives the state machine; GET .../trace walks PO → GRNs → movements
    (with purpose) → lots → bags → day-bin loads → the consuming segments,
    through the Inventory and Production SERVICES, never their models. GET
    goods-receipts/{grn} likewise. FC-06 holds on every one of them: a
    procurement-only reader gets the key OMITTED and a `rate_withheld` note,
    never a blank that would read as "no rate".
  • The chain as a contract (P6-01): three test files walk the REAL endpoints
    and services — create → send → partial receipt → over-receipt refused by
    LINE (naming the remaining to 4 dp) → exact remainder → Closed → a receipt
    against a Closed order refused by STATUS before quantity is even read;
    after every step the ledger invariant holds (Σ signed movements ==
    balances, and `inventory:check-ledger` exits 0), one material lot per
    receipt line, every movement's purpose is Receipt, and exactly ONE Receipt
    Note per GRN. receipt_key idempotency is proved across the WHOLE chain:
    the same request twice yields one GRN, one movement set, one lot set, 16
    bags once, and ONE Tally entry.
  • PO → Tally, staged (P6-03): `tally-sync.purchase_orders_enabled` defaults
    FALSE and nothing outside an in-test config() sets it. With it off, send()
    enqueues nothing and the order says so (`tally_staging.state = 'disabled'`).
    With it on, enqueuePurchaseOrder REFUSES rather than guesses — party_
    unmapped (the vendor has no Tally ledger name), item_unmapped, purchase_
    ledger_unmapped (the Purchase role, not an env default), godown_unresolved,
    no_lines — and the refusal is recorded on the order for the screen to say
    out loud. DEC-20260812-002's "must not touch accounts or stock, stated in
    the code and proved by a test" is proved on BOTH sides: the cloud counts
    ten tables before and after (a stray stock write would fail the assertion),
    and the agent pins VCHTYPE/VOUCHERTYPENAME 'Purchase Order' with
    ISINVOICE=No. The agent's builder (0.3.9, NOT published) was derived from
    the STRUCTURE of 107 real Purchase Order vouchers the owner exported on
    12-Aug — tag order, the sign of every amount, the ORDERDUEDATE JD/P pair —
    read locally and never copied into the repo (Q38). Its golden fixture is
    synthetic.
  • Cancelling or closing an order now WITHDRAWS its staged queue row if the
    agent has not collected it yet, so the agent can never post an order the
    ERP knows is cancelled; if Tally already has it, the entry is left alone
    and the fact is recorded — what Tally should be told is Q48.

Tests:
  Backend 1,526 → 1,595 (1 skipped: the CEC golden, by design) / 14,633
  assertions. Frontend vitest 304 → 368. Agent 119 → 122. Knowledge sound.

Independent QA + adversarial review (Sonnet QA · Opus rules · Fable correctness):
  Sonnet PASS with zero findings — it rebuilt the state-machine matrix itself
  (8 reachable status × mirror combinations driven through the real endpoints,
  asserting the `can` flags never disagree with the actual HTTP outcome),
  fired the real PurchaseOrderSent event twice to prove one entry, counted the
  trace's queries (flat from 1 GRN to 2 GRNs/3 lines/6 bags), and recomputed
  the ORDERDUEDATE JD epoch independently in Python against the golden.
  Opus and Fable both returned FAIL, and both were right:
    P1 · FC-06/Q38 — the redaction commit's own message claimed completeness
      it did not have: a SECOND supplier from the same export was still named
      in an evidence note, with an order number and a quantity. Redacted; a
      full name sweep (every party name extracted from the exports locally and
      grepped across docs, backend, frontend and the agent) now returns zero.
    P2 · An under-scheduled PO line staged allocations no real voucher has:
      quantities that did not sum to the line and a last amount that was not
      its own quantity × rate. Each schedule is now its own quantity × rate and
      the unpromised remainder is one more allocation — undated when the order
      has no expected date, because a due date is never invented.
    P2 · The trace showed every receipt the movements of every receipt sharing
      a reference (two blank-reference GRNs each listed both). Receipt lines
      now carry stock_movement_id and the trace reads by id, saying `by_id` or
      `by_reference` per row so legacy receipts stay honest.
    P2 · The trace drawer claimed "nothing has been consumed" where the truth
      is that consumption is not ATTRIBUTABLE — under the common input a bag
      belongs to no machine and no batch (FC-01). The words now say that.
    P3s · the documented reason set matched to the emitted one; the parallel-
      build scaffolding removed so a renamed route fails instead of skipping;
      the trace fixture writes its legacy machine-stamped load as the
      historical row it is (DEC-20260807-006) instead of through the live door;
      the contract doc's measured counts corrected (105/107, not 107/107) and
      the second JD pattern stated; the tally-sync catalogue fixtures updated
      to the shape actually served.

API proof (dev API, Administrator):
  Recorded in the re-gate; the flag-off path is proved through the REAL send()
  (HTTP and service): zero tally_sync_entries, tally_staging.state 'disabled'.

Browser proof:
  NOT DONE (the Chrome extension is still disconnected). The procurement screens
  are pinned by 50 vitest cases over the pure helpers and by the backend suite;
  to be re-proven in Phase 8's chain walk.

Data/transaction proof:
  Nothing that already reaches Tally changed (the Receipt Note payload and
  builder are byte-identical; the TallySync and agent suites are green). Three
  additive, reversible migrations (purchase_order_revisions; the close/cancel
  and tally_staging columns plus vendors.tally_ledger_name;
  goods_receipt_note_lines.stock_movement_id — no backfill).

Owner-gated items:
  • Q35(d) — does the accountant want an ORDER voucher in Tally at all? The
    flag stays FALSE until answered; the first live post is attended.
  • Q35(c) — whose PO number is authoritative. Staged default is the ERP's
    own 'PO-{id}', stated as provisional in the contract doc and the builder.
  • Q35(e) / Q39 — which purchase/tax/rounding ledgers a PO voucher names. No
    tax and no rounding line is emitted; one Purchase role, or a refusal.
  • Q40 — the unit. No unit suffix is emitted; bare decimals, as the live Stock
    Journals already post.
  • Q48 (NEW) — once Tally has the order, what should reach it when the ERP
    amends, short-closes or cancels it? Nothing is sent today.

Deferred items:
  • The ORDERDUEDATE JD/P pair disagrees on 44% of real allocations; the
    builder derives both from the one ERP due date and the contract doc states
    the disagreement. The first ATTENDED live post must read the due dates back
    before a second order is sent.
  • The machine-stamped `POST production/day-bin/load` write door still exists
    though DEC-20260807-006 retired that path — engineering cleanup measured
    against a decision in force, not an owner question (Phase 7/8).
  • `match: 'by_id'|'by_reference'` is not yet typed in the frontend's
    procurement types (additive, typechecks clean).
PR:                 #189 (base: feat/phase-5.7-shift-summary-cec → #188 → … → #179)
Deployment state:   not deployed; stack #179 → … → #188 → #189
Next phase:         7 — regression, MySQL CI leg, reporting honesty, hardening
## PHASE 7 — Regression + reporting honesty + hardening

```
Phase:    7 — the suite runs on the driver the factory runs on
          (MASTER-PLAN rev 3, P7-01..05)
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-7-regression-hardening (stacked on Phase 6 PR #189 → … → #179)
Dates:    2026-08-17

Goal:
  Close the standing gaps the earlier phases kept recording: run the suite on
  MySQL as well as sqlite (live is MySQL and the suite had never met it), pay
  off the deferred hardening from Phases 3–5.7, make the reports say when they
  are showing part of a range, and put a regression smoke under the whole read
  surface so a route added tomorrow is walked the day it lands.

What changed:
  • The MySQL CI leg (P7-02). ci.yml gains `app-mysql`: the same suite against
    a real MySQL 8 service. No second phpunit config was needed — PHPUnit
    applies a non-force <env> only when the variable is absent from the
    process environment, so a job-level DB_CONNECTION wins over the pinned
    sqlite. The deploy gate stays sqlite deliberately (it is the fast leg) and
    its comment, which had claimed a DB service could not override the pinned
    values, is corrected. Tests\TestCase gains assertSameJson: MySQL's native
    JSON type normalises object keys (by length, then bytes) where sqlite
    returns them as written, so an assertSame over a payload row was pinning
    the driver rather than the contract. It sorts associative keys on both
    sides and still asserts list ORDER, every key's presence and every value —
    it is deliberately not assertEqualsCanonicalizing, which would lose which
    value sat under which key.
  • TWO REAL dev-vs-live differences the leg exposed. `accepted_quantity` (the
    batch cost summary) and `actual_pieces` (metrics, the production report,
    the CEC, the exports) both came from `(string) $entry->quantity_produced`
    — a decimal(15,4) column with NO Eloquent cast, so the raw driver value
    was published: live (MySQL) has always emitted '5880.0000' while dev
    (sqlite) emitted '5880', and the tests documented dev. Both are normalised
    to the 4-dp form live already emits: LIVE BEHAVIOUR IS UNCHANGED, dev now
    matches it. Four test expectations that had pinned the in-memory shape
    moved with it. This is the class of defect the leg was built to find.
  • Deferred hardening paid off (P7-03): the entries index cost two queries
    per completed batch (one stock_movements read, one bag-cost read per row)
    and now costs one of each PER PAGE, pinned by a query-count test at N=1
    against N=60 — Completed Today and the CEC inherit it; a snapshot show
    cap; TallySyncLinkService ranking so a synced older entry outranks a
    pending newer one for legacy duplicates; ShiftSummaryExport's honest
    *_now columns; and the two work-queue filters (awaiting_correction,
    correctable) applied in SQL BEFORE the page is cut, with a parity test
    against the resource's own derivation. The frontend's 25-page walk over
    every pending entry is replaced by those filters.
  • Reporting honesty: the reconciliation and traceability reports carry
    `row_cap` and `truncated`, so a range wider than the cap is cut but never
    silently — a partial list can no longer read as a whole period. A cap of
    zero cannot produce an empty report (the floor is one row).
  • Regression smoke (P7-05): every parameterless GET under /api/v1 answers
    401 unauthenticated and NEVER a 5xx to an administrator, on an empty
    database and on a fully seeded one; every parameterised GET is either read
    against a fixture (all 31) or named in SKIPPED — which is EMPTY, so
    nothing is unclassified; auth, roles and per-module index/permission
    coverage; and the SPA's route table is pinned.
  • P7-04 (ingestPage) is deliberately NOT declared "API-only". The endpoint
    works, is tested, and has no screen; the priority it was built for is
    quoted in its own docblocks from a 05-Aug discussion, and a discussion is
    not a decision (AGENTS.md) — so whether the factory still wants the page
    screen is owner question Q49. Two tests pin today's truth in both
    directions: no screen calls it, and the endpoint is still registered.

Tests:
  Backend 1,595 → 1,661 (1 skipped: the CEC golden) / 15,382 assertions —
  green on BOTH drivers: sqlite AND a real MySQL 8, verified locally against a
  mysql:8.0 container AND in CI (the new `app-mysql` job ran green on PR #190
  in 5m51s, run 32038255491 — its first real run). Frontend vitest 368 →
  383. Agent 122 → 135 (snapshot journal + retry: a Tally answer captured
  while the cloud was down is no longer dropped). Knowledge sound.

Independent QA + adversarial review:
  NOT YET RUN for this phase — the phase was integrated by hand after a
  session limit interrupted three of its four implementers mid-flight, and the
  gate is the next act. What IS proven: both driver legs green, every leg's
  counts reproduced, and the four MySQL failures that remained after the
  implementers stopped were diagnosed and fixed by the integrator (three were
  driver-portability in tests — JSON key order and sqlite's identifier
  quoting; one was the real published-shape difference above).

Data/transaction proof:
  Nothing that reaches Tally changed. One migration, additive, from an
  implementer's MySQL work (a widened quality/scrap note column).

Owner-gated items:
  • Q49 (NEW) — is the "type a whole paper page in one go" screen still
    wanted, or is the endpoint dead weight to retire?

Deferred items:
  • The gate for this phase (Sonnet QA + adversarial) — next.
  • The new `app-mysql` check must be added to branch protection by the
    repository owner; an agent cannot set that.
  • Still open from the deferred lists: `needs_review` status, the
    unvalidated_builder flag, the "queued payload predates the document"
    flag, delivery `delivered_date` raw ISO, snapshot_count on the list, the
    90-day retention nobody has been asked about, the shift-KPI sub-table
    export kinds, the import updateOrCreate on (standard, mode), and
    FinishedCartonService label precedence.
PR:                 #190 (base: feat/phase-6-purchase-chain → #189 → … → #179)
Deployment state:   not deployed; stack #179 → … → #189 → #190
Next phase:         7.5 — Store → Production material flow (the lead's
                    business-rule correction of 17-Aug), then 8 acceptance
```

## PHASE 7.6 — The Configuration Lifecycle Contract (Tier 0)

```
Phase:    7.6 — the lead's product-wide configuration contract, mechanism first
Status:   PASS WITH DEFERRED ITEMS
Branch:   feat/phase-7.6-configuration-lifecycle (stacked on Phase 7 PR #190 → … → #179)
Dates:    2026-08-17

Goal:
  Make every applicable master behave the same way — Create → View → Edit →
  Activate/Deactivate → Safe Delete → Audit — with the delete guard enforced in
  the BACKEND, one shared policy rather than per-page logic, and duplicate
  guards. This pass ships the MECHANISM and the live-facing fixes; no entity is
  wired and no route is exposed. The duplicated warehouses are one test case of
  the contract, not the contract itself.

What changed:
  • The mechanism (app/Support/Configuration): DependencyCheck (declarative —
    a table+column, a callable, an attribute; with cascadeSide() and
    includeTrashed()), DependencyReport, ConfigurationLifecycle,
    ConfigurationInUseException, and a trait so a module declares only its own
    checks. The 422 carries code=configuration_in_use, `blocking` with integer
    counts, `unprovable`, `cascade_gaps` and `alternative`, so the UI renders
    "used by 12 stock movements and 2 production batches" from DATA.
  • THE HARD DELETE IS REAL (DEC-20260817-002 §1): a record whose LOCKED report
    is clear is destroyed outright, which is what frees its business code (§2).
    A retained soft-deleted row would keep reserving the code and satisfy
    neither half of the decision.
  • THE SCHEMA IS THE BACKSTOP — the gate's central finding. The delete used to
    trust a hand-written list; a reviewer proved an EMPTY list deleted an
    employee and took a real attendances row with it. The report now asks the
    database which foreign keys cascade into the table (sqlite PRAGMA, MySQL
    information_schema — both implemented, both tested, no driver skips) and
    REFUSES, naming table and column, for any cascading COLUMN no check covers.
    Per-COLUMN, not per-table: masterbatch_dosings reaches items through two
    columns, so a check on one proves nothing about the other. A check that
    skips soft-deleted rows also fails to cover its table, because a trashed
    child is still a physical row and cascades identically. The backstop
    immediately caught THREE incomplete declarations in the phase's own tests.
  • Status-enum masters: ActiveFlag expresses a boolean flag OR a status enum;
    activate and archive are two separate predicates, so a mould under repair
    is neither active nor retired and is never stranded. Declaring a boolean
    over an enum column now throws, naming the fix.
  • The hard-delete authority seam (§3), asked FIRST and fail-closed: a
    lifecycle that names nobody deletes nothing. No permission name and no role
    invented — the repo has no Super Admin construct and the wiring wave
    supplies the callback.
  • THE LIVE-FACING HALF, which needed no delete to matter: eleven is_active /
    status flags were set and filtered NOWHERE — a retired mould and a
    withdrawn scrap reason were selectable on the floor — and Item/Warehouse
    were unfiltered on eight stock/GRN paths. All closed in the FormRequests,
    each with a test proving the inactive row is refused, the active one still
    passes, and history still renders the inactive row. The pickers that fed
    those forms were narrowed too, so the operator never picks a row the server
    will refuse.
  • The audit trail: spatie/laravel-activitylog was installed, migrated and
    used nowhere, and no updated_by column existed. Now on for the ten Tier-1
    models with created_by/updated_by, and a test proving nothing is logged for
    a transaction model — the blast radius is bounded deliberately.
  • The shared frontend: one hook, row actions that READ `can` and never
    re-derive it, a delete modal rendering all THREE refusal lists, one status
    vocabulary, and an error helper that keeps field keys.

The convention correction, stated rather than slipped in:
  routes/api.php claimed "there is no PUT and no DELETE here or anywhere" —
  false in 47 places in that same file. It now states the truth, scoped:
  transactions and ledgers are append-only; configuration masters carry a
  lifecycle including a guarded hard delete. CLAUDE.md gained the matching
  half. MaterialLotCostVersionTest is untouched and a new test (T16) pins that
  every append-only surface still answers 405/404.

Tests:
  Backend 1,670 → 1,766 (1 skipped: the CEC golden) / 15,925. Frontend vitest
  383 → 456. The mechanism's own tests pass 41/41 on sqlite AND on a real
  MySQL 8. Knowledge sound.

Gate:
  Sonnet PASS_WITH_DEFERRED · Opus FAIL · Fable PASS_WITH_DEFERRED → fix loop →
  Sonnet re-gate PASS_WITH_DEFERRED. All three reviewers independently found
  the cascade P1. The re-gate's P2 (archive/activate did not enforce their own
  abilities) and the vocabulary P3 were fixed after it.

Owner-gated items:
  DEC-20260817-002 answered the contract's open points. Q43 still owns
  duplicate NAMES (block vs warn). Q51 still gates any warehouse consolidation.

Deferred items:
  • NO ENTITY IS WIRED and no route exposed — the next wave does that, carrying
    the Super-Admin-only grant (and the repo has no Super Admin construct yet).
  • Tier 1 entity rows are not yet green: this pass shipped the mechanism, the
    live-facing flag fixes and the audit trail.
  • payload()['alternative'] is hardcoded 'archive' even for a master that can
    neither flag nor soft-delete; DependencyCheck's soft-delete-column cache
    has no flush counterpart.
PR:                 #191 (base: feat/phase-7-regression-hardening → #190 → … → #179)
Deployment state:   not deployed; stack #179 → … → #190 → #191
Next phase:         7.5 (material flow) then 8 (end-to-end acceptance)
```

## PHASE 7.5 — Store → Production material flow

```
Phase:    7.5 — the lead's business-rule correction of 17-Aug (DEC-20260817-001)
Status:   PASS
Branch:   feat/phase-7.5-material-flow (stacked on Phase 7.6 PR #191 → … → #179)
Dates:    2026-08-17

Goal:
  Store Stock → Production Material Request → Store Issue → Scan/Handover →
  Issued-to-Production → Actual Consumption → Return unused. The Day Bin leaves
  the TARGET workflow without a row being deleted.

The rule the phase exists for:
  A STORE ISSUE IS NOT A CONSUMPTION. Three states stay distinct — Store Stock,
  Issued to Production (Production/WIP), Consumed — plus Issued → Returned.
  Production/WIP is a LOCATION, not a flag: signed transfer pairs carry stock in
  and out, so the ledger invariant and inventory:check-ledger hold at every
  state. The audit's decisive finding was that a movement-PURPOSE-only change
  would have been invisible to that invariant, which signs by movement TYPE.
  DEC-20260817-001 named the locations and confirmed there is no Day Bin; the
  existing WIP warehouse row is reused, never duplicated.

What changed:
  • Material requests + lines: requester, time, shift, work centre (nullable),
    item, quantity, uom snapshotted from the item, status draft → submitted →
    partially_issued → issued (or cancelled). The store works a QUEUE with every
    filter applied in SQL, over the FACTORY day localised through the factory
    timezone — without that every night-shift request files under the wrong day.
  • Store issues + lines + bag scans: partial fulfilment, remaining quantity,
    completion, cancellation and the return of unused material; the bag/lot
    resolution and QC-hold refusals reuse the existing scanner rather than a
    second one. Nothing assumes a daily cadence.
  • FC-01 honoured, not weakened, and Q50 was resolved by inspection rather than
    by a ruling: a common-input (resin) request REFUSES to name a machine, while
    consumable requests carry one; and the trace from a batch stops at the
    ISSUE — the ERP records which bags left the store, to whom and against which
    request, and never claims a batch consumed a particular bag.
  • The Day Bin: three dead doors closed, every reader either migrated to the
    issue ledger or recorded as historical-only, and the table, its rows and the
    machine-stamped history left exactly as they are (DEC-20260807-006). No
    Tally payload derives from it, verified.

What the gate found (two FAILs) and the fix loop closed:
  • P0 — THE TWO HALVES WERE NEVER CONNECTED. applyIssuedQuantities() is the one
    writer of issued_quantity and the only place a request advances, and the
    issue flow never called it: issuing left the request untouched, so the
    store's queue would have shown work already done, for ever. Now called
    inside the issue's transaction on both handover paths, with a scan crediting
    the request line the SCAN named — two bags of one resin share an issue line,
    so the second would otherwise have been credited to the first scan's request.
  • P1 — a reversal was bounded by issue-line arithmetic rather than by what is
    still standing, so cancelling after a batch had consumed drained another
    issue's material and erased the trace. Reversals are now bounded by what is
    genuinely unconsumed, read from stock movements rather than a balance that
    predates the phase, and they REFUSE rather than quietly writing a smaller
    number — the storekeeper typed a figure, and recording a different one
    records a return that did not happen. The refusal names the real figure.
  • P1 — material standing in Production/WIP was invisible to the Tally stock
    reconciliation, so every open issue would have read as store DRIFT and the
    accountant would have chased a discrepancy that is just material on the
    floor. It is now folded in and named, and never "fixed" by moving stock.
  • P1 — over-consumption. The brief said to refuse if in doubt; the fixer
    declined and was right: config/production.php records the 30-Jul incident by
    name — a real shift's completion refused at 6am over a balance nobody had
    entered — and the rule already decided is that a paperwork gap must never
    become lost production. So it is allowed and made VISIBLE. The genuinely
    silent hole was the SECOND over-draw: once WIP hit zero the next batch fell
    through to the store, whose balance dropped for material it never issued.
    WIP now stays the source while anything is standing, and a WIP shortfall
    carries a sentence saying nothing is missing from the store.
  • P2 — the WIP resolver depended on a sole-tally_guid fallback; two warehouses
    carry a guid on the live instance TODAY, so every store issue would have
    422'd on day one. WIP is identified by its own identity now, still
    fail-closed if it genuinely cannot be found.
  • P2 — the carton trace's owner-fixed sentence (DEC-20260810-001) had come to
    cover lots that never passed through the day bin. Owner wording is not
    rewritten by an agent: store-issue lots get their own separately-worded
    block, and the wording question is recorded.

Tests:
  Backend 1,766 → 1,874 (1 skipped: the CEC golden) / 16,688 · vitest 456 → 489
  · inventory:check-ledger VERDICT clean · knowledge sound. (Counts are after
  the rebase onto 7.6, so they include that phase.)

Gate:
  Sonnet FAIL · Opus FAIL → fix loop → Sonnet RE-GATE **PASS, zero findings**,
  with the reviewer's own scratch tests re-proving the reversal bounds, the
  reconciliation, the second over-draw and the resolver under two other
  Tally-linked warehouses, and confirming append-only behaviour by id.

Owner-gated items (recorded as Q54, none answered):
  whether every kg-family item is a common input or masterbatch may name a
  machine; refuse-or-cap on a late reversal; what the Start Batch availability
  panel should show now; the carton-trace wording; and whether a batch may
  consume more than was issued.

Deferred items:
  • The three legacy day-bin writers now have no production caller and survive
    through tests, deliberately kept and docblocked as historical.
  • returnUnused reads its budgets before the per-line lock, so two concurrent
    reversals on DIFFERENT issues could both pass their checks; the transfer's
    own non-negative decrement still protects the ledger (one wins, one 422s).
  • A backdated issue would be charged consumption it predates — the bound is
    inclusive and conservative (a refusal, never a wrong reversal).
PR:                 #192 (base: feat/phase-7.6-configuration-lifecycle → #191 → … → #179)
Deployment state:   not deployed; stack #179 → … → #191 → #192
Next phase:         8 — end-to-end acceptance
```

## PHASE 8 — End-to-end acceptance

```
Phase:    8 — the chain, not the modules (MASTER-PLAN rev 3, P8-01..08)
Status:   NOT READY  (one link NOT TESTED; two links BLOCKED by named owner gates)
Branch:   feat/phase-8-acceptance (stacked on Phase 7.5 PR #192 → … → #179)
Dates:    2026-08-17

The verdict, and why it is not a complaint about quality:
  Every one of the five chains passes. The full backend suite is 1,904 tests /
  1,903 passed / 1 skipped by design / 17,806 assertions; Pint clean; frontend
  typecheck, tests and build clean; inventory:check-ledger clean. The phase is
  NOT READY because ONE link is NOT TESTED, and the rule this programme set
  says that is enough, full stop.

  D-WIRING — the configuration lifecycle applied to the in-scope masters — is
  NOT TESTED, because Phase 7.6 deliberately shipped the mechanism and wired no
  entity. The independent QA confirmed it by inspection: no module declares
  ManagesConfigurationLifecycle, and the one lifecycle-shaped route in the app
  bypasses the shared mechanism. Chain D therefore proves the MECHANISM against
  an existing master through routes that predate 7.6 — and proves nothing about
  the 35 in-scope masters. That independently confirms both constraints the
  lead set: 7.6 wired no entity, and a material configuration gap remains.

What the chains proved:
  A · the operator workflow end to end — configuration answers every Shift
    Floor question; the standard is frozen at Start under its own
    calculation_version and that stamp is load-bearing; Completed Today equals
    the completed batches and excludes running, cancelled and yesterday's;
    Shift Summary equals Σ completed production with the QC reduction flowing
    through; the CEC composition is byte-identical to the Shift Summary; the
    Tally shift voucher contains exactly the approved entries, proved by
    inclusion AND exclusion; release gate → agent ack → snapshot.
  B · PO → GRN → lot → movement → balance → consumption, traced backward to
    every GRN line, FC-06 holding on every link in both halves.
  B2 · the three states never collapse — proved by MOVEMENT CENSUS rather than
    a total: the store's outflows are exactly three issue_to_production rows
    and ZERO consumption rows, so it cannot be charged twice for the same
    material. Mutation-checked twice. An open issue reads as NO drift.
  C · sales traced to Tally, the Layer-B honesty statement stated plainly, one
    export per kind, FC-06 on every file, the CEC slot BLOCKED not produced.
  D · the mechanism — a referenced master refused with integer counts and its
    cascade children surviving; an unused one really deleted and its code
    released; fail-closed verdicts; the schema backstop refusing an incomplete
    declaration.

Owner-gated, correctly recorded BLOCKED (neither invented, neither bypassed):
  • A6b — the CEC LAYOUT. The composition is proved equal to the Shift Summary;
    the format waits on the owner's sample.
  • B8 — the PO → Tally live write (Q35(d)). Flag off, staging proved, no
    egress reachable. The first live post is attended, never unattended.
  Even with D-WIRING closed, the best achievable state is PASS WITH
  OWNER-GATED ITEMS, not unconditional PRODUCT READY, while these remain.

Honest limits, stated rather than implied:
  • NO BROWSER PROOF was taken for any chain at any point in this phase. The
    Chrome extension disconnected early in the programme and never returned.
    Every PASS is at the transaction-model / API layer.
  • SQLITE ONLY. No MySQL on the build machine; the CI app-mysql leg (Phase 7)
    must execute the acceptance files before "green on both drivers" is said.
  • Chain B2's "Production Receipt" link has no separate document in the ERP —
    the receipt IS the WIP arrival with a named two-handed handover. Recorded
    as a PASS with the deviation stated, not as a gap.

What would change the verdict: see docs/engineering/RELEASE-READINESS.md.
PR:                 #193 (base: feat/phase-7.5-material-flow → #192 → … → #179)
Deployment state:   not deployed; stack #179 → … → #192 → #193
Next:               wire the Tier-1 masters through the contract, re-walk D
                    against them, run the chains on MySQL, take the browser walk
```
