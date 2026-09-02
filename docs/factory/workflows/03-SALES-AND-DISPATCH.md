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
## 2. The consolidated answers (02-Sep-2026, 23:05 IST)

The owner asked for every remaining Sales question in one list and answered
all thirteen in their own words. Recorded as DEC-20260902-043 to -052.

| # | Rule | Record |
|---|---|---|
| 1 | Expected date IS the promised date; renamed; no second date | -043 |
| 2 | Floor reads product, quantity, promised date, job reference, packing instructions; customer/PO only for a customer-specific label; never price, supplier, rate, unrelated stock | -044 |
| 3, 4 | Sales may move the date or reduce an undispatched line with a reason; reduction never below dispatched, releases excess holds, cancels only unstarted requests; increase is a new line; after any dispatch only close-short; every cancellation records person, date, reason | -045 |
| 5, 6 | A Tally invoice matches only a confirmed order; Accounts imports and resolves unmatched invoices, links or marks "No ERP order", never creates an order; Sales sees the match status | -046 |
| 7 | Carton scan is the final dispatch method; typed only for identified legacy stock with an authorised person and reason; no automatic product switch; count legacy stock before rollout | -047 |
| 8 | Store records why it cannot fulfil; sends only the shortfall; else back to Sales; nothing auto-raised | -048 |
| 9 | Dispatch append-only; a wrong dispatch is reversed by an audited reversal document; a customer return is a separate workflow through Quality | -049 |
| 10 | Tally ledger import is the customer source; a Sales-made customer is Pending until Accounts maps a ledger; GSTIN mandatory only for GST-registered; dry run first | -050 |
| 11 | A Sales role: sales read/write plus scoped stock-availability and production-status views; no broad inventory, production or finance | -051 |
| 12, 13 | CRM, enquiries, quotations out of scope and hidden; carton tare and resin grade deferred to the label build | -052 |

Approved as engineering fixes, no record needed: a dispatch always leaves the
finished-goods location its hold sits in; the dashboard's two identical sales
counts become one.

## 3. What the build must do for this chapter

Every item below is a GAP against a record now in force.

1. Rename expected date to Promised date everywhere (-043); floor job view
   limited to the five fields (-044).
2. Sales order amend: date change, reduction with hold release and unstarted
   request cancellation, increase as new line, close-short after dispatch,
   mandatory reason and actor on every cancellation (-045).
3. Tally invoice import as an Accounts action on a screen, an unmatched list
   with link / No-ERP-order actions, confirmed-only matching, match status
   shown to Sales and written on the order (-046, DEC-20260831-012).
4. Dispatch: scan required for carton-tracked stock; typed path gated to
   identified legacy stock with person and reason; legacy stock counted on
   live first (-047). Dispatch always from the hold's finished-goods location.
5. Store "cannot fulfil" reason, shortfall-only send, return-to-Sales (-048).
6. Dispatch-reversal document (-049).
7. Customer Pending state until Accounts maps a Tally ledger; GSTIN rule;
   live customer import dry run then write (-050).
8. Sales role definition command (-051).
9. Quality-pending finished goods state with server refusals on hold,
   approval and delivery; live count first (-042).
10. The production queue by promised date with sticky manual position and
    moved flag (DEC-20260902-031); finished-goods-only picker and server
    refusal (DEC-20260902-035).
11. Stale text: the Tally mirror statement on the Sales pages; superseded ids
    in code comments; one dashboard sales count.

## Status

Chapter 3 is complete. Q37, Q67, Q73 closed; Q44 partly; Q61 and Q72 carry
their RESOLVED markers. Deferred to Accounts: Q30(b), Q36, Q41. Deferred to
the label build: Q23, Q24.
