# Procurement defect register — 28-Aug-2026

A multi-agent hunt across the procurement surface: six lenses (crash risk, flow
dead ends, server errors and unactionable refusals, permissions and FC-06, data
integrity, floor usability). Every candidate was then put to a skeptic prompted
to REFUTE it, and only what survived is listed here.

**24 candidates · 15 confirmed · 9 refuted.**

Nothing here is an owner decision, and severity is the reviewing agent's
judgement. The suggested fix is recorded with the defect as a starting point,
not an instruction — several would change behaviour a person should rule on.

## Status, 28-Aug-2026

**All 15 are closed** on `claude/procurement-defects` (PR 45).

Thirteen were fixed outright. The last two were the same question from opposite
sides — what an inspection MEANS when it covers only part of a line — and both
are closed by refusing to pretend rather than by inventing a rule:

- **Inspecting part of a line is now refused.** The disposition releases every
  bag that was not rejected and cannot do otherwise, because a line that already
  has an inspection refuses a second one, so a held-back bag would be stranded.
  Inspecting 10 of 20 therefore released the other 10 as though someone had
  looked at them. Partial inspection remains a reasonable thing to want; it
  needs re-inspection built alongside it and a rule for the remainder that only
  the quality desk can give. **The refusal names both horns so the question
  reaches a person instead of being silently answered by the code.**
- **A rejection on a line with no bags now records that no stock was issued.**
  The figure was written to the inspection while the material stayed in the
  store, and the record said nothing about it. The quantity is not this code's
  to move — on a bag-tracked line it is summed from real bags, and here the only
  source is a typed figure the service refuses to move stock on by design — so
  the fact is written down rather than acted on.

Neither change decides a factory rule. Both stop the system asserting something
that is not true.

| # | severity | file | defect |
|---|---|---|---|
| 1 | high | `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:70` | The goods-receipt form cannot record a partial delivery on a multi-line purchase order: it rebuilds the form with EVERY line that still has quantity outstanding (`replace(remainingLines)`, line 650), each line's quantity is validated `z.number().gt(0)` (line 70), and there is no per-line remove/skip control in the render (lines 963-993) — while `StoreGoodsReceiptRequest` (`'lines' => ['required','array','min:1']`, each line only tied to the order) accepts any non-empty subset, so the API allows the receipt the screen cannot express. |
| 2 | high | `frontend/src/features/quality/pages/IncomingInspectionsPage.tsx:35` | The only control that releases bags from `waiting_qc` builds its GRN-line picker from `listGoodsReceipts()` with no `per_page` — ProcurementDocumentQuery::PER_PAGE_DEFAULT = 20 — and the page has no search, no filter and `pagination={false}` (line 88), so lines on any arrival older than the newest 20 receipts cannot be inspected at all; those bags stay `waiting_qc`, MaterialBagIssueResolver refuses them at the scanner and IncomingQcHold::lockAndSum keeps subtracting their kilograms from every outflow of that item, permanently. The same picker also offers lines that already have an inspection, which the API refuses with a 422 (line 60), with nothing on screen marking them. |
| 3 | high | `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:504` | Lot/bag/barcode capture on a goods receipt is silently switched off for any login that holds procurement but not the production module, and the receipt then also lands with no incoming-QC hold on the material. |
| 4 | high | `backend/app/Modules/Quality/Services/IncomingInspectionService.php:161` | Incoming QC disposition releases every non-rejected `waiting_qc` bag on the receipt line into available stock regardless of how much was actually inspected — `dispositionBags` takes `$accepted` (line 121) and never reads it, so only `rejected_quantity` limits the release; combined with the one-disposition-per-line rule enforced at line 47, the uninspected remainder can never be inspected afterwards. |
| 5 | high | `frontend/src/features/procurement/components/PurchaseOrderLinesFields.tsx:142` | The shared PO lines editor renders only the array-level `errors.lines.root`, never the per-line `item_id`/`quantity`/`unit_price`/`schedules.*.due_date` errors, and neither host modal passes an onInvalid callback to `handleSubmit` — so an invalid purchase order simply does nothing when submitted, with no message anywhere. |
| 6 | high | `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:827` | The goods-receipt register renders `pagination={false}` over a server-paginated list fetched with no per_page, so it shows only the newest 20 receipts with no pager, no total and no filter bar — the page silently presents a partial register as the whole one. |
| 7 | high | `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:82` | The purchase requisition queue renders `pagination={false}` over a server-paginated list requested with no page argument, so it shows only the newest 20 requisitions and gives no sign that more exist. |
| 8 | medium | `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:46` | The purchase requisition queue calls `listPurchaseRequisitions()` with no paging argument and renders `pagination={false}` (line 82), and PurchaseRequisitionController::index (line 16) calls `paginate()` with the fixed default of 20 and supports no `per_page`; Approve and Reject exist only as buttons on a rendered row (line 104), so a draft requisition pushed past the newest 20 (the service orders by `id` DESC) can never be approved or rejected from any screen, while DashboardService (line 73) keeps counting it in `pending_requisitions`. |
| 9 | medium | `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:67` | The purchase-requisition create, approve and reject mutations (lines 59, 67, 68) have no onError handler, and frontend/src/lib/api.ts installs no global error toast (only a 401 redirect), so every server refusal on this page is completely invisible — no message, no alert, no field error. |
| 10 | medium | `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:67` | The Approve and Reject mutations declare only `onSuccess`, and there is no global mutation error handler, so a server refusal is swallowed and the row silently stays as it was. |
| 11 | medium | `frontend/src/features/procurement/pages/VendorsPage.tsx:56` | The create-vendor mutation has no `onError` handler, while the sibling edit mutation on the same page (line 71) opens a `Modal.error` with the server's message — so a rejected vendor creation produces no feedback at all. |
| 12 | medium | `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:134` | The New Requisition modal renders only `errors.lines.root` (line 157) and passes no onInvalid to handleSubmit, and its create mutation has no onError — so pressing OK can do nothing at all, whether the form or the server rejects it. |
| 13 | low | `frontend/src/features/procurement/components/PurchaseOrderDetailDrawer.tsx:401` | NOT A CRASH, but an FC-06 mislead aimed at exactly the readers FC-06 admits: the revision history's Unit Price column is added whenever `showsRates` is true, regardless of revision kind, and a 'close' snapshot carries no unit_price at all — so rateCell() reports every row of a short-close revision as "withheld" to an Owner/Accounts login, asserting a rate was hidden from them when none exists. |
| 14 | low | `frontend/src/features/procurement/pages/VendorsPage.tsx:55` | The createVendor mutation has no onError handler while the editMutation directly below it (line 71) does (Modal.error), so a server 422 on vendor creation is swallowed silently; the client zod schema also omits a length bound that StoreVendorRequest enforces. |
| 15 | low | `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:978` | The receiving form's per-line quantity is typed against a bare item label with no unit of measure shown, although the line's UOM is already in form state (`item_uom`, used only to decide whether the lot sub-form renders). |

## Detail

### 1. [high] `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:70`

**Defect.** The goods-receipt form cannot record a partial delivery on a multi-line purchase order: it rebuilds the form with EVERY line that still has quantity outstanding (`replace(remainingLines)`, line 650), each line's quantity is validated `z.number().gt(0)` (line 70), and there is no per-line remove/skip control in the render (lines 963-993) — while `StoreGoodsReceiptRequest` (`'lines' => ['required','array','min:1']`, each line only tied to the order) accepts any non-empty subset, so the API allows the receipt the screen cannot express.

**Trigger.** A sent PO with two lines (say resin + masterbatch) where only the resin arrives. The receiver must submit both lines; setting the masterbatch line to 0 or leaving it blank fails zod and the modal answers "This receipt cannot be submitted yet" (line 867). The arrival that is standing on the dock cannot be booked at all until the rest of the order turns up.

**Suggested fix.** In GoodsReceiptsPage.tsx take `remove` from the lines useFieldArray (line 563: `const { fields, replace, remove } = useFieldArray(...)`) and render a small danger button in each line's Space row (near line 995) — `{fields.length > 1 && <Button size="small" danger onClick={() => remove(index)}>Not in this delivery</Button>}` — so the line, its allocations and its lot rows all leave the payload. The `fields.length > 1` guard keeps the schema's `lines.min(1)` satisfied; no backend change is needed.

### 2. [high] `frontend/src/features/quality/pages/IncomingInspectionsPage.tsx:35`

**Defect.** The only control that releases bags from `waiting_qc` builds its GRN-line picker from `listGoodsReceipts()` with no `per_page` — ProcurementDocumentQuery::PER_PAGE_DEFAULT = 20 — and the page has no search, no filter and `pagination={false}` (line 88), so lines on any arrival older than the newest 20 receipts cannot be inspected at all; those bags stay `waiting_qc`, MaterialBagIssueResolver refuses them at the scanner and IncomingQcHold::lockAndSum keeps subtracting their kilograms from every outflow of that item, permanently. The same picker also offers lines that already have an inspection, which the API refuses with a 422 (line 60), with nothing on screen marking them.

**Trigger.** Twenty-one goods receipts posted after an arrival that was never inspected — routine, since the factory books a receipt per arrival. That material can then never be released to production, and nothing on the GRN page or the inspection page shows which arrivals are still uninspected. (The identical picker defect was already treated as a defect and fixed elsewhere: see the `listAllVendors` comment in features/procurement/api.ts and RECEIVABLE_PO_FILTERS' `per_page: MAX_PER_PAGE` on the GRN page.)

**Suggested fix.** frontend/src/features/quality/pages/IncomingInspectionsPage.tsx:33-36 — ask for the whole register, mirroring GoodsReceiptsPage.tsx:488-489: `queryKey: ['procurement', 'goods-receipts', 'all'], queryFn: () => listGoodsReceipts({ per_page: 1000 })`. The 'all' suffix is the identical request the GRN page's deep-link path already makes (shared cache, not a collision) and stays under the ['procurement','goods-receipts'] prefix that GoodsReceiptsPage.tsx:655 invalidates; 1000 is ProcurementDocumentQuery::PER_PAGE_MAX.

### 3. [high] `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:504`

**Defect.** Lot/bag/barcode capture on a goods receipt is silently switched off for any login that holds procurement but not the production module, and the receipt then also lands with no incoming-QC hold on the material.

**Trigger.** A storekeeper with procurement.manage (+ inventory.view) but no production.view/manage opens Goods Receipts and books an arrival. useProductionSettings (frontend/src/features/production/packing.ts:60-68) calls GET /production/settings, which is inside `module:production` (backend/routes/api.php:770), catches the 403 and returns null. GoodsReceiptsPage:505 then sets traceabilityEnabled = false, so :641 pre-opens no lot row, :994 renders no lot/bag fields, and :693 sends `lots: undefined`. The backend accepts it — StoreGoodsReceiptRequest.php:47 has `lines.*.lots => sometimes`, and GoodsReceiptService.php:495 only refuses lots when traceability is OFF. Result: stock moves, but no MaterialLot, no MaterialBag, no barcodes, nothing in the PO trace. Because IncomingQcHold::lockAndSum (backend/app/Modules/Inventory/Services/IncomingQcHold.php:85-92) sums only bags in `waiting_qc`, held = 0 for a lot-less receipt (StockMovementService.php:895-896 says this outright), so StockMovementService::decrementBalance applies no arrival hold and the material is immediately issuable to production without incoming QC. Nothing on the screen tells the storekeeper any of this; a colleague with production access receiving the same PO the same day gets the full lot/bag flow.

**Suggested fix.** Stop sourcing the traceability flag from the production module on this page. In `backend/app/Modules/Procurement/Http/Controllers/GoodsReceiptController::index`, publish it on the response the page already fetches: `return GoodsReceiptNoteResource::collection(...)->additional(['meta' => ['traceability_enabled' => (bool) config('production.traceability_enabled')]]);`. Then in `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:504-505` replace `const settings = useProductionSettings(); const traceabilityEnabled = settings?.traceability_enabled === true;` with `const traceabilityEnabled = data?.meta?.traceability_enabled === true;` (add the optional `meta.traceability_enabled` field to the `listGoodsReceipts` return type in `frontend/src/features/procurement/api.ts`). Everything downstream (`:641`, `:693`, `:994`) already keys off `traceabilityEnabled` and needs no change.

### 4. [high] `backend/app/Modules/Quality/Services/IncomingInspectionService.php:161`

**Defect.** Incoming QC disposition releases every non-rejected `waiting_qc` bag on the receipt line into available stock regardless of how much was actually inspected — `dispositionBags` takes `$accepted` (line 121) and never reads it, so only `rejected_quantity` limits the release; combined with the one-disposition-per-line rule enforced at line 47, the uninspected remainder can never be inspected afterwards.

**Trigger.** A GRN line of 100 kg received as 4 x 25 kg bags, all born `waiting_qc` (TraceabilityService::createLot). POST /api/v1/quality/incoming-inspections with inspected_quantity 25, accepted_quantity 25, rejected_quantity 0. Validation passes (line 60 only refuses inspected > line quantity, line 64 only requires accepted+rejected == inspected), nothing is rejected, `$heldId` stays null, and the loop at 162-169 flips all 4 bags to `in_store` — 100 kg is released as QC-passed against a recorded 25 kg inspected/accepted, and MaterialBagIssueResolver will now hand any of those bags to production. A second inspection on the line is then refused 422, so the 75 kg can never be inspected.

**Suggested fix.** In IncomingInspectionService::create(), tighten the line-60 check to demand the inspection cover the whole arrival line, since line 50 allows only one disposition per line: replace `if (bccomp($inspected, $line->quantity, 4) > 0)` with `if (bccomp($inspected, $line->quantity, 4) !== 0)` and throw with a message naming the line quantity ("one inspection disposes the whole arrival line — inspect all {$line->quantity}"). With line 64 already forcing accepted+rejected==inspected, this makes accepted+rejected==line quantity, so released kg = lineQty - rejectedWholeKg - heldKg <= accepted in every branch, including the boundary-bag hold. Do NOT instead cap the release by $accepted kg: under the one-disposition-per-line rule that swaps a silent over-release for a silently permanent uninspectable remainder, and deciding how many kg of a bag may be withheld is the bag-split question lines 104-107 deliberately leave to the owner. Mirror the guard in the UI (disable submit unless inspected equals the selected line's quantity, which lineOptions already carries). If sampling QC is the real floor workflow, that needs a separate sampled_quantity field and an owner ruling — not this code choosing to release 100 against a recorded 25.

### 5. [high] `frontend/src/features/procurement/components/PurchaseOrderLinesFields.tsx:142`

**Defect.** The shared PO lines editor renders only the array-level `errors.lines.root`, never the per-line `item_id`/`quantity`/`unit_price`/`schedules.*.due_date` errors, and neither host modal passes an onInvalid callback to `handleSubmit` — so an invalid purchase order simply does nothing when submitted, with no message anywhere.

**Trigger.** A buyer adds a line in New Purchase Order (CreatePurchaseOrderModal.tsx:62) or Amend (PurchaseOrderLifecycleModals.tsx:222), fills item and quantity but leaves Unit Price empty (or clicks "+ delivery schedule" and leaves the due date blank), and presses OK / Save amendment: zodResolver rejects, `mutate` never runs, the modal sits unchanged. On Amend this is the ordinary path for a login without finance standing — `ratesNotPrefilled` leaves every unit_price empty by design. The same folder's GRN modal (GoodsReceiptsPage.tsx:861) does pass an onInvalid handler with a comment saying why.

**Suggested fix.** One file, both hosts fixed: in PurchaseOrderLinesFields.tsx render each line's own errors with the same red `<div>` idiom already at :143. Inside the `fields.map` row (after the `<Space>`, before `<LineSchedulesEditor>`), emit the messages from `errors.lines?.[index]?.item_id?.message`, `.quantity?.message` and `.unit_price?.message`; thread the existing `errors` prop into `LineSchedulesEditor` and do the same there for `errors.lines?.[lineIndex]?.schedules?.[scheduleIndex]?.due_date?.message` / `.quantity?.message`. That indexing typechecks against `FieldErrors<LinesFormValues>` without a cast. Do not convert the rows to `Form.Item` (new import, disturbs the `Space align="baseline"` layout), and do not fix this with an onInvalid message alone — "check the lines marked in red" is unactionable while nothing turns red.

### 6. [high] `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:827`

**Defect.** The goods-receipt register renders `pagination={false}` over a server-paginated list fetched with no per_page, so it shows only the newest 20 receipts with no pager, no total and no filter bar — the page silently presents a partial register as the whole one.

**Trigger.** `listGoodsReceipts(undefined)` (api.ts:196) sends no per_page, so GoodsReceiptController::index falls back to ProcurementDocumentQuery::PER_PAGE_DEFAULT = 20. Once a 21st receipt exists, a storekeeper looking for last week's arrival cannot reach it from this page at all: there is no next page, no total count, and no search box, even though ListGoodsReceiptsRequest already supports vendor/purchase_order/item/date/q filters. Only a `?grn=` or `?po=` deep link (which switches to per_page 1000) finds it. VendorsPage and PurchaseOrdersPage in the same folder page server-side correctly.

**Suggested fix.** Give the register real server pagination, mirroring PurchaseOrdersPage.tsx:137-150. Do not simply delete `pagination={false}` — antd's client pager over the 20 fetched rows would print "1-10 of 20" and assert a false total, which is worse.

1. api.ts:196 — widen to `listGoodsReceipts(params?: { page?: number; per_page?: number })`.
2. GoodsReceiptsPage.tsx:487-489 — add `page`/`pageSize` state (default 1/20) and include them in the query key, or TanStack serves page 1 forever; keep the `['procurement','goods-receipts', ...]` prefix so the post-create invalidation still hits:
   `queryKey: ['procurement','goods-receipts', isDeepLinked ? 'all' : { page, pageSize }]`,
   `queryFn: () => listGoodsReceipts(isDeepLinked ? { per_page: 1000 } : { page, per_page: pageSize })`.
   Leave the `isDeepLinked` branch exactly as it is — per_page 1000 is what makes `?grn=` / `?po=` reach an old receipt.
3. Line 827 — `pagination={isDeepLinked || !data?.meta ? false : { current: data.meta.current_page, pageSize: data.meta.per_page, total: data.meta.total, showSizeChanger: true, pageSizeOptions: [20, 50, 100], showTotal: (t) => `${t} receipt${t === 1 ? '' : 's'}`, onChange: (p, s) => { setPage(p); setPageSize(s); } }}`

No backend change is needed — ListGoodsReceiptsRequest and ProcurementDocumentQuery already accept and bound `per_page` (max 1000).

### 7. [high] `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:82`

**Defect.** The purchase requisition queue renders `pagination={false}` over a server-paginated list requested with no page argument, so it shows only the newest 20 requisitions and gives no sign that more exist.

**Trigger.** `listPurchaseRequisitions()` (api.ts:68) sends no params and PurchaseRequisitionService::paginate defaults to 20 per page. With 21+ requisitions the older ones vanish from the screen — and because Approve/Reject exist only as buttons on this table's rows, a draft requisition that falls off the first page can never be approved or rejected at all.

**Suggested fix.** Frontend only — Laravel's `paginate()` reads `page` off the query string, so the controller needs no change. In `api.ts`: `export async function listPurchaseRequisitions(page = 1) { const { data } = await api.get('/procurement/purchase-requisitions', { params: { page } }); return data; }`. In `PurchaseRequisitionsPage.tsx`: add `const [page, setPage] = useState(1)`, change the query to `queryKey: ['procurement', 'purchase-requisitions', page]` / `queryFn: () => listPurchaseRequisitions(page)`, and replace the outer table's `pagination={false}` with `pagination={{ current: page, pageSize: data?.meta?.per_page ?? 20, total: data?.meta?.total ?? 0, showSizeChanger: false, onChange: setPage }}`. `showSizeChanger: false` is load-bearing, not cosmetic: `index()` has no `$this->perPage($request)`, so a user picking 50 would get 20 rows while the pager computes page counts from `total` — the same silent truncation. Leave the detail drawer's inner `pagination={false}` (~line 219/224) alone; those lines arrive embedded in the row. The existing `invalidate()` on the `['procurement', 'purchase-requisitions']` prefix still matches the page-keyed query.

### 8. [medium] `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:46`

**Defect.** The purchase requisition queue calls `listPurchaseRequisitions()` with no paging argument and renders `pagination={false}` (line 82), and PurchaseRequisitionController::index (line 16) calls `paginate()` with the fixed default of 20 and supports no `per_page`; Approve and Reject exist only as buttons on a rendered row (line 104), so a draft requisition pushed past the newest 20 (the service orders by `id` DESC) can never be approved or rejected from any screen, while DashboardService (line 73) keeps counting it in `pending_requisitions`.

**Trigger.** Twenty-one requisitions raised after an unapproved draft. The dashboard says e.g. 23 pending; the queue shows 20 rows, no pager, no filter, and the three oldest — the ones that have been waiting longest — are unreachable. Approved rows are also indistinguishable from ones already ordered (no `ordered` status exists and CreatePurchaseOrderModal never sends `purchase_requisition_id`), so the visible 20 fill with dead rows.

**Suggested fix.** Mirror the shipped vendor cure — three edits, no new concepts:

1. backend/app/Modules/Procurement/Http/Controllers/PurchaseRequisitionController.php: `public function index(Illuminate\Http\Request $request)` → `PurchaseRequisitionResource::collection($this->requisitions->paginate($this->perPage($request)))`, exactly as VendorController.php:36.

2. frontend/src/features/procurement/api.ts: `listPurchaseRequisitions(page = 1, perPage = 50)` → `api.get('/procurement/purchase-requisitions', { params: { page, per_page: perPage } })`; the defaults keep any no-arg caller working, same shape as listVendors.

3. frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx: add `const [page, setPage] = useState(1)` (+ perPage), put both in the queryKey and pass them to the queryFn, and replace `pagination={false}` at line 81 with the VendorsPage.tsx:103-110 block — `{ current: page, pageSize: perPage, total: data?.meta.total, onChange: setPage }`.

Do NOT do the frontend-only variant that relies on Laravel resolving `page` itself: it duplicates the page size by convention and leaves the one controller in the module that ignores per_page still ignoring it.

Out of scope for the smallest fix: the requisition→PO status transition (an `ordered` state + CreatePurchaseOrderModal sending purchase_requisition_id) is a feature, not this defect's cure.

### 9. [medium] `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:67`

**Defect.** The purchase-requisition create, approve and reject mutations (lines 59, 67, 68) have no onError handler, and frontend/src/lib/api.ts installs no global error toast (only a 401 redirect), so every server refusal on this page is completely invisible — no message, no alert, no field error.

**Trigger.** Two buyers have the queue open. One approves PR #12; the other clicks Approve on the same row. PurchaseRequisitionService::guardStatus throws InvalidStatusTransitionException -> 422 "Cannot transition purchase requisition from \"approved\" to \"approved\"." `invalidate` runs only onSuccess, so the button stops spinning, the row still reads Draft, and nothing at all appears on screen. Same silence for any 422 from the New Requisition modal, which simply stays open. Every other procurement screen surfaces the server's sentence (PurchaseOrdersPage Alert, GoodsReceiptsPage serverErrors, VendorsPage editMutation Modal.error).

**Suggested fix.** In PurchaseRequisitionsPage.tsx, import { apiMessage } from '@/features/procurement/components/apiMessage' (Modal is already imported). Add to createMutation: onError: (e) => Modal.error({ title: 'Could not create requisition', content: apiMessage(e, 'Unknown error') }) — leave its onSuccess (setModalOpen(false) + reset()) alone. For approve/reject, change `onSuccess: invalidate` to `onSettled: invalidate` so a stale Draft row corrects itself after a refusal, and add onError: (e) => Modal.error({ title: 'Could not approve' / 'Could not reject', content: apiMessage(e, 'Unknown error') }).

### 10. [medium] `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:67`

**Defect.** The Approve and Reject mutations declare only `onSuccess`, and there is no global mutation error handler, so a server refusal is swallowed and the row silently stays as it was.

**Trigger.** Two users open the queue and one approves a requisition first; the second presses Approve, PurchaseRequisitionService::guardStatus throws InvalidStatusTransitionException (422), the button stops spinning, the row still reads "draft", and nothing is shown. lib/queryClient.ts sets no MutationCache onError and lib/api.ts's interceptor only redirects on 401, so nothing else surfaces it.

**Suggested fix.** In frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx, add `message` to the existing antd import and `import { apiMessage } from '@/features/procurement/components/apiMessage';`, then give the mutations an onError using the module's existing helper:

const refuse = (fallback: string) => (error: unknown) => message.error(apiMessage(error, fallback));

const approveMutation = useMutation({ mutationFn: approvePurchaseRequisition, onSuccess: invalidate, onError: refuse('The approval was refused.') });
const rejectMutation  = useMutation({ mutationFn: rejectPurchaseRequisition,  onSuccess: invalidate, onError: refuse('The rejection was refused.') });

Add the same onError to createMutation ('The requisition could not be created.'). apiMessage prints the server's own 422 sentence ("Cannot transition purchase requisition from \"approved\" to \"approved\".") and the 403's "You don't have permission to access this feature." verbatim, falling back only when the body has none. Nothing else changes.

### 11. [medium] `frontend/src/features/procurement/pages/VendorsPage.tsx:56`

**Defect.** The create-vendor mutation has no `onError` handler, while the sibling edit mutation on the same page (line 71) opens a `Modal.error` with the server's message — so a rejected vendor creation produces no feedback at all.

**Trigger.** A buyer fills New Vendor and the server refuses (a 422 from StoreVendorRequest, or a 403): `confirmLoading` clears, the modal stays open with the typed values still in it, no message appears, and nothing was saved. The page cannot tell the user whether the vendor exists. `showApiError` (lib/showApiError.ts, used by ConfigurationActionsCell) is the helper this path skips.

**Suggested fix.** In frontend/src/features/procurement/pages/VendorsPage.tsx add the house helper to the create mutation (one import + one line); prefer showApiError over the sibling's hand-rolled Modal.error because it names the refused field on a seven-field form:

+ import { showApiError } from '@/lib/showApiError';

  const mutation = useMutation({
      mutationFn: createVendor,
      onSuccess: () => { invalidate(); setModalOpen(false); reset(); },
+     onError: (error: unknown) => showApiError(error, 'Could not create vendor'),
  });

Leave the modal open with the typed values intact — that is correct, the buyer needs to correct the refused field.

### 12. [medium] `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:134`

**Defect.** The New Requisition modal renders only `errors.lines.root` (line 157) and passes no onInvalid to handleSubmit, and its create mutation has no onError — so pressing OK can do nothing at all, whether the form or the server rejects it.

**Trigger.** A requester clicks Add Line, leaves the Item select empty (or the quantity blank), and presses OK: zod's per-line "Item is required" is never rendered, `createMutation.mutate` never runs, and the modal is unchanged — indistinguishable from a dead button.

**Suggested fix.** In PurchaseRequisitionsPage.tsx, copy the two idioms already used next door. (1) Import `Alert` from antd and `apiMessage` from '@/features/procurement/components/apiMessage', and put the CreatePurchaseOrderModal.tsx:70-78 block at the top of the Modal body: `{createMutation.isError && <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Not created" description={apiMessage(createMutation.error, 'The requisition could not be created.')} />}`. (2) Inside the `fields.map` at line 155, render the per-line messages in the same red-div style as the existing `.root` error: `{errors.lines?.[index]?.item_id && <div style={{ color: '#ff4d4f' }}>{errors.lines[index]?.item_id?.message}</div>}` and the same for `quantity`. Leave the identical per-line gap in PurchaseOrderLinesFields.tsx:142 alone — that is a separate finding on a surface already being touched by another branch.

### 13. [low] `frontend/src/features/procurement/components/PurchaseOrderDetailDrawer.tsx:401`

**Defect.** NOT A CRASH, but an FC-06 mislead aimed at exactly the readers FC-06 admits: the revision history's Unit Price column is added whenever `showsRates` is true, regardless of revision kind, and a 'close' snapshot carries no unit_price at all — so rateCell() reports every row of a short-close revision as "withheld" to an Owner/Accounts login, asserting a rate was hidden from them when none exists.

**Trigger.** A user with finance.view/finance.manage opens a short-closed PO whose live lines carry rates (so showsRates is true) and expands a kind='close' revision. PurchaseOrderService::remainingSnapshot() writes only quantity/quantity_received/remaining (no unit_price), and PurchaseOrderRevisionResource's own docblock states 'A close snapshot has no rate (quantities only) and no note' — but rateCell()'s contract is 'absent key means withheld', so every cell in that column reads "withheld".

**Suggested fix.** frontend/src/features/procurement/components/PurchaseOrderDetailDrawer.tsx:400 — gate the column on the kind the component already discriminates: `...(showsRates && revision.kind !== 'close' ? [{ title: 'Unit Price', ... }] : [])`. Use `!== 'close'` (not `=== 'amend'`) so an older backend that omits `kind` keeps showing rates on amend rows; KIND_AMEND/KIND_CLOSE are the only two kinds on the model. Do NOT change rateCell() — its absent-key-means-withheld rule is deliberate, test-pinned, and correct for the five PurchaseOrderTraceDrawer call sites.

### 14. [low] `frontend/src/features/procurement/pages/VendorsPage.tsx:55`

**Defect.** The createVendor mutation has no onError handler while the editMutation directly below it (line 71) does (Modal.error), so a server 422 on vendor creation is swallowed silently; the client zod schema also omits a length bound that StoreVendorRequest enforces.

**Trigger.** `phone: z.string().optional()` (line 15) has no `.max()`, but StoreVendorRequest validates `'phone' => ['nullable','string','max:32']`. Entering two contact numbers, e.g. `+91 98765 43210 / +91 98765 43211` (33 chars), returns 422 "The phone field must not be greater than 32 characters." The New Vendor modal stays open with no message on any field and no alert; the user can only guess which value the server disliked.

**Suggested fix.** In frontend/src/features/procurement/pages/VendorsPage.tsx, add to the create mutation (line 65) the same handler the editMutation below already uses — `Modal` is already imported, no new dependency:

    onError: (error: any) => {
        Modal.error({ title: 'Could not create vendor', content: error?.response?.data?.message ?? 'Unknown error' });
    },

Optional belt, same file: `maxLength={32}` on the Phone `<Input>` in the New Vendor modal. Do NOT add `.max(32)` to the zod `phone` — that Form.Item has no `validateStatus`/`help` wired, so a client-side bound would block submit with nothing on screen, trading one silent dead-end for another.

### 15. [low] `frontend/src/features/procurement/pages/GoodsReceiptsPage.tsx:978`

**Defect.** The receiving form's per-line quantity is typed against a bare item label with no unit of measure shown, although the line's UOM is already in form state (`item_uom`, used only to decide whether the lot sub-form renders).

**Trigger.** On a partial arrival the storekeeper overwrites the prefilled remaining quantity on a non-mass line (preforms, caps, cartons) and types a bare number, with nothing on the row saying whether the order is counted in Nos., boxes or kg. For mass items the kg is visible only because the lots sub-form spells it out; for every other item the unit appears nowhere on the form, the register, or the receipt drawer's Quantity column.

**Suggested fix.** GoodsReceiptsPage.tsx:980 — show the line's own unit on the quantity input. Rename the shadowing render-prop arg so the outer row stays reachable: `render={({ field: qty }) => <InputNumber {...qty} min={0} placeholder="Quantity" addonAfter={field.item_uom || undefined} />}`. Use `item_uom` verbatim from the item master — no alternate unit and no conversion, since Q58(a)/(c) are unanswered. Optional same-shape follow-on: the receipt drawer's Quantity column (~line 1054) can render `${line.quantity} ${uomOf(line.item) ?? ''}`.

