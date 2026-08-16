# Test Matrix — per phase

Values: `PASS` · `FAIL` · `NOT TESTED` · `BLOCKED` · `N/A`. Never "looks good".
Rows are appended per phase; a re-run adds a dated column, never overwrites.

## Baseline (main @ 9a9cbe3, 2026-08-16)

| Suite | Where | Count | Status |
|---|---|---:|---|
| Backend feature | `backend/tests/Feature/**` | 133 files | CI green on main |
| Backend unit | `backend/tests/Unit/` | 1 (ExampleTest) | — |
| Frontend vitest | `frontend/src/**/*.test.ts` | 4 files | CI green |
| Agent (node:test) | `tally-sync-agent/tests/` | 4 files / 62 tests | CI green |
| Factory-knowledge | `scripts/factory-knowledge/check.sh` | — | `sound` |
| Pint | `backend/` | — | CI green |
| Typecheck + build | `frontend/` | — | CI green |

## Known coverage gaps at baseline (from the audit, §4.6 / §4.18)

| Area | Status |
|---|---|
| `OverReceiptException` (partial-receipt guard) | NOT TESTED |
| `OverDeliveryException` | NOT TESTED |
| `DeliveryService` stock decrement | NOT TESTED |
| Carton-scan dispatch guards incl. DEC-20260807-013 | NOT TESTED |
| `InvoiceService`, invoice→Tally | NOT TESTED |
| `PurchaseOrderService::send()` transition guard | NOT TESTED |
| CRM (any) | NOT TESTED |
| Finance (any) | NOT TESTED |
| `ShiftSummaryService::report()` | NOT TESTED |
| Frontend `tally-sync` feature | NOT TESTED |
| Procurement-only user vs purchase-rate fields | NOT TESTED |
| Local-fixture sweep (`TallySyncService.php:620-623`), either hole | NOT TESTED |
| Second same-mode packaging with different counts | NOT TESTED |
| `stock_balances == Σ stock_movements` | NOT TESTED |

## Phase 0

| Suite | Result |
|---|---|
| Factory-knowledge check | PASS |
| Everything else | N/A (read-only phase) |


## Phase 1 (fix/phase-1-live-safety, gate closed 2026-08-16)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | `{"tool":"pint","result":"passed"}` |
| Backend PHPUnit | PASS | **1,034 passed / 5,740 assertions** (baseline 993 / 5,520; reconciled: −4 deleted with the dead `/items` route, +45 new). Sonnet re-ran twice, identical |
| Frontend typecheck | PASS | `tsc --noEmit` clean |
| Frontend vitest | PASS | 4 files / 25 tests |
| Frontend build | PASS | `vite build` into `backend/public/build` |
| Agent (node:test) | PASS | 69/69 — agent untouched by this phase (`git diff --stat -- tally-sync-agent` empty) |
| Factory-knowledge | PASS | `sound` |
| Red-before / green-after | PROVEN | Fable reviewer, scratch copy: every new test file fails against HEAD code |
| Sonnet independent QA | PASS (re-gate) | first pass NOT_READY (2 P2 · 2 P3) → all fixed → re-gate PASS, 0 findings |
| Adversarial review (Opus, rules/accounting) | closed | 1 P1 · 3 P2 · P3s — all fixed or recorded as deferred with reason |
| Adversarial review (Fable, races/tests) | closed | 1 P2 · P3s — all fixed |
| Browser proof | PARTIAL | PO drawer + API bytes for procurement-only and Accounts users: PASS. Name guard at API layer: PASS. ItemsPage disabled-name affordance: **NOT TESTED in browser** (modal did not open in the harness after 3 attempts; covered by typecheck) |

### Coverage gaps closed this phase
Procurement-only vs rate fields · local-fixture sweep (both holes, both directions, both rebuild paths) · Tally-side rename escape hatch (MasterSyncTest) · fixture as packaging identity · scrap resolver misconfiguration distinction · packaged config default pinned on source text.

### Still open (from the baseline list)
`OverReceiptException` · `OverDeliveryException` · `DeliveryService` decrement · carton-scan guards · `InvoiceService` / invoice→Tally · CRM · Finance · `ShiftSummaryService::report()` · frontend `tally-sync` · second same-mode packaging · `stock_balances == Σ stock_movements` — Phases 5 and 7 per MASTER-PLAN.

## Phase 2 (feat/phase-2-sync-control-center-foundation, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,097 / 6,503** (Phase 1 close 1,034 / 5,740; +63: SyncEventHistoryTest 17 · SyncEventBackfillTest 4 · TransactionClassifierTest 11 · SyncQueryFiltersTest 16 · SyncSummaryTest 6+ · SyncEntryShowTest 4 · SyncPayloadRateVisibilityTest 5) |
| Frontend vitest | PASS | 25 → **45** (`filters.test.ts` 20) |
| Typecheck · build | PASS | clean · built |
| Agent (node:test) | PASS | 69/69 — untouched |
| Factory-knowledge | PASS | sound |
| MySQL 8 (reviewer, docker) | PASS for Phase 2 suites | 6 pre-existing tests fail on JSON key order (`assertSame` on payload arrays) — **Phase 7: MySQL CI leg + `assertEqualsCanonicalizing`** |
| Red-before / green-after | PROVEN | per workstream and per fix (F1 payload gate 3/5 red; F7 transaction 2 red; F2 flag; F3 clauses; C factory-tz; D wire suffix) |
| Sonnet independent QA | PASS → fix loop → **PASS** | see PHASE-LOG |
| Adversarial review | Opus FAIL → all fixed; Fable PASS + MySQL run | 2 P1 · 6 P2 · P3s closed |
| Browser proof | PARTIAL | page renders (filter row, counts line, Category column, three-clause honesty line); Ant `Select`/`Input.Search` interaction not driven by the harness → filters proven at API layer with exact ids |
| API proof | PASS | summary/list/show/filters + FC-06 four-caller matrix on the local dev API |

### Coverage gaps closed this phase
Sync-payload rate visibility (four caller kinds incl. the real agent) · event history for every mutation + inbound contact · backfill idempotency + rollback isolation · classification of every ERP-built type + census mapping · every list filter incl. `held` via the real gate and JSON-null on MySQL · factory-tz business date · show-vs-list history · pending()/retry() transactional atomicity · agent-vs-user actor typing · frontend query-param serialisation.

### Still open (from the baseline list)
`OverReceiptException` · `OverDeliveryException` · `DeliveryService` decrement · carton-scan guards · `InvoiceService` / invoice→Tally · CRM · Finance · `ShiftSummaryService::report()` · second same-mode packaging · `stock_balances == Σ stock_movements` — Phases 5 and 7. **New:** MySQL CI leg (Phase 7).
