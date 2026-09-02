# Chapter 3: Sales and Dispatch

**Status:** Capture in progress — owner walkthrough 02-Sep-2026  
**Owner input captured:** 02-Sep-2026  
**Code and active decisions checked:** pending — research note under `research/` in progress  

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
- **REQUIRED (new in this walkthrough):** there is no customer-approval step
  anywhere in the flow. Dispatch approval is Internal Quality's, on a Sales
  Order line, once the full required quantity is held and the finished goods
  have passed batch Quality.
- **Verification pending:** the current pages and routes for each step, from
  the research note.
- **OPEN (to ask, one at a time):**
  1. Partial dispatch: may the Store dispatch part of a line before the full
     quantity is held and approved, or only the whole approved quantity?
  2. What Internal Quality checks at dispatch approval: a sign-off on the held
     cartons, or a second checklist?
  3. Sales Order amendment after a hold exists: quantity, date, cancellation.
  4. Customer master: who creates customers, the Tally ledger import, FC-06.
  5. An imported Tally invoice that matches no order: who resolves it, and how.
