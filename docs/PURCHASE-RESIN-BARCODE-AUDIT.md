# Purchase & Resin-Barcode Audit — SWAASHPET POLYMERS ERP

*Prepared 2026-08-12 for the owner's meeting.*

**Method:** code-only. Nothing was read from or written to the live system, no
workflow was run. Every claim cites `file:line`. Where a fact depends on live
configuration, it is listed as a question rather than asserted.

---

## 1. The walkthrough — a real Reliance resin PO through the ERP today

### Step 0 · Purchase requisition — works, but it is a dead end

Screen `/procurement/purchase-requisitions` (`frontend/src/app/App.tsx:145`).
You can raise one, and Approve/Reject appear on any draft row
(`PurchaseRequisitionsPage.tsx:101-115`).

It is a dead end because **the purchase-order form has no field to carry a
requisition forward.** `purchase_requisition_id` is nullable in both the schema
(`2026_07_18_160519_create_purchase_orders_table.php:14`) and the request rules
(`StorePurchaseOrderRequest.php:18`), and the word "requisition" does not appear
anywhere in `PurchaseOrdersPage.tsx`. Every PO raised in the app carries
`purchase_requisition_id = null` by construction. Approving a requisition
changes a status and nothing else.

### Step 1 · Purchase order — works, and the screen says Tally owns it

Screen `/procurement/purchase-orders` (`App.tsx:146`). The form carries a
**"Mirrored from Tally"** switch and a Tally order-number field
(`PurchaseOrdersPage.tsx:293-313`): *"Tally is the PO and delivery-schedule
source of truth. A mirror records the real order's exact identities; it arrives
already sent and is corrected in Tally, never edited here."* The backend honours
it — a mirror PO skips Draft and is created already `Sent`
(`PurchaseOrderService.php:102-106`).

For a real Reliance order the intended path is: raise the PO **in Tally**, then
mirror it. The ERP-native Draft→Send path exists
(`PurchaseOrderService.php:112-125`) but is secondary.

### Step 2 · GRN against that PO — **THE FIRST HARD STOP**

Screen `/procurement/goods-receipts` (`App.tsx:147`).

**A resin receipt cannot be entered unless the arrival divides exactly into a
uniform bag weight.**

- For any kg item one lot row is force-opened (`GoodsReceiptsPage.tsx:348-355`,
  `isMassUom` `:112-114`).
- That row requires a bag count and a kg-per-bag figure — both
  `z.number({error: ...})` with no optional escape (`:84-85`), rendered as two
  mandatory inputs (`:181-198`).
- The form sends **only** `bag_count` and `bag_weight_kg` and computes the lot
  total as their product (`:400-405`).
- The server recomputes `bagTotal = bag_weight_kg × bag_count`
  (`GoodsReceiptService.php:395`) and **refuses the whole receipt unless it
  equals the line quantity exactly** (`:414-419`).

40 × 25 kg = 1000 kg passes. A weighbridge figure of 998.5 kg does not. 39 full
bags plus one part bag does not. The API *does* accept a per-bag weight array
(`StoreGoodsReceiptRequest.php:57-58`, consumed at
`GoodsReceiptService.php:382-393`) — **but no screen supplies it.**

Given `AGENTS.md` ("Never invent a factory value — a weight, a cycle time, a
dose, a Tally name") and the withdrawn derived bag weight in PR #128, the honest
description is not "the button is greyed out" — it is **the receipt cannot be
entered truthfully.**

### Step 3 · Bag barcodes — they exist, and every one is ours, not Reliance's

The GRN posts atomically: stock movement, supplier lot, one `material_bags` row
per bag (`GoodsReceiptService.php:166-216`), and a label drawer opens
(`GoodsReceiptsPage.tsx:706-708`).

Every barcode is app-generated: `sprintf('LOT%d-B%d', $lot->id, $seq)`
(`TraceabilityService.php:153`). A supplier barcode *can* be supplied via the
API (`StoreGoodsReceiptRequest.php:50-56`) but the form never sends one. **Every
Reliance bag must be physically relabelled with an ERP-printed label before it
can be scanned.**

### Step 4 · Day-bin load — **THE SECOND HARD STOP**, and it crosses departments

Scan a freshly received bag and it is refused: *"Bag {barcode} is waiting for
incoming QC — it cannot be loaded until quality releases it."*
(`FactoryDayBinService.php:212-216`). A bag born on a GRN is `WaitingQc`
(`TraceabilityService.php:162-164`), released only by an Incoming Inspection
(`IncomingInspectionService.php:163-169`).

It is a *stop*, not a step, because the release lives in a **different module
behind a different permission**: `/quality/incoming-inspections`
(`App.tsx:156`), route group `middleware('module:quality')`
(`routes/api.php:239-240`) → `quality.manage` for a write
(`EnsureModulePermission.php:24-26`). **A store or purchase user holding
`procurement.manage` cannot release their own arrival.**

Two further points:
- The inspection is **one-shot per arrival line**; a second is refused
  (`IncomingInspectionService.php:50-54`). A mis-keyed accept quantity is not
  re-inspectable.
- If QC rejects a quantity falling *inside* a bag rather than on a boundary,
  that bag is held at `waiting_qc` indefinitely (`:153-158, 185-191`). Whether
  QC may split one bag's kilograms is an open owner decision.

### Step 5 · Consumption — works once released

Full-bag scan empties the bag to `Consumed`; a weighed partial pours off the kg
and leaves the bag `InStore` with its remainder
(`FactoryDayBinService.php:268-273`). Returns are capped at `original_kg` so
material cannot be minted (`TraceabilityService.php:305-313`).

### Summary

| Step | Screen | Outcome |
|---|---|---|
| Requisition | `/procurement/purchase-requisitions` | Works; cannot be carried into a PO |
| PO | `/procurement/purchase-orders` | Works; screen states Tally is master |
| GRN | `/procurement/goods-receipts` | **HARD STOP** — `GoodsReceiptService.php:414-419` |
| Barcodes | GRN drawer, `/inventory/material-lots` | Works; all ERP-generated, bags must be relabelled |
| Day-bin load | Shift Floor scan | **HARD STOP** — `FactoryDayBinService.php:212-216`, needs `quality.manage` |
| Consumption | Shift Floor | Works |

---

## A. GRN — against a PO, standalone, and receipt quantities

**A PO is mandatory; there is no standalone GRN.** `purchase_order_id` is
`required|exists` (`StoreGoodsReceiptRequest.php:22`), loaded with `findOrFail`
(`GoodsReceiptService.php:94-97`). The PO must be `Sent` or
`PartiallyReceived` (`:99-101`).

- **Short receipt:** supported; PO moves to `PartiallyReceived` (`:509-515`).
- **Partial / multiple receipts:** supported and correctly serialised — the PO
  row is locked for the transaction (`:94-96`).
- **Over receipt:** hard-blocked, **no tolerance of any kind** (`:136-139`,
  `OverReceiptException.php:11-15`). Not a percentage, not a kg band, not
  configurable. A lorry delivering slightly over cannot be received until the PO
  is amended. (`config/production.php` has a `tolerances` block, but those are
  *production* bands — none touches goods receipt.)
- **PO cancellation:** `PurchaseOrderStatus::Cancelled` exists
  (`PurchaseOrderStatus.php:11`) but is **never written anywhere**. There is no
  way to cancel a PO.
- **Replay safety is good:** `receipt_key` + canonical payload hash
  (`:69-77, 440-471`); a retry returns the original receipt, a reused key with
  different data is refused, a concurrent race is caught by the unique
  constraint (`:229-241`).

---

## B. PO approval and who may raise one

**There is no PO approval flow at all.** The only transition is Draft → Sent
(`PurchaseOrderService.php:112-125`). No `approve` method, no controller action
(`PurchaseOrderController.php:14-36` has only `index`, `store`, `send`), no
route.

**The creator can send their own PO.** `created_by` is recorded (`:64`) but
`send()` never receives or compares a user. No separation of duty.

**One permission unlocks the entire department.** The procurement group carries
`middleware('module:procurement')` (`routes/api.php:184`) → `procurement.manage`
for every write (`EnsureModulePermission.php:24-26, 29`). That single permission
authorises: raise a requisition, approve it, reject it, create a PO, send it,
and receive the goods. There is no `procurement.approve` — the catalogue only
generates `.view`/`.manage` per module (`PermissionService.php:81-87`), a
documented deliberate simplification (`:12-16`).

**Requisitions can be self-approved and the approver is never recorded.** The
approve endpoint has no check beyond module permission
(`PurchaseRequisitionController.php:28-31`); the service only guards the status
transition (`PurchaseRequisitionService.php:53-59`). The table has **no
`approved_by` column at all**
(`2026_07_18_160517_create_purchase_requisitions_table.php`).

**No value threshold or approval limit exists.** A ₹100 PO and a ₹10,00,000 PO
travel identical paths. The only line validation is `unit_price >= 0`.

**The codebase knows how to do this properly elsewhere.** Production gates by
*role*: `abort_unless($request->user()->hasAnyRole(['Plant Manager',
'Administrator']), 403, ...)` (`ShiftProductionEntryController.php:104, 113`) —
*"Gated by ROLE (not just module permission): each stage belongs to a specific
desk."* Those are the only `hasRole`/`hasAnyRole` call sites in the repo.
Procurement does not use the pattern.

**Frontend gating is cosmetic.** The nav hides the group
(`AppLayout.tsx:263-267`) but `ProtectedRoute.tsx` checks authentication only —
typing the URL loads the page; only the API returns 403. Approve/Reject/Send
buttons gate on row status alone, with no permission check.

---

## C. Bag barcodes for resin

**Generated by the ERP, not supplied by the user** —
`TraceabilityService.php:153`:

```php
'barcode' => $barcodes[$seq - 1] ?? sprintf('LOT%d-B%d', $lot->id, $seq),
```

**Format:** `LOT{lot_id}-B{sequence}`, e.g. `LOT84-B17`. Not GS1 — a
database-id-derived internal code, unique by column constraint, max 64 chars.

**Supplier barcodes:** accepted by the API (`StoreGoodsReceiptRequest.php:50-56`;
validated for count, in-payload duplicates and collisions at
`GoodsReceiptService.php:365-380, 423-434`) but **the UI never sends them**.

**So when Reliance's bags carry no barcode, nothing breaks** — that is the
normal and only on-screen path. The cost is that **every bag must be physically
relabelled** before the floor can scan it.

**Label printing is real.** `MaterialBagLabels.tsx` renders CODE128 via JsBarcode
(`:56-70`) and opens a print window, one label per page (`:95-139`). Each label
carries SKU + material, supplier lot + "Bag n of N", original kg, received date
and registration timestamp (`:82-93`). Entry points: after posting a GRN
(`GoodsReceiptsPage.tsx:706-708`) and reprint from
`/inventory/material-lots` (`MaterialLotsPage.tsx:277-280`).

**One defect, scoped honestly.** The frontend status vocabulary is stale:
`MaterialBagStatus` (`frontend/src/features/production/types.ts:1145`) lists only
`in_store | in_day_bin | consumed | returned`; the backend enum also has
`waiting_qc` and `rejected_qc` (`MaterialBagStatus.php:14, 18`). Since **every
GRN bag is born `waiting_qc`**, the on-screen status tag renders blank for
exactly the bags a receiver is looking at. The **printed label is unaffected** —
`labelDetails` excludes status (`MaterialBagLabels.tsx:82-93`).

**What the day-bin scan does:** resolves the barcode under a row lock, then in
order — reject if consumed/empty (`FactoryDayBinService.php:203-207`), if
`waiting_qc` (`:212-216`), if `rejected_qc` (`:218-222`), if no store recorded
(`:224-228`), if quantity exceeds remaining (`:244-246`) — then decrements and
appends the ledger row in one transaction.

**Part-used bags:** handled correctly (`:268-273`); returns capped at
`original_kg` (`TraceabilityService.php:300-313`).

**Double scans:** protected by bag state, not an idempotency key.
- *Full-bag double scan is safe* — the second is refused as already consumed
  (`:203-207`), and the row lock (`:196`) serialises simultaneous scans.
- *Partial-load double scan double-counts.* Two scans of "10 kg off bag X" are
  indistinguishable from a genuine second pour. **This is the one real
  idempotency gap in the scan path.**

**One enforcement asymmetry.** An older endpoint `POST /production/day-bin/load`
(`routes/api.php:602` → `TraceabilityService::loadBagToDayBin` `:225-281`) checks
remaining quantity and FIFO but **never checks `waiting_qc`**. No screen calls it
(`loadDayBin` at `frontend/src/features/production/api.ts:1221-1222` has zero
callers), but `/api/v1` is a deliberately public product surface, so the route is
live to any token holder. Usually the FIFO guard incidentally blocks it, but when
no released bag of that material exists the FIFO head is `null` and the check
passes through (`:376`), letting an uninspected bag load. Worth closing or
deleting.

---

## D. Vendor master, Tally ledger, and the Receipt Note payload

**The vendor master does NOT resolve to a Tally ledger.** `Vendor` has fillable
`code, name, email, phone, address, gstin, state_code, is_active`
(`Vendor.php:9`) — no ledger id, no ledger name, no Tally GUID, and no migration
adds one.

Instead the vendor's **name is passed verbatim** as the party ledger
(`TallySyncService.php:124`):

```php
'party_ledger' => $note->purchaseOrder?->vendor?->name,
```

A synced `ledgers` table exists (`2026_07_23_000006_create_ledgers_table.php`)
and **the vendor master is not joined to it.** The link between "Reliance" in the
ERP and the Reliance ledger in Tally is an unverified string match — no dropdown
from the pulled ledger list, no existence check, no preview. A one-character
difference surfaces only when Tally rejects the post.

### Receipt Note payload — `TallySyncService.php:120-147`

| Field | Source | Line |
|---|---|---|
| `voucher_type` | `'Receipt Note'` | :121 |
| `voucher_date` | GRN `received_date` | :122 |
| `voucher_number` | `receipt_note_reference` else `GRN-{id}` | :123 |
| `party_ledger` | **vendor `name`, verbatim** | :124 |
| `party_gstin` | vendor `gstin` | :125 |
| `godown` | resolved from receiving warehouse | :126 |
| `narration` | GRN notes | :127 |
| `lines[]` | item, quantity, rate, amount | :108-116 |
| `total_amount` | sum of line amounts | :118, :129 |
| `tracking_number` | GRN tracking number | :136 |
| `tally_order_no` | mirrored Tally PO number | :137 |
| `order_due_dates[]` | due_date, quantity, tally_reference | :138-146 |

**No purchase ledger, no CGST/SGST/IGST ledger, no tax amount, no batch number.**

Three things to know:

1. **The trigger is synchronous, inside the GRN's own transaction.**
   `event(new GoodsReceiptNoteReceived($grn))` fires at
   `GoodsReceiptService.php:225` inside the transaction opened at `:80`, and the
   listener calls `enqueueGoodsReceiptNote` directly
   (`TallySyncEventServiceProvider.php:47-49`). **If payload construction throws,
   the entire goods receipt rolls back** — stock movement, lots and bags
   included.
2. **The last three fields never reach Tally.** The agent's
   `ReceiptNotePayload` omits `tracking_number`, `tally_order_no` and
   `order_due_dates` (`receiptNote.ts:4-14`); `party_gstin` and `total_amount`
   are declared but never written into XML. So the arrival **cannot yet clear the
   right outstanding order allocation in Tally.**
3. **The builder is explicitly unvalidated** (`receiptNote.ts:16-23`):
   *"BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE."*
   It hardcodes `<BATCHNAME>Primary Batch</BATCHNAME>` (`:56`).

**Net accounting effect: a Receipt Note moves stock IN and names a supplier. It
books no liability, no purchase value and no GST. The purchase bill must still be
keyed into Tally by hand.**

---

## E. Which Tally vouchers fail today because ledgers are unmapped

The honest answer is narrower — and in one way worse — than the question assumes.

**Only one of the eight ledger roles is read by any code, and even that one does
not hard-fail when unmapped.** The roles are `Sales, Purchase, Cgst, Sgst, Igst,
RoundOff, ResinConsumption, RegrindCredit` (`TallyLedgerRole.php:13-21`). A
repo-wide grep for `TallyLedgerRole::` outside the enum returns four hits: two
internal to the mapping service, one populating the Settings dropdown, and **one
single production read** — `TallySyncService.php:72`.

| Role | Read by code? | What actually happens |
|---|---|---|
| **Sales** | Yes (`TallySyncService.php:72`) | Unmapped ⇒ `null` ⇒ agent substitutes the hardcoded literal `'Sales Account'` (`salesInvoice.ts:60`). No exception, no log, no block. |
| **Purchase** | **No** | No Purchase voucher exists — no `enqueuePurchase*`, no `case 'Purchase'` (`voucherBuilders/index.ts:16-24`). Mapping it changes nothing. |
| **CGST / SGST / IGST** | **No** | Zero reads. No voucher emits a tax ledger entry. |
| RoundOff / ResinConsumption / RegrindCredit | **No** | Zero reads. |

*Caution:* `backend/app/Modules/Compliance/` contains `cgst`/`sgst`/`igst` keys —
that is the Compliance module's own GST arithmetic, unrelated to
`TallyLedgerRole`.

**The real failure mode.** `TallyLedgerMappingService::get()` returns `null`
silently (`:29-32`) and `UpdateLedgerMappingsRequest` requires nothing (`:16-20`).
**There is no readiness gate on ledger mappings anywhere** — no completeness
check, no validation that a name exists in the pulled `ledgers` table, no blocking
preview. So the failure is not a refused save; it is a voucher posted to a ledger
name nobody chose, discovered hours later in the Failed tab.

**Which mapping fixes what** (mappings only, no values proposed):
- **Sales** — the only mapping that changes behaviour today. Setting it replaces
  the hardcoded `'Sales Account'` fallback.
- **Purchase** — unblocks nothing until a Purchase voucher builder is written.
  There is none.
- **CGST / SGST / IGST** — unblock nothing until the Sales builder emits tax
  ledger entries. It does not, and says so (`salesInvoice.ts:29-32`).

**Read that as: the ERP cannot post a GST-correct sales voucher today, and no
ledger mapping alone will fix it — the builder needs the code.**

**One separate latent defect.** Under shift granularity the cloud enqueues
voucher type `'Stock Journal'` (`TallySyncService.php:659-670`), and the in-repo
agent (v0.2.0, `tally-sync-agent/package.json:4`) has **no `case 'Stock Journal'`**
(`voucherBuilders/index.ts:16-24`) — it throws. The comment names the prior outage
(entries #33/#34, 07-Aug). Dormant only because granularity defaults to `batch`.

---

## F. What is missing for a full purchase cycle

| Capability | Classification | Evidence |
|---|---|---|
| **Purchase invoice / supplier bill** | **NOTHING** | No table, model, service, route, screen or Tally path. Zero matches for `purchase_invoice`, `supplier_invoice`, `vendor_invoice`. The `invoices` table is sales-only — `sales_order_id` + `customer_id` both required (`2026_07_18_163626:13-14`); no `vendor_id`. |
| **Three-way match** | **NOTHING** | Zero matches for `three_way`, `invoice_match`. Structurally impossible — the third leg does not exist. Only PO↔GRN check is over-receipt; no price variance, no match status. |
| **Vendor payments / AP aging** | **NOTHING** | No payments table, no `Payment*` class, no `payables_outstanding`. "Accounts Payable" exists only as a seeded chart-of-accounts *name*. Money owed can only be recorded by hand-keying a Journal Entry. |
| **Debit notes / purchase returns** | **NOTHING** (a text field, not a document) | No `debit_notes`/`purchase_returns` table. Only `rejections_out_reference`, a nullable `string(64)` on `incoming_inspections` (`2026_08_02_200001:70-76`) — no quantity, value, vendor link, status or lines. The UI says: *"Rejections Out ref {…} — recorded; no Tally voucher until its shape is proven"* (`IncomingInspectionsPage.tsx:109-114`). |

**Finance confirms the gap in its own words** (`AccountsReceivableService.php:11-14`):
*"Deliberately no Accounts Payable counterpart yet: Procurement has no vendor-bill
document with its own paid/unpaid status to source one from."*

**One near-miss — MODEL-BUT-NO-SCREEN.** `material_cost_versions` is an
append-only rate history per lot whose `kind` vocabulary includes `'invoice'` and
`'landed_cost'` (`2026_08_02_100002:48`). The migration header states the intent:
*"a GRN rate is PROVISIONAL. The purchase invoice, the freight bill and the
landed-cost workings all arrive after the resin is already in the store."* Model,
service and both API routes exist (`routes/api.php:180-181`). **But no screen
writes one** — a grep of `frontend/src` for `cost-version`/`costVersion`/
`CostVersion` returns zero matches in zero files. **An accountant cannot file an
invoice rate through the UI today.** Note it deliberately changes nothing else
(`MaterialCostVersionService.php:19-26`): it is a rate memo, not an AP document.

---

## Questions for the owner — the code cannot answer these

1. **Which roles actually hold `procurement.manage` on live?** Code shows only
   `Administrator` (`PermissionSeeder.php:29-30`), but roles are edited at runtime
   (`RoleService.php:26-45`). Given that one permission covers raise + approve +
   PO + send + receive, the live answer matters.
2. **Is a day-bin warehouse configured?** Without one every bag scan fails
   (`FactoryDayBinService.php:182-186`).
3. **Is `PROD_TRACEABILITY` on?** With it off the whole lots/bags/day-bin surface
   returns 404 (`EnsureTraceabilityEnabled.php:20`).
4. **Which Tally ledger roles are mapped, and does the Sales one match a real
   ledger?** Only Sales has any effect today; empty silently posts to
   `'Sales Account'`.
5. **What agent version is deployed?** The in-repo agent is 0.2.0 and lacks the
   `Stock Journal` builder — safe only while granularity stays `batch`.
6. **Do ERP vendor names match Tally ledger names exactly?** Nothing checks. This
   is the single highest-risk unverified assumption in the Receipt Note path.
7. **May QC split one bag's kilograms?** Recorded in code as an open decision
   (`IncomingInspectionService.php:104-107`). Until ruled, a boundary bag is stuck
   at `waiting_qc`.

---

## The three things to put in front of the owner first

1. **The GRN cannot record an uneven delivery.** Until the receipt form can accept
   individual bag weights — the API already accepts them
   (`StoreGoodsReceiptRequest.php:57-58`), only the screen does not send them — a
   lorry whose weight does not divide evenly into uniform bags cannot be received
   honestly. This is the first thing that stops a real Reliance PO.

2. **Procurement has no separation of duty.** One permission raises, approves,
   orders, sends and receives; requisitions can be self-approved; the approver is
   not even recorded; there is no PO approval step and no value threshold. The
   role-gated pattern already exists in this codebase
   (`ShiftProductionEntryController.php:104, 113`) — Procurement just does not use
   it.

3. **The purchase cycle ends at "material arrived."** No supplier bill, no
   three-way match, no payments, no debit notes — none of them as a model, a
   screen or a Tally path. Everything from the bill onward happens in Tally by
   hand, and the Receipt Note the ERP sends carries no purchase value, no GST and
   no order reference Tally can clear against.

---

# Addendum — multi-delivery, short close, and where a PO is raised

*Prepared 2026-08-12, code-only, on `main`. Nothing read from or written to
the live system. Added after the owner described his real flow: a tonnage PO
with delivery schedules, several arrivals against the same PO reference,
remaining tracked per line, auto-close when full.*

## 1. Remaining per line — the visibility is WEAK

**The purchase-order screen never renders a remaining figure anywhere.** A
purchase person cannot answer "how much is still to come" at a glance; they
can answer it one order at a time, by opening a drawer and subtracting in
their head.

- The PO **list** (`PurchaseOrdersPage.tsx:193-233`) shows ID, Status,
  Source, Vendor, Order Date, **line count**, Actions. No quantity column, no
  received column, no remaining column — the list carries no numbers at all.
- The **drawer** (`:367-431`) shows a lines table with Item, **Quantity**,
  **Received**, Unit Price, Amount. That is the closest the screen comes:
  the user subtracts. For a 1-tonne order after two arrivals it shows `1000`
  and `800`; it does not show `200`.

**The server already computes remaining and the screen throws it away.**
`PurchaseOrderLineResource` serves `quantity_received` (`:18`) *and* the full
delivery-window array with a server-computed `remaining` per window
(`:22-29`, arithmetic in `PurchaseOrderSchedule.php:32-35`).
`PurchaseOrderService::paginate` eager-loads `lines.schedules` (`:17`), so it
is in every list response, and the frontend type declares it
(`procurement/types.ts:44`). **`PurchaseOrdersPage.tsx` never references
`line.schedules`** — not in the table, not in the drawer. The delivery
schedule the owner entered when raising the PO becomes invisible the moment
it is saved.

Remaining is visible in exactly one place: **inside the New Goods Receipt
modal**, and only once you have started creating a receipt — the quantity
input is prefilled with `quantity − quantity_received`
(`GoodsReceiptsPage.tsx:329-335`) and each window shows `open {remaining}`
(`:144-145`). So reading "how much is still to come" means opening the
receipt form, selecting the PO, reading the prefill, and abandoning the form.

**Two things compound it.**

- **The dashboard does not help.** `DashboardService::incomingStock()`
  (`:123-139`) returns id, vendor, expected_date, status and up to three item
  *names* — no quantities. The procurement tile is a bare count of open POs.
- **Only the newest 20 orders are reachable.** The PO table sets
  `pagination={false}` (`:192`) over a query with no `per_page` (`:116-119`),
  and the endpoint defaults to 20. No pager, no status filter, no sort. The
  same applies to the order picker inside the GRN form (`:258`, `:265-268`) —
  **a still-open PO that has fallen past the newest 20 cannot be selected for
  its next arrival.**

**Verdict: weak.** The number the owner named as important exists in the
database, is computed correctly, is transmitted to the browser, and is
rendered on no screen a purchase person would look at.

## 2. The multi-delivery walk — 400 / 400 / 200 against 1000 kg

Status machine: `GoodsReceiptService::recomputeOrderStatus()` (`:497-516`),
called once per receipt at `:221`. The PO row is locked for the whole
transaction (`:94-97`), so concurrent arrivals serialise.

| | PO status after | `quantity_received` | remaining | what the user sees |
|---|---|---|---|---|
| PO raised (Tally mirror) | `Sent` (`PurchaseOrderService.php:104-106`) | 0 | 1000 | Quantity 1000, Received 0 |
| Arrival 1 · 400 kg | **`PartiallyReceived`** (`:509-514`) | 400 | 600 | 1000 / 400 |
| Arrival 2 · 400 kg | **`PartiallyReceived`** | 800 | 200 | 1000 / 800 |
| Arrival 3 · 200 kg | **`Closed`** (`:503-506`) | 1000 | 0 | 1000 / 1000 |

What closes it (`:499-506`): every **line** must satisfy
`bccomp(quantity_received, quantity, 4) >= 0`. Closure is driven by lines
only — **delivery schedules play no part** (`allocateSchedules` `:264-320`
moves window figures but never touches PO status). Once `Closed` the PO fails
the receive guard (`:99-101`) and no fourth arrival is possible.

**"Auto-close when full" is CONFIRMED** — no manual step, no approval; the
third GRN closes the order as a side effect of posting.

Two caveats for the owner:
- **The transition is untested.** A repo-wide grep for
  `PurchaseOrderStatus::Closed` returns exactly one hit — the write itself.
  No test asserts a PO ever reaches `Closed`.
- **Status only ever moves forward.** Nothing un-receives.

### A second hard stop inside the multi-delivery flow

The clean 400/400/200 case works **only because the three windows sum exactly
to the line.** When they do not, the screen cannot post the arrival at all.

The payload builder always includes the allocations key
(`GoodsReceiptsPage.tsx:392-397`), and that array is **empty** in two
reachable situations:

1. **The PO line has no delivery windows at all** — `proposeAllocations`
   returns `undefined` when `schedules.length === 0` (`:30-31`). Schedules are
   optional when raising a PO (`StorePurchaseOrderRequest.php:30`,
   `'sometimes'`), so a tonnage PO recorded without a schedule lands here.
2. **Windows are fully allocated but the line still has quantity remaining** —
   every `take` computes to 0 and the `> 0` filter drops every row. This is
   legal to create: the service rejects schedules promising *more* than the
   line (`PurchaseOrderService.php:95-99`), but under-promising passes.

An empty array is then rejected by `StoreGoodsReceiptRequest.php:42` —
`['sometimes', 'array', 'min:1']` — with HTTP 422. Verified empirically
against the framework's validator: an empty array satisfies `sometimes` (the
key is present) and fails `min:1`.

This contradicts the service's own documented intent
(`GoodsReceiptService.php:258-262`): *"A remainder beyond every schedule is
allowed … and simply carries no allocation row."* **That fallback is
unreachable from the UI** — the request is refused one layer earlier, and no
test covers it.

**Net: the 1-tonne PO with 400/400/200 windows receives cleanly and
auto-closes. The same PO recorded WITHOUT delivery windows cannot be received
from the screen at all.**

## 3. Closed short — 950 of 1000, nothing more coming

**There is no way to close, cancel or complete that PO. It sits
`PartiallyReceived` permanently.**

| Path | Status |
|---|---|
| Service method (close/cancel/complete) | **Does not exist** — `PurchaseOrderService.php:14-126` has only `paginate`, `upcoming`, `openCount`, `create`, `send` |
| Controller action | **Does not exist** — `PurchaseOrderController.php:13-37` has `index`, `store`, `send` |
| Route | **Does not exist** — `routes/api.php:191-192`, `->only(['index','store'])` + `send` |
| UI button | **Does not exist** — row actions are View and (draft only) Send |

The audit's earlier claim about `Cancelled` **holds, verified
independently**: it is declared (`PurchaseOrderStatus.php:11`) and never
written by any service, controller, seeder, command, test or migration. The
only status writes are `Draft`, `Sent`, `PartiallyReceived`, `Closed`.

**What the dead PO costs, indefinitely.** `PartiallyReceived` is an *open*
status everywhere: it stays in `upcoming()` (dashboard "stock coming in"), in
`openCount()` (inflating the open-PO tile forever), and in the GRN form's
`receivableOrders` — permanently offering an order that will never receive
anything. There is no workaround: over-receipt is hard-blocked with no
tolerance, so a phantom 50 kg cannot force the auto-close. **The only way to
close a short-shipped PO today is a direct database update.**

## 4. `tally_order_no` and `source` — and the two-books question

Both columns come from `2026_08_02_200001_add_po_schedules_and_arrival_flow.php:22-30`:
`source` is `string(10)` **NOT NULL default `'erp'`** (`:27`);
`tally_order_no` is `string(64)` nullable (`:28`).

**Writes:** `PurchaseOrderService::create()` `:68-69` is the **only** writer
in the codebase. Validation is `StorePurchaseOrderRequest.php:28-29`
(`in:erp,tally`, `required_if:source,tally`). UI entry is the "Mirrored from
Tally" switch (`PurchaseOrdersPage.tsx:293-313`); an unchecked switch sends
neither key, so the row falls to the DB default. No seeder and no command
writes either column, and **there is no PO update path at all**, so neither
can ever be corrected through the API once written.

**Reads:** the API resource; `source === 'tally'` making the order born
`Sent`; the Source column tag; and `tally_order_no` into the Receipt Note
queue payload (`TallySyncService.php:137`). `PurchaseOrder::isTallyMirror()`
has **no production caller** — its only call site is a test assertion.

**Does `tally_order_no` reach any voucher? No.** The agent's
`ReceiptNotePayload` interface (`receiptNote.ts:4-14`) does not declare it,
`buildReceiptNoteXml` (`:24-42`) does not emit it, and a repo-wide grep for
`tally_order_no` returns **zero hits anywhere under `tally-sync-agent/`**. It
is stored, echoed to the screen, placed in the queue payload, and **dropped
at the XML boundary** — so the arrival still cannot clear the right
outstanding order allocation in Tally.

### Were POs ever meant to arrive FROM Tally? **No.**

**There is no inbound path that creates a `PurchaseOrder` from Tally data.**

- `SyncMastersRequest.php:21-53` accepts exactly `company`, `item_groups`,
  `godowns`, `ledger_groups`, `ledgers`, `items`. **No purchase-order
  section.**
- `MasterSyncService.php` — zero occurrences of "purchase" or "order".
- `tally-sync-agent/src/tally/masters.ts` exports readers for item groups,
  godowns, ledger groups, ledgers, items and companies. **No order reader.**
- A grep for "purchase"/"order" across `tally-sync-agent/src/` returns **one**
  hit: a prose comment.
- `backend/app/Modules/TallySync/` contains **zero** references to
  `PurchaseOrder`.
- The agent's charter states the rule: *"agent-initiated and
  one-directional … Never build a path where the cloud reaches into Tally."*

**What the code assumes today:** the PO is raised **in Tally by a human**,
then **re-typed into the ERP by a human** — vendor, item, quantity, price,
every delivery window, plus the order number as free text with `max:64` as
its only validation.

**So the two-books risk is not hypothetical — it is the current design.**
Every mirror row is an unreconciled manual copy. Nothing imports it, nothing
verifies it against Tally, nothing detects divergence afterwards, and because
no PO update path exists, nothing can correct it in place when the Tally
order is amended. The screen says *"corrected in Tally, never edited here"*,
which in practice means the ERP copy silently goes stale and the only remedy
is a second PO row.

This is the evidence behind DEC-20260812-002 (purchase orders raised in the
ERP from now on): the mirror was never a sync, and one book is the fix.
