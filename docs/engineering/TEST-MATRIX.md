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

## Phase 5 (feat/phase-5-product-sku-configuration, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,428 / 12,377** (Phase 4.5 close 1,352 / 11,879; +76: PackagingVariantUniquenessTest · PackQuantityResolverTest · PackingLinesPersistTest · PackagingIdentityOnlyTest · ProductVariantsTest · ConfigurationReviewTest · ProvisionalSkuTest · StockLedgerInvariantTest · StockMovementPurposeTest · CheckStockLedgerCommandTest · ProductionStandardImportTest +2 · PackagingTallyIdentityTest +1) |
| Frontend vitest | PASS | 143 → **190** (`production/productStandardsConfig.test.ts` 47: packaging state/vocabulary, identity labels, counts summary incl. mismatch wording, packagingForCompletion id-first) |
| Typecheck · build | PASS | clean · built |
| Agent | UNTOUCHED | — |
| Factory-knowledge | PASS | exit 0 (Q45 added; preamble → Q46) |
| Migrations | REVERSIBLE | 5 additive; up/down/up on sqlite by two reviewers; D1 index order keeps the FK covered on MySQL |
| Red-before / green-after | PROVEN | D1 (old index refused a second tray); the metric reader ignored the packaging row for tray/pouch; packing lines discarded; identity-only link rewrote 520 → 525; completion by mode; importer overwrite; append-only guard; provisional SKU create path |
| Sonnet independent QA | PASS_WITH_DEFERRED → fix loop → re-gate (see PHASE-LOG) | |
| Adversarial review | Opus NOT_READY (P1) · Fable NOT_READY (2 P1 · P2) → all fixed | |
| Browser proof | PASS | Product Standards workspace: "Needs review — 103 packing identities still waiting on a person" with the honest no-candidate cell; API: variants + review + ledger check clean |
| API proof | PASS | variants tree, review rows, inventory:check-ledger clean exit 0 |

### Coverage gaps closed this phase
Two same-mode packagings on one standard (D1) · one pack-quantity reader with per-rung precedence · packing lines persisted/replaced · identity-only linking that touches no count · completion packaging by id · importer safe beside person-entered rows · configuration status vocabulary + review candidates (active, Tally-pulled, non-fixture) · provisional SKU flag · stock ledger invariant through the real paths + append-only + purpose backfill rule.

### Still open (from the baseline list)
`OverReceiptException` · CRM · Finance · `ShiftSummaryService::report()` (Phase 5.7) — **Closed here:** second same-mode packaging (D1) · `stock_balances == Σ stock_movements`. **Still:** MySQL CI leg (Phase 7).

## Phase 5.5 (feat/phase-5.5-shift-floor, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,502 / 13,167** (Phase 5 close 1,428 / 12,377; +74: EstimationUnifiedTest 24 · CompletionEventAndDefaultsTest 13 · EntriesIndexFiltersTest 20 · BatchPreviewMouldTest 4 · BatchPreviewRunStatusTest 7 · SegmentHandoverTest +4 · v3 arms in ExpectedOutputDivergence / ProductionCalculationEngine / ExpectedOutputEngine) |
| Frontend vitest | PASS | 190 → **269** (`startBatchChoices.test.ts` 32 incl. the previous inline conditions verbatim · `expectedOutput.test.ts` 28 · `completedToday.test.ts` 19) |
| Typecheck · build | PASS | clean · built |
| Agent | UNTOUCHED | — |
| Factory-knowledge | PASS | exit 0 (Q46 added; preamble → Q47) |
| Migrations | none | — |
| Red-before / green-after | PROVEN | preview-vs-entry divergence under v3; legacy golden pins (Opus: 871,563-case equivalence, zero divergence); handover child unstamped (13580 vs 13584.91); snapshot 'live' → 'snapshot'; N+1 15 → 22 queries; client mirror float floor (19,995 vs 20,000); modal gaps grain; efficiency ceiling |
| Sonnet independent QA | FAIL → fix loop → re-gate (see PHASE-LOG) | |
| Adversarial review | Opus NOT_READY (P0 + 2 P1 + P2s) · Fable PASS_WITH_DEFERRED (2 P2) → all fixed | |
| Browser proof | NOT DONE (extension disconnected mid-session) | pinned by vitest/backend tests; to be re-proven in Phase 8's chain walk |
| API proof | PASS | entries index filters (422s, per_page bound, unknown ignored); preview carries calculation_version production_v3_unified + downtime_netted false, configuration.mould, per-variant statuses |

### Coverage gaps closed this phase
One estimation engine for preview and entry, versioned, legacy pinned · every entry-creation path stamps · completion event after the outer commit · configuration gaps frozen at Start · packing defaults on the entry · entries index filters (factory day, index-friendly) + TallyLink at constant cost · Completed Today row mapper (nothing recomputed; settings ceiling) · Start Batch choices helper == the previous inline conditions · client mirror exact arithmetic (integer micro-units; downtime 6-dp truncation).

### Still open (from the baseline list)
`OverReceiptException` · CRM · Finance · `ShiftSummaryService::report()` (**Phase 5.7, next**) — **Still:** MySQL CI leg (Phase 7). **New (recorded):** the two pre-existing per-row cost reads on the entries list (materialCost stock_movements; bag-cost allocations) — a batching follow-up.

## Phase 5.7 (feat/phase-5.7-shift-summary-cec, gate closed 2026-08-17)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,526 / 13,602** (1 skipped by design: CecGoldenTest "CEC sample not on file — format authority is the owner's"); Phase 5.5 close 1,502 / 13,167; +24: ShiftSummaryReportTest 12 · CecReportTest 11 · CecGoldenTest 1 |
| Frontend vitest | PASS | 269 → **304** (`shiftSummaryReconcile.test.ts` 18 · `cecPreview.test.ts` 17) |
| Typecheck · build | PASS | clean · built |
| Agent | UNTOUCHED | — |
| Factory-knowledge | PASS | exit 0 (Q47 added; preamble → Q48) |
| Migrations | none | — |
| Red-before / green-after | PROVEN | honesty keys absent (6 red → green); CEC route absent (11 red → green); stray fixture file → harness fails loudly (proved with a throwaway csv, removed) |
| Sonnet independent QA | PASS_WITH_DEFERRED (P3s) | own fixture: report == index == CEC at 125.0000 kg with amended/handover/awaiting-correction/cancelled/in-progress on one shift; 42/42 field-for-field; skip message byte-exact |
| Adversarial review | Opus PASS_WITH_DEFERRED (P3s) · Fable PASS_WITH_DEFERRED (P3s) → P3s fixed on the branch except the inherited per-entry cost reads (recorded, P7-03) | |
| Browser proof | NOT DONE (extension disconnected) | pinned by vitest/backend tests; Phase 8 chain walk |
| API proof | PASS | report honesty keys; GET production/cec format string + figures_from + honest empty day; 422 matrix; exports cec kind still `blocked` with the verbatim reason |

### Coverage gaps closed this phase
`ShiftSummaryService::report()` — historical dates, A/B/C, All, Σ completed batches == summary, day == Σ shifts, logs per shift, honesty keys · CEC data endpoint == Shift Summary == entries index (field-for-field) · golden harness (skips honestly; fails loudly on a stray/unguided sample) · reconcile line (BigInt 4 dp; honest when the walk is capped) · CEC preview (server figures only; no download).

### Still open (from the baseline list)
`OverReceiptException` (**Phase 6 WS-B, in progress**) · CRM · Finance — **Still:** MySQL CI leg (Phase 7). **New (recorded):** P5.7-03 reporting honesty sweep → P7-05; ShiftSummaryExport alias columns; per-entry cost reads (2 queries/batch) on the entries resource → P7-03.

## Phase 6 (feat/phase-6-purchase-chain, gate closed 2026-08-17)
## Phase 7 (feat/phase-7-regression-hardening, integrated 2026-08-17; gate pending)

| Suite | Result | Evidence |
|---|---|---|
| Pint (whole tree) | PASS | clean |
| Backend PHPUnit | PASS | **1,595 / 14,633** (1 skipped by design: CecGoldenTest); Phase 5.7 close 1,526 / 13,602; +69: PurchaseOrderLifecycleTest 17 · PurchaseOrderTraceTest 9 · PurchaseOrderTallyStagingTest 27 · PurchaseChainContractTest 6 · OverReceiptContractTest 5 · GoodsReceiptIdempotencyContractTest 4 · additive TallySync catalogue cases |
| Frontend vitest | PASS | 304 → **368** (`procurement/purchaseOrders.test.ts` 63 · tally-sync catalogue fixtures refreshed) |
| Typecheck · build | PASS | clean · built |
| Agent | PASS | 119 → **122** (`purchaseOrder.test.js` 25 incl. the DEC-20260812-002 half and a synthetic byte-exact golden); version 0.3.9 **NOT published** |
| Factory-knowledge | PASS | exit 0 (Q48 added; preamble → Q49) |
| Migrations | 3, additive + reversible | purchase_order_revisions · close/cancel + tally_staging + vendors.tally_ledger_name · goods_receipt_note_lines.stock_movement_id (no backfill); rollback→migrate proven |
| Red-before / green-after | PROVEN | 24 of 26 lifecycle/trace tests red on 404 before the routes; the withdrawal pair red ('pending' where 'dismissed' expected, `after` key undefined); the two-GRN trace defect reproduced (size 2 ≠ 1) before the fix |
| Sonnet independent QA | **PASS, zero findings** | own state-machine matrix (8 status × mirror combinations, `can` vs actual HTTP), real double-fire of PurchaseOrderSent, trace query count flat 15→16 across a 6× wider chain, JD epoch recomputed independently |
| Adversarial review | Opus **FAIL** (P1 FC-06 + 4 P2/P3) · Fable **FAIL** (P1 FC-06 + 5 P2/P3) → all fixed → re-gate | the P1 was a real second supplier name still in a committed doc |
| Browser proof | NOT DONE (extension disconnected) | pinned by 50 vitest helper cases + the backend suite; Phase 8 chain walk |
| API proof | PASS | flag-off through the REAL send(): zero entries, `tally_staging.state = 'disabled'` |

### Coverage gaps closed this phase
`OverReceiptException` (the long-standing baseline gap) · PO send() transition · the whole purchase chain as one contract with the ledger invariant after every step · receipt_key idempotency across the chain incl. exactly one Receipt Note · PO lifecycle (amend/close/cancel) with mirror refusal · PO show + trace with FC-06 gating · the first ERP-raised Tally voucher staged, refusing rather than guessing, proved to touch neither accounts nor stock.

### Still open (from the baseline list)
CRM · Finance — **Still:** MySQL CI leg (**Phase 7, next**). **New (recorded):** the ORDERDUEDATE JD/P read-back at the first attended live post; the machine-stamped `day-bin/load` write door (cleanup against DEC-20260807-006); `match` not yet typed in the frontend procurement types.
| Backend PHPUnit — **sqlite leg** | PASS | **1,661 / 15,382** (1 skipped by design: CecGoldenTest); Phase 6 close 1,595 / 14,633 |
| Backend PHPUnit — **MySQL 8 leg** | **PASS** | **1,661 / 15,382**, identical counts, run against a real `mysql:8.0` container as well as the new `app-mysql` CI job — the driver the live factory runs on had never met this suite before |
| Frontend vitest | PASS | 368 → **383** |
| Typecheck · build | PASS | clean · built |
| Agent | PASS | 122 → **135** (snapshot journal + retry) |
| Factory-knowledge | PASS | exit 0 (Q49 added; preamble → Q50) |
| Migrations | 1, additive | a widened quality/scrap note column (from the MySQL work) |
| Red-before / green-after | PROVEN | four MySQL failures reproduced and fixed: JSON object-key order ×2, sqlite identifier quoting in a SQL-predicate assertion, and the published decimal shape; the report cap's cut proved directly (7 rows → 3 with `truncated: true`, and exactly-at-cap NOT called truncated) |
| Sonnet independent QA | **NOT YET RUN** | the gate is the next act — recorded honestly rather than implied |
| Adversarial review | **NOT YET RUN** | as above |
| Browser proof | NOT DONE (extension disconnected) | Phase 8 chain walk |
| API proof | pending the gate | |

### Coverage gaps closed this phase
The MySQL leg itself (the longest-standing gap) · two published figures that differed between dev and live now single-shaped · the entries index cost per page instead of per row · work-queue filters in SQL before the page is cut · report `row_cap`/`truncated` · a regression smoke over the WHOLE read surface (401 unauthenticated, never 5xx to an admin, every parameterised GET classified — SKIPPED is empty) · auth/roles/module-index coverage where there was none · the agent no longer drops a snapshot captured while the cloud was down.

### Still open (from the baseline list)
CRM · Finance · the Phase 7 gate itself. **New (recorded):** Q49 (the paper-page screen); the `app-mysql` check needs adding to branch protection by the repo owner.
