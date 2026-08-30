# Procurement UX Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the 19 confirmed UX problems from the 28-Aug live procurement audit (drawer race, table ergonomics, sidebar, quantities/UOM, status vocabulary, workflow walk-forward PR→PO and GRN→QC, search/filters, picker identity, inspection validation, jargon), leaving GST/supplier-bill work (item 20) as an owner question.

**Architecture:** Frontend-heavy. Pure vocabulary/presentation helpers extracted into tested modules (the `tally-sync/drawer.ts` precedent); pages stay thin renderers. Two small additive backend changes only: (a) `ListPurchaseRequisitionsRequest` + filterable `PurchaseRequisitionService::paginate` (the controller currently ignores even `per_page`), (b) expose what is already loaded/known — GRN vendor (already eager-loaded), PR→PO ids (FK exists since the first migration). No stock, QC, Tally or live-data behaviour changes.

**Tech Stack:** React 18 + TS + antd 5 + TanStack Query + RHF/zod (frontend), Laravel 11 (backend). Vitest runs in **node, no DOM** — component assertions call components as functions and walk element trees (EntrySource.test.tsx precedent).

**Spec:** The 20-item audit summary in the task request (kept in the PR description). Baseline: frontend 929 tests green; backend has **8 pre-existing failures** (GoodsReceiptIdempotencyContractTest, OverReceiptContractTest, PurchaseChainContractTest — Receipt-Note/TallySyncEntry counts) on clean tree 96fdaa0.

## Global Constraints

- Never touch live data; browser retest against local dev servers/local DB only.
- Server stays the authority on abilities (`can` blocks) — the UI may hide an act, never enable one.
- No new factory rules invented: no GST logic, no warehouse defaulting, no Tally name approximation (item 19 is already enforced server-side — `TallyGodownResolver::resolve`, not `resolveName`, for Receipt Notes; verify, don't change).
- No merges, no deploys, no GitHub guardrails. Work stays on `claude/procurement-workflow`.
- Status vocabulary: Sentence case words ("Partially received"), never raw enums. Document identity: `PO-{id}` (exists), `PR-{id}` (new), `document_number` = `GRN-{id}` (exists server-side, unused by GRN page).
- All list pages: server-side search + real pager (28-Aug standing rule), `sticky` headers, Actions `fixed: 'right'`.

---

### Task 1: Pure vocabulary + presentation helpers (frontend, tested)

**Files:** create `frontend/src/features/procurement/documentWords.ts` (+ `.test.ts`), extend `frontend/src/lib/itemLabel.ts` (+ test), `frontend/src/features/procurement/purchaseOrders.ts` (+ test updates), create `frontend/src/features/quality/words.ts` (+ test), extend `frontend/src/features/tally-sync/drawer.ts` (+ test), extend `frontend/src/components/configuration/configurationWords.ts` (+ test).

- `prNumber(idOrRec)` → `"PR-{id}"`; `requisitionStatusTag(status)` → `{color, label}` with `Draft/Approved/Rejected`.
- `grnTitle(rec | null)`/`grnNumber(rec)` → `document_number ?? "GRN-{id}"`; null-safe (never "#undefined").
- `vendorLedgerWords(vendor)` → `not_mapped` / `same_as_name` (case+whitespace-insensitive, itemLabel's `bare()` rule) / the differing name.
- `itemPickerLabel(item)` = `itemLabel(item)` + ` · {uom}` when uom present — picker identity (audit 15) without touching the 40-caller `itemLabel`.
- `statusTag()` labels get sentence case ("Partially received"); `ACTION_LABELS.cancel` → `"Cancel order"`.
- quality `resultTag(result)` → Pass/Fail/Partial; `inspectionPreview({inspected, accepted, rejected})` → `'incomplete' | 'unbalanced' | {result}` — all-zero/empty is `incomplete`, never `pass` (audit 16).
- tally-sync `saidTone(entry)` → `'danger'` normally, `'history'` when `status === 'dismissed'` (+ prefix words `"Before it was dismissed: "`) (audit 11).
- `splitConfigurationActions(actions)` → `{primary /* edit */, overflow /* rest; disabled activate/archive dropped, disabled delete kept with reason */}` (audit 6).
- `grnQcSummary(lines, inspectionByLineId)` → `{state: 'none'|'partial'|'done', words, tone}` for the GRN QC column (audit 12/13).

### Task 2: `#undefined` drawer race (audit 1)

**Files:** `PurchaseRequisitionsPage.tsx`, `GoodsReceiptsPage.tsx` (three drawers total), pure title helpers from Task 1.

Port the PO-drawer `lastId` pattern: keep the last non-null record in state so the closing frames keep their title/body; titles built via null-safe helpers. Regression: `documentWords.test.ts` asserts titles for `null` never contain "undefined".

### Task 3: Backend additive changes (+ feature tests, pint)

**Files:** create `backend/app/Modules/Procurement/Http/Requests/ListPurchaseRequisitionsRequest.php`; modify `PurchaseRequisitionController.php`, `PurchaseRequisitionService.php`, `PurchaseRequisition.php` (add `purchaseOrders()` HasMany), `PurchaseRequisitionResource.php` (add `purchase_order_ids` whenLoaded), `GoodsReceiptNoteResource.php` (add `vendor` from already-loaded `purchaseOrder.vendor`); quality: `IncomingInspectionController::index` accepts validated `per_page` (1..1000)/`page`. Tests: `tests/Feature/Procurement/PurchaseRequisitionListTest.php` (or extend existing), GRN resource vendor assertion.

- PR filters: `status` (enum or list), `q` (`"PR-12"/"12"` via `ProcurementDocumentQuery::documentId` with `PR` prefix, else item name/sku or notes contains), `per_page` finally honoured.
- Nothing removed, nothing required — empty query = old behaviour.

### Task 4: Goods Receipts page (audit 1, 2, 5, 8, 10, 12, 13, 17)

**Files:** `GoodsReceiptsPage.tsx`, `procurement/api.ts`, `procurement/types.ts`, small `GoodsReceiptFilterBar` (inline or component).

- Server search/filters: `q`, vendor, date range; deep-link `?po=` → server `purchase_order_id` (kills the 1000-row fetch); `?grn=` keeps small client narrow but paged query.
- Columns: **Receipt** (`GRN-n` strong + RN reference secondary, `fixed: 'left'`), PO link, **Vendor** (new resource field), Items summary (itemLabel), Warehouse, Received, **QC** (`grnQcSummary` from fetched inspections list), Actions (`fixed: 'right'`); `sticky`.
- Drawer: `grnTitle`, RN reference + tracking rows, quantities via `formatQuantity(q, uomOf(item))`, per-line QC state + "Record inspection" link → `/quality/incoming-inspections?line={grn_line_id}`.
- Create modal: okText "Post receipt", required marks on PO/Warehouse/Received, `loading` on pickers while queries pending; toasts say `GRN-n`.

### Task 5: Purchase Requisitions page (audit 2, 5, 8, 10, 12, 14, 17)

**Files:** `PurchaseRequisitionsPage.tsx`, `procurement/api.ts`, `procurement/types.ts`, `CreatePurchaseOrderModal.tsx` (gains optional `initial` + sends `purchase_requisition_id`).

- Search box (`q`) + status filter, server-side; pager now honest (backend Task 3).
- Columns: Number `PR-n` (fixed left), Status words, Items, Requested By, Needed By, **Ordered** (`purchase_order_ids` → `PO-n` links / "Not ordered yet" for approved), Actions fixed right; sticky.
- **Create PO from an approved PR** (data model already supports it): mounts the PO create modal prefilled `{purchase_requisition_id, lines: [{item_id, quantity}]}`; vendor left for the buyer; success toast links the new PO.
- Drawer: pattern-A close, status words, `formatQuantity` + UOM.
- Modal: okText "Create requisition", required marks, item picker via `purchasableItemOptions` + `itemPickerLabel` (drops retired items — matches the PO picker and the server).

### Task 6: Incoming Inspections page (audit 5, 8, 10, 12, 15, 16, 17)

**Files:** `quality/pages/IncomingInspectionsPage.tsx`, `quality/api.ts` (list params), `quality/words.ts`.

- Defaults: quantities **empty** (not 0), date defaults today; zod messages per field; per-field `validateStatus/help` on the three quantities; preview uses `inspectionPreview` (no green "pass" on zeros); okText "Record inspection"; required marks.
- Picker: label `GRN-n · {itemLabel} · received {qty} {uom}`; **already-inspected lines filtered out** (from the inspections list, per_page 1000); `loading` while the register loads; `?line=` deep link preselects + opens the modal.
- List: real pager, Result via `resultTag`, quantities with UOM, sticky header.

### Task 7: Purchase Orders page + drawers (audit 2, 4, 7, 9, 17)

**Files:** `PurchaseOrdersPage.tsx`, `CreatePurchaseOrderModal.tsx`, `PurchaseOrderDetailDrawer.tsx`, `PurchaseOrderTraceDrawer.tsx`, `PurchaseOrderLinesFields.tsx`.

- Close/Cancel separation: "Cancel order" label, visual gap (Divider) between it and the rest in row + drawer footer; reason modal already confirms with distinct copy.
- Create modal: `order_date` defaults to today (editable), okText "Create draft order", required marks.
- Table: sticky, Number fixed left, Actions fixed right.
- Trace drawer: `receipt_key` label → "Internal receipt key" in secondary small text.
- LinesFields: drop "(FC-06)" from user copy; item options use `itemPickerLabel`.

### Task 8: Vendors page + shared configuration actions (audit 2, 6, 18)

**Files:** `ConfigurationRowActions.tsx` (Edit inline + "⋯" Dropdown overflow via `splitConfigurationActions`), `VendorsPage.tsx` (ledger column via `vendorLedgerWords`, sticky, fixed actions).

- Check existing tests pinning row-action rendering before changing; the contract (server decides; hide-never-enable) is preserved — presentation only.

### Task 9: Sidebar + jargon sweep (audit 3, 9)

**Files:** `AppLayout.tsx` (SIDER_WIDTH 200→240; icon/tooltip fixes as browser check dictates), `TallySyncPage.tsx` + `EntryDrawer.tsx` + `TallyMirrorPanel.tsx` + `SalesDocumentDrawer.tsx` ("unvalidated builder" → plain words; dismissed row tone via `saidTone`; "withheld (FC-06)" → plain), `inventory ItemIdentityFields.tsx` (Q59/DEC tooltips → plain), grep for any other rendered `Q\d+`/`DEC-`/`FC-` strings.

### Task 10: Verification + owner question

- `./vendor/bin/pint`, `php artisan test` (compare against the 8-failure baseline), `npm run typecheck`, `npm run test`, `npm run build`.
- Browser walk (local dev only, local DB): sidebar collapsed/expanded, each list at 1280px, drawer open/close race, create flows, PR→PO→GRN→QC chain on dev data.
- Add named (unnumbered, per merge-time-numbering rule) GST/supplier-bill question to `docs/factory/PENDING-OWNER-QUESTIONS.md`, referencing `docs/PURCHASE-TAX-CONFIGURATION-DESIGN.md` if aligned.
