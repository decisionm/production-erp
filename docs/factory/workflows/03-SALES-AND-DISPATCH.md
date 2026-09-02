# Chapter 3: Sales and Dispatch

**Status:** Capture in progress — owner walkthrough 02-Sep-2026  
**Owner input captured:** 02-Sep-2026  
**Code and active decisions checked:** 02-Sep-2026 — see `research/2026-09-02-sales-and-dispatch-ground-truth.md`  

Same reading rule as the end-to-end document: VERIFIED, GAP, OPEN, REQUIRED.
Nothing in this file is a decision. A REQUIRED line becomes binding only when it
is recorded through the factory decision system in the owner's words.

## 1. The sale, end to end

### Owner input (02-Sep-2026)

Verbatim, the owner's line: "Sales enters order, Store checks stock, Quality
approves, Store dispatches". The owner then forwarded a Codex expansion as their
own statement of the flow:

1. Sales creates the Sales Order with the customer PO reference and promised date.
2. Store checks available finished-goods stock and places the hold against the
   Sales Order line.
3. If stock is short, only the shortfall goes to the Production Request queue.
   Production completes the requirement through the approved batch workflow.
4. When the full required quantity is held and the finished goods have passed
   batch Quality, Internal Quality records dispatch approval for the Sales Order
   line.
5. Store scans and dispatches only the approved cartons. Dispatch reduces ERP
   stock. Sales does not perform dispatch, and there is no customer-approval step.
6. The ERP does not send Sales Orders, Delivery Notes or Sales Invoices to Tally.
   Tally creates the accounting Sales Invoice, e-invoice and e-way bill. The ERP
   imports and matches the Tally invoice using the customer and customer PO
   reference.

### Standing decisions this walks through

| Step | Already decided |
|---|---|
| 1 | Customer PO reference is the matching key (DEC-20260831-012); promised date orders the production queue (DEC-20260902-031); a sales order sells active finished goods only (DEC-20260902-035). |
| 2 | First hold wins, the ERP never moves a hold (DEC-20260902-028); the Store re-points on its own judgement (DEC-20260902-029); no customer priority (DEC-20260902-030). |
| 3 | A queued request retires when the line is covered, delivered plus held (DEC-20260902-032); the queue sorts by promised date (DEC-20260902-031); the completion date is a labelled ceiling (DEC-20260902-033). |
| 4 | The finished-goods Quality stage and its checklist (DEC-20260902-006 to -010); no Store-acceptance stage (DEC-20260902-016). |
| 5 | The Store performs the dispatch, Sales does not (DEC-20260901-005); carton identity is permanent and the scan answers (DEC-20260807-013); the public scan and label never carry cost (DEC-20260810-001). |
| 6 | Inbound only: Tally originates the invoice, the ERP imports and matches by customer plus customer PO reference; an unmatched invoice is recorded for a person (DEC-20260831-012). |

### Result

- **VERIFIED as decisions:** every step above is carried by a record already in
  force; the walkthrough confirms them as one flow.
- **VERIFIED as a decision (not new):** there is no customer-approval step
  anywhere in the flow, and dispatch is gated on Internal Quality's recorded
  approval of the fully held line — DEC-20260831-006, 31-Aug-2026, which also
  forbids dispatching beyond the approved quantity and expressly leaves open
  whether Quality may withdraw an approval after goods have moved.
- **VERIFIED (code, 02-Sep-2026)** — detail and citations in the
  [research note](research/2026-09-02-sales-and-dispatch-ground-truth.md):
  the sales order carries the customer PO reference; the Store Fulfilment page
  holds against a line, refuses above free stock, releases and re-points with a
  reason, and sends the shortfall to Production; dispatch approval is recorded
  per line with who, when and quantity, only when the line is fully held; the
  Store raises the delivery on its own permission; a delivery is refused above
  the remaining approved quantity and an order becomes partially delivered;
  no outbound sales voucher path is switched on; the Tally invoice importer
  matches by customer plus customer PO reference and records an unmatched
  voucher for a person.
- **GAP (from the note):** the import runs only as an artisan command over an
  XML file; no screen lists unmatched invoices, and a match writes no status on
  the sales order although DEC-20260831-012 says it must.
- **GAP (from the note):** DEC-20260902-031 (queue by promised date) and
  DEC-20260902-035 (active finished goods only) are recorded, not built: the
  picker and server accept any item, inactive included.
- **GAP (from the note):** a delivery may be dispatched from any warehouse
  while holds live only in the finished-goods warehouse, so a dispatch can
  leave its hold standing.
- **GAP (from the note):** step 5 says the Store scans and dispatches only the
  approved cartons, but approval is a quantity, typed deliveries exist, and
  live has no cartons yet; nothing joins a hold or an approval to a batch.
- **Stale text (from the note):** the Tally mirror statement on the Sales
  Orders and Invoices pages still describes the reversed outbound direction;
  several code comments cite superseded decision ids; Q61 and Q72 lack their
  RESOLVED markers though DEC-20260831-012 resolves them.
- **VERIFIED as a decision:** DEC-20260902-041, partial dispatch. Quality
  approves only once the full line is held; the Store may then dispatch in
  parts under the same approval, never above the remaining approved quantity;
  after the first dispatch the approval cannot be withdrawn. A declared
  clarification of DEC-20260831-006. The code already refuses over-delivery
  and refuses withdrawal after dispatch; no build follows.
- **VERIFIED as a decision:** DEC-20260902-042, Quality-pending finished
  goods. Completed goods are visible as Quality Pending but cannot be held,
  approved or delivered until the batch's Quality check records OK and
  rejected; only the OK quantity becomes holdable; rejected goes to scrap.
  Enforced on the server for carton and typed paths. A read-only live count of
  Quality-pending goods precedes the rollout.
- **GAP:** no Quality-pending state exists for finished goods; completion
  receives straight into holdable stock; the build adds the state, the
  server refusals on hold, approval and delivery, and the dashboard figures.
- **OPEN (collected below as one list, per the owner):**
  2. "Promised date": the schema and form carry `expected_date`, whose meaning
     Q67 says was never recorded. Is it the date promised to the customer?
  3. Sales Order amendment after a hold exists: quantity, date, cancellation.
  4. Customer master: who creates customers, the Tally ledger import, FC-06.
  5. An imported Tally invoice that matches no order: who resolves it, and how.
  6. Dispatch by carton scan versus typed quantity, given live has no cartons.
