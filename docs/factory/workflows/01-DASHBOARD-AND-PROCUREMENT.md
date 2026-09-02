# Chapter 1: Dashboard and Procurement

**Status:** Verified working document  
**Owner input captured:** 02-Sep-2026  
**Code and active decisions checked:** 02-Sep-2026  

This chapter explains what each page is for, what the current application does, and
what must change. Repeated comments about the same page are combined here.

## 1. Dashboard

### Required workflow

Every login needs a dashboard for its own job. Admin and Owner need the complete
factory view, but Store, Procurement, Quality, Production, Accounts and Sales must
see their own pending actions first.

Example for Store:

1. Material requests raised and their status.
2. Requests waiting for Store issue.
3. Incoming material still held for Quality.
4. Finished goods held against sales orders.
5. Sales lines waiting for Store dispatch.

### Current application

The current Dashboard is one common office/factory page. It hides sections when the
user lacks a module permission, but it does not build a different action queue for
each role. It currently shows:

- production day, shift, machines and day-bin estimate;
- Plant Manager and Accounts production approval counts;
- Tally voucher queue figures;
- open sales demand and estimated standard run time;
- incoming purchase orders;
- low stock, open POs, requisitions awaiting approval, sales, quality and other
  module totals.

Evidence: `frontend/src/features/dashboard/pages/DashboardPage.tsx` and
`backend/app/Modules/Core/Services/DashboardService.php`.

### Result

**GAP:** Permission-based visibility exists, but role-based action dashboards do not.

The correct implementation is one Dashboard shell with role-aware sections. It does
not require separate unrelated dashboard pages. Each number must open the exact
filtered work queue it counted.

## 2. Vendor master and Tally review

### What these pages are for

- **Vendors:** the ERP configuration master used when raising a purchase order.
- **Tally review:** a controlled comparison between Tally ledger data and ERP vendor
  data. Tally proposes a new vendor or a field correction; Owner or Accounts decides
  what to accept.

The Tally review is already the second tab inside the Vendors page. It is not a
separate daily procurement stage.

### Current application

- The Vendor list contains every configured vendor and has no vendor classification
  such as resin, packaging, service, consumable or tooling.
- The visible State column shows only the two-digit code.
- State code `34` already maps to `Puducherry` and `33` maps to `Tamil Nadu` in
  `backend/app/Modules/Compliance/Services/GstStateCodes.php`, but the Vendor page
  does not display the name.
- Tally review can propose name, email, phone, GSTIN, state code and ledger name.
- Tally review deliberately refuses ambiguous GSTIN matches.
- Tally has very little supplier email and phone data. The ERP currently protects a
  manually entered value when Tally is blank.

### Required changes

1. Add a clear vendor classification and filters.
2. Show `34 — Puducherry`, `33 — Tamil Nadu`, and the correct label for every valid
   state code instead of showing only the number.
3. Keep Tally review inside Vendors and show only rows that need a decision.
4. Import missing contact data from the verified Excel source without overwriting a
   better ERP value with a blank value.
5. Do not create duplicate vendors from similar names or shared GSTINs.

### Resolved 02-Sep-2026

**DEC-20260902-026:** five classifications — Resin; Packaging; Consumables,
Spares and Tooling; Service; Other — one or more per vendor, set by a person;
the Tally ledger group only proposes. The default view shows the first three;
Service, Other and Unclassified sit behind an explicit filter. Classification
never blocks selecting a vendor.

## 3. Purchase requisition

### Required workflow

1. Requester selects the required date.
2. Requester adds one or more material lines and quantities.
3. The picker shows only materials allowed for procurement.
4. Search must work with normal fragments such as `PET`, a SKU, an item name, or a
   material group. The user must not guess spaces or word order.
5. A different authorised person approves or rejects the request.
6. An approved request opens a prefilled Purchase Order.

### Current application

- Required date, multiple lines, quantity, notes, approval/rejection, audit stamps,
  pagination, search and Raise PO handoff already exist.
- The item picker calls `listAllItems()` and offers every item, including finished
  goods.
- The backend also accepts any active item. This was deliberate because Q59(a),
  “Which item categories may a purchase use?”, is still open.
- The page places Approve and Reject on draft rows. The permission gate controls who
  may press them, but the workflow document does not yet name the required approver
  role or explicitly prevent self-approval.

### Result

- **VERIFIED as a decision:** DEC-20260902-023 — the pickers show Raw Material and
  Packing Material by default; consumables, spares or tooling and unclassified
  items sit behind a deliberate choice; finished goods never appear; every such
  purchase follows the full workflow.
- **VERIFIED as a decision:** DEC-20260902-025 — any procurement-write holder
  except the requester approves; self-approval refused; no Administrator bypass;
  rejection is an approver action; a requester withdraws their own.
- **GAP:** The current picker and backend enforce neither rule yet.

## 4. Purchase order and delivery schedule

### Required workflow

An approved requisition becomes a Purchase Order. Each line must show:

- ordered quantity;
- delivery dates and quantities;
- received quantity;
- remaining quantity;
- short, complete, overdue or excess status.

Multiple deliveries against one PO must be normal. Messages must be short and clear.

### Current application

- An approved requisition can already open the PO form with its lines prefilled.
- A PO line supports multiple delivery schedules.
- GRNs can allocate a partial arrival against those schedules.
- The server refuses receipt above the PO line’s remaining quantity.
- A Draft PO can be amended. A Sent PO cannot be amended; it can be short-closed or
  cancelled. This protects the order already sent to the vendor.

### Required handling

- **Short delivery:** receive the actual quantity and keep the balance open, or use
  a clear Short Close action when the remaining quantity will not arrive.
- **Excess delivery:** do not silently increase the old PO. The application must
  refuse the excess and direct the user to create the required new PO or authorised
  adjustment.
- **Overdue schedule:** show one clear alert and the remaining quantity.

### Open point

**OPEN:** What must be sent to Tally after a PO already sent to Tally is changed,
short-closed or cancelled? This remains Q48 and must not be guessed.

## 5. Goods receipt and incoming inspection

### Correct sequence

1. Store records the actual arrival against the PO as a GRN.
2. The GRN records the stock arrival and creates the lot/bag identity where the item
   supports it.
3. The arrived quantity is held from issue while it waits for Incoming Quality.
4. Quality records inspected, accepted and rejected quantities.
5. Accepted material becomes usable stock. Rejected material follows the recorded
   rejection path.
6. Labels can be printed or reprinted from Barcode and Labels.

This wording separates **on-hand stock** from **usable stock**. The active decision
records the GRN arrival immediately, but the material cannot be issued until Quality
releases it.

### Current application

- Multiple partial GRNs and schedule allocation already exist.
- Over-receipt is refused.
- Incoming Inspection selects a GRN line and records inspected, accepted and rejected
  quantities and date.
- Accepted bags leave `waiting_qc` and become issuable.
- Rejected quantity is recorded out; what Tally should receive for that rejection is
  still open.
- Bag barcodes are created at GRN time. The Barcode and Labels page reprints existing
  identities; it does not invent a new barcode later.

### Result

The main workflow is wired. The Incoming Quality checklist, whole-bag rejection,
the counted-material hold and handling units are recorded in Chapter 2 §5
(DEC-20260902-011 to -015).

**VERIFIED as a decision (02-Sep-2026):** DEC-20260902-034 — every purchase is
PO-first; the GRN screen never offers "receive without order"; a direct purchase
gets a short PO at the gate first. Q64 closed.

## 6. Supplier Bills — clear explanation

Supplier Bills records the supplier’s paper invoice after material arrives. Accounts
records:

- vendor and bill number;
- bill date;
- optional PO and GRN-line matches;
- item quantity, rate and printed amount;
- CGST, SGST, IGST, rounding and total;
- attached bill scan and accounting ledger selection.

The page does not receive stock and does not create a PO. It records the financial
document and matches it to the purchase and receipt trail. It currently does **not**
post a Purchase Invoice to Tally. Whether it should do that is open question Q68.

## 7. Find — clear explanation

Find is a read-only lookup page. A user can scan or type an item SKU, bag barcode,
lot, production batch, carton or store-issue reference and ask, “What is this
number?” It does not create, approve or move anything. It is useful for traceability,
but it is not a step in the procurement workflow diagram.

## 8. Pages named during the walkthrough

| Page | Purpose |
|---|---|
| Store Fulfilment | Holds finished goods against sales-order lines, releases or re-points the hold, and sends a shortage to Production. It does not physically issue stock. |
| Production Planning | Read-only completion estimate for open production demand. It must show the assumptions and gaps behind the date. |
| Warehouses | Configuration and historical location records, not a daily workflow page. The owner requires one operational Store; old warehouse references must remain readable and must not be deleted from history. |
| Stock Movements | Read-only append-only inventory ledger used for reconciliation. |
| Barcode and Labels | Prints or reprints existing bag/lot labels. It is not a second stock transaction. |

## 9. Implementation order from this chapter

1. Record the Raw Material + Packing Material purchase-eligibility decision.
2. Define the purchase-requisition approver and self-approval rule.
3. Add vendor classification and confirm which classifications are shown by default.
4. Add state names and verified Excel contact-data reconciliation.
5. Build role action queues on the Dashboard, starting with Store and Procurement.
6. Verify the Purchase Requisition picker and server enforce the recorded category
   rule with clear search and clear refusal messages.
7. Simplify PO delivery, remaining, short-close and excess-arrival alerts.

No live master data, Tally voucher, stock quantity or warehouse history is changed by
this document.
