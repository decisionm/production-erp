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
