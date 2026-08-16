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

