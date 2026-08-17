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

## Phase 3 (feat/phase-3-sync-every-transaction-type, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,193 / 7,914** (Phase 2 close 1,097 / 6,503; +96: LineMappingResolverTest · SyncEntryMappingsTest · EntryPresenterTest · VoucherPreviewAmbiguityTest · SupplierIdentityVisibilityTest 8 · PerType/* 6 classes on the real endpoints · Sales/DispatchRefusesQualityRejectedCartonTest · AuditLocalFixturesCommandTest; SyncQueryFiltersTest corrected — it had pinned the q= vendor oracle) |
| Frontend vitest | PASS | 45 → **69** (`drawer.test.ts` 24 incl. the four `showsFixedAfterFailures` cases) |
| Typecheck · build | PASS | clean · built |
| Agent (node:test) | PASS | 69/69 — untouched (`git diff main...HEAD -- tally-sync-agent/` empty) |
| Factory-knowledge | PASS | exit 0 |
| Red-before / green-after | PROVEN | per workstream and per fix (ambiguous fail-closed; supplier-identity predicate on headline/mappings/payload/root party/q=; rejection-text leak on error_message/resolution_log root+payload copy/timeline/history; banner status gate) |
| Sonnet independent QA | PASS → fixes → FAIL → FAIL → **PASS** (re-gate #3, no findings) | see PHASE-LOG |
| Adversarial review | Opus FAIL (2 P1) · Fable NOT READY → all fixed | 4 P1 · 1 P2 · 1 P3 (observation, deferred) |
| Browser proof | PARTIAL | drawer chain for GRN-3 as Accounts (rate/amount columns) and as tally-sync-only (Item · Quantity only, no blanks); Ant Select/Input still not drivable via refs → filters at the API layer |
| API proof | PASS | show keys flags/history/mapping_summary/mappings/summary/timeline; honest mapping_summary on the dev seed (no GUIDs); FC-06 supplier-identity matrix by reader kind |

### Coverage gaps closed this phase
Mapping state per line without a conflict table (identity/name_only/unmapped/fixture/ambiguous) · ambiguous names fail closed in the preview · supplier identity (not just rate) withheld from readers without standing, at every depth including Tally's own rejection text and the list search · per-type lifecycle through the REAL endpoints incl. the real agent token · DEC-20260807-013 refusal end to end + OverDeliveryException both paths (§4.6) · read-only fixture audit command · frontend banner truth table.

### Still open (from the baseline list)
`OverReceiptException` · `InvoiceService` / invoice→Tally · CRM · Finance · `ShiftSummaryService::report()` · second same-mode packaging · `stock_balances == Σ stock_movements` — Phases 5 and 7. **Closed here:** `OverDeliveryException` · `DeliveryService` decrement · carton-scan guards. **New:** Delivery replay key (Phase 3.5) · MySQL CI leg (Phase 7).

## Phase 3.5 (feat/phase-3.5-sales-visibility, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,243 / 9,060** (Phase 3 close 1,193 / 7,914; +50: SalesSearchFilterTest 14 · SalesDocumentShowTest 8 · TallySyncLinkServiceTest 7 · SalesOrderCancelTest 8 · SalesTraceChainTest 2 · TallyMirrorHonestyTest 4 · GenericEnqueueReplayTest 7) |
| Frontend vitest | PASS | 69 → **105** (`sales/filters.test.ts` 21 · `sales/drawer.test.ts` 15) |
| Typecheck · build | PASS | clean · built |
| Agent (node:test) | PASS | 69/69 — untouched (`git diff … -- tally-sync-agent/` empty) |
| Factory-knowledge | PASS | exit 0 (Q44 added; preamble → Q45) |
| Red-before / green-after | PROVEN | GenericEnqueueReplayTest 7/7 red at baseline (two rows minted); WS-A endpoint tests 23 red → green; fix loop: N+1 DB::listen test, documentId "-1", paginator links red on the pre-fix tree |
| Sonnet independent QA | PASS (2 P3) → fix loop → **re-gate PASS_WITH_DEFERRED** (3 P3 doc/test gaps, fixed) | see PHASE-LOG |
| Adversarial review | Opus PASS_WITH_DEFERRED · Fable PASS_WITH_DEFERRED (no P1) | 2 P2 · 8 P3 — P2s fixed, P3s fixed or recorded |
| Browser proof | PASS (with harness notes) | mirror panel, filter bar, ?open=SO-1 drawer chain, ?entry=4 deep link, invoices/deliveries columns; a 403 no longer reads as "no matches" |
| API proof | PASS | tally-mirror strings; list filters, q spellings, 422s; show + trace + TallyLink keys; 404 |

### Coverage gaps closed this phase
Server-side sales filters incl. factory-day range and LIKE escaping · document-number grammar (accept AND reject set) · show/trace shapes and the seven-key TallyLink (no rate/vendor/payload/error) · carton-scan → delivery → SO → Tally chain through a REAL agent ack · SO cancel lifecycle + no side effects + permission · tally-mirror honesty (exact strings, pure read) · generic enqueue replay for all four types (+ dismissed re-issue) · orders-list query count · frontend URL round-trip, drawer helpers, honest empty text.

### Still open (from the baseline list)
`OverReceiptException` · CRM · Finance · `ShiftSummaryService::report()` · second same-mode packaging · `stock_balances == Σ stock_movements` — Phases 5 and 7. **Closed here:** `InvoiceService` / invoice→Tally (issue → Sales entry; replay). **Still:** MySQL CI leg (Phase 7).

## Phase 4 (feat/phase-4-agent-xml-snapshot, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,276 / 9,429** (Phase 3.5 close 1,243 / 9,064; +33: SnapshotStoreTest 17 · SnapshotVisibilityTest 12 · PendingPayloadHashTest 4) |
| Frontend vitest | PASS | 105 → **127** (`tally-sync/drawer.test.ts` +22: formatXml, snapshotHeadline, snapshotXmlDecision, snapshotAnswer, event label) |
| Agent (node:test) | PASS | 69 → **94** (`tests/snapshot.test.js` 25; releaseContract + versionAdvance still governing; check-tests-present lists it) |
| Typecheck · build | PASS | frontend + agent clean · built |
| Voucher builders / .github | UNTOUCHED | `git diff … -- tally-sync-agent/src/tally/ .github/` empty |
| Factory-knowledge | PASS | exit 0 |
| Red-before / green-after | PROVEN | session guard (200 → 403), sha recompute / verdicts / prune (mutation-tested by Sonnet), attempt default, answered-vs-unanswered idempotency, byte-exact XML |
| Sonnet independent QA | PASS → fix loop → re-gate (see PHASE-LOG) | |
| Adversarial review | Opus NOT_READY (1 P1 · 1 P2 · 5 P3) · Fable PASS (9 P3) → all fixed except 3 recorded deferrals | |
| Browser proof | PASS | drawer section as Administrator (XML pretty-printed, Copy, rejected tag) and as tally-sync-only (withheld notes, counts) |
| API proof | PASS | payload_hash on /pending (agent only); store 201 / idempotent 200 / sha mismatch 422 / poll-only 403; four-caller matrix on show for Receipt Note and Delivery Note; timeline sentence |

### Coverage gaps closed this phase
The agent's report surface is the agent's alone (session refused on pending/ack/fail/snapshot) · snapshot store contract (sha recompute, caps at request AND column, idempotency incl. answered/unanswered, retention prune, event details without text, byte-exact body) · FC-06 on the XML per caller kind × voucher type, verdicts table over every category (fail-closed for Unknown) · payload_hash on the agent branch only · agent snapshot body builder (caps, omission, never-throws, attempt ordinal) · formatXml adversarial inputs.

### Still open (from the baseline list)
`OverReceiptException` · CRM · Finance · `ShiftSummaryService::report()` · second same-mode packaging · `stock_balances == Σ stock_movements` — Phases 5 and 7. **Still:** MySQL CI leg (Phase 7). **New:** agent snapshot for a post made while the cloud was down (journal-persisted, later).

## Phase 4.5 (feat/phase-4.5-export-center, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,352 / 11,879** (Phase 4 close 1,276 / 9,429; +76: Core/ExportCenterTest 10 · Unit/Core/CsvStreamerTest 27 · TallySyncEntriesExportTest 5 · TallySyncHistoryExportTest 5 · Production/ProductionExportsTest 8 · Procurement/ProcurementListFiltersTest 9 + ProcurementExportsTest 6 · Sales/SalesExportsTest 6) — phpunit.xml memory_limit 512M (the suite sat at PHP's 128M default) |
| Frontend vitest | PASS | 127 → **143** (`exports/filters.test.ts` 16: schema→control, serialisation, Content-Disposition plain/quoted/RFC 5987, refusal sentences, grouping) |
| Typecheck · build | PASS | clean · built |
| Agent | UNTOUCHED | — |
| Factory-knowledge | PASS | exit 0 (validator regex word-anchored; "RFC 4180" wording) |
| Red-before / green-after | PROVEN | per kind (rows == endpoint, FC-06 per reader, count == total, catalogue per reader); reviewers' scratch probes: 5,000-row chunk-boundary walk, deliveries decorate parity, history lazy(200) boundary, mid-stream abort, cap boundary |
| Sonnet independent QA | PASS_WITH_DEFERRED (no P1) | see PHASE-LOG |
| Adversarial review | Opus PASS_WITH_DEFERRED (2 P2 fixed) · Fable PASS (P3s) | |
| Browser proof | PASS | /exports cards from the catalogue, generated forms, CEC card disabled with the reason, a real download (blob saved under the server's filename), Recent downloads |
| API proof | PASS | catalogue per reader; one file per family; cec 409 with the reason; unknown 404; runs |

### Coverage gaps closed this phase
Server-side exports equal to their lists/reports for the same filters and reader · FC-06 on files (rate columns absent / withheld cells worded) · CSV byte contract (BOM, CRLF, RFC 4180, formula guard, UTF-8, arrays) · stated cap + audit rows · blocked kinds (CEC exact reason; traceability when off) · PO/GRN list filters (new) · frontend schema-driven form and filename parsing.

### Still open (from the baseline list)
`OverReceiptException` · CRM · Finance · `ShiftSummaryService::report()` (Phase 5.7) · second same-mode packaging (Phase 5 D1) · `stock_balances == Σ stock_movements` (Phase 5) — **Still:** MySQL CI leg (Phase 7).
