# Purchase & tax configuration — design for review

**Authority:** DEC-20260812-003. **Status: DESIGN. Nothing is built.**
Build follows the reviewer and the accountant's answers.

---

## The headline: two of the three parts already have a home, and one of them is wrong

The brief asked where this should live and said not to create a third place.
Reading the code first changed the answer, and corrected one premise.

| What the brief said | What the code says |
|---|---|
| "The company's own state — today nothing holds it" | **It is already held.** `gst_registrations` has `state_code` with an `is_primary` flag. |
| local-vs-interstate needs building | **Already implemented.** `GstComputationService:36` — `$isInterState = $customer->state_code !== $seller->state_code`, then IGST or a CGST/SGST half-split (`:60-69`). Exactly the rule measured in the Day Book. |
| GST rates need a home | **`gst_rates` exists** — `hsn_sac_code`, `rate_percent`, `is_active` — and `items.hsn_sac_code` already links to it. |

So the home is the **Compliance module**, which already owns the company's
registration and the rate table, and already computes the split. **No new
place, no new screen family.** What is needed is one structural change, one
correction, and two extensions.

---

## 1 · The structural change: rates must be effective-dated

`gst_rates` today (`2026_07_18_173135_create_gst_rates_table.php:11-19`):

```
hsn_sac_code   string UNIQUE      <-- one rate per HSN, forever
rate_percent   decimal(5,2)
is_active      boolean
```

**The UNIQUE constraint IS the defect the owner's evidence exposes.** One row
per HSN cannot express "5% until June, 18% after", which the Day Book proves
happened: 9 of 43 items appear at both rates in different months.

### The change

```
hsn_sac_code    string            (UNIQUE dropped)
effective_from  date              NEW
rate_percent    decimal(5,2)
notes           string nullable   NEW  — why this rate, from whom
UNIQUE (hsn_sac_code, effective_from)
```

Resolution becomes: **the row with the greatest `effective_from` that is ≤ the
document's date.** A new rate is a new ROW. The old row is never edited and
never deleted — the same immutability the decision records already use, for the
same reason: a rate that applied in April is a fact about April, and editing it
would silently restate history.

`is_active` is kept only for withdrawing a row entered in error. It must not be
repurposed as "the current one" — that is the constant this change removes.

## 2 · The correction: computing "fresh" is the bug, stated as a feature

`GstComputationService`'s own docblock (`:10-17`) says the breakdown is

> *"computed fresh on every call, never persisted, so rate changes … are
> reflected immediately rather than needing a re-save of historical invoices."*

That is written as a virtue and is, given effective-dated rates, precisely the
failure the owner warned of. Recomputing an April invoice today would apply
**today's** rate to it — restating history silently, on a screen that looks
authoritative.

`GstRate::query()->where('hsn_sac_code', …)->where('is_active', true)->first()`
(`:52`) must become a resolution **as at the document's date**. Recomputing
fresh stays correct — it just has to be fresh *as at the invoice's date*, not
as at today. The docblock must be rewritten to say so, or the next reader
restores the bug on purpose.

### Severity, established from the code rather than asserted

The reviewer asked which of two meanings "computed fresh" has. **It is the
worse one, and worse than either option offered.**

- **The invoice stores NO tax at all.** `invoices` holds `sales_order_id`,
  `customer_id`, `status`, `invoice_date`, `due_date`, `notes`, `created_by` —
  and nothing else. `invoice_lines` holds only quantity and `unit_price`. There
  is no tax column anywhere, so tax is **100% derived, every time**.
- **It is not a Tally restatement.** `TallySyncService` never calls the
  breakdown; the sales voucher emits no GST ledger entries at all today. So a
  historical invoice re-synced later would not carry today's rate into Tally,
  because it carries no tax into Tally in the first place.
- **It is a STATUTORY RETURN.** The breakdown's two consumers are a display
  endpoint (`GET /invoices/{invoice}/gst-breakdown`) and
  **`GstReportService::gstr1()`** — GSTR-1. And that service is explicitly
  **not period-filtered**: its own docblock says it *"covers all issued invoices
  to date"*. So every GSTR-1 recomputes every historical invoice at whatever
  rate is in `gst_rates` right now.

**The mechanism is live today, before any dating work.** `gst-rates` exposes an
`update` route, so editing one `rate_percent` silently changes the computed tax
of every invoice ever issued, and the next GSTR-1 with it.

#### But the IMPACT is not established, and must not be overstated

Whether that mechanism is a wrong statutory filing depends on something the code
cannot answer: **does anyone file from this report?** Both readings, plainly:

- **Latent defect (what the evidence currently supports).** Live holds
  **one** invoice — issued, 22-Jul-2026 — against Tally's 553 receipts. And the
  owner-confirmed record DEC-20260809-003, on main since PR #155 merged,
  states that **all real sales are invoiced directly in Tally** and the ERP
  Sales module is demo-scale. On that reading the ERP's GSTR-1 is a report over demo data that
  nobody submits, and nothing has been mis-filed.
- **Wrong statutory return.** If anything is ever filed from the ERP, the same
  mechanism produces a return computed at today's rates over historical
  invoices.

**The mechanism is not softened by this and the impact is not escalated past
it.** The question is Q41. Either way the defect must be closed **before** the
ERP could ever become the invoicing system — itself an open owner decision — so
steps 2 and 3 proceed regardless, and ahead of the purchase work because they
are small and unblock everything downstream.

## 3 · Extension: the purchase ledger matrix

Lives in **Tally Sync → Settings**, which already holds `tally_ledger_mappings`
(role → Tally ledger name). This is a widening of an existing fact in its
existing home, not a new place: the company state that selects between the
ledgers is one table away in Compliance, and the mapping itself is Tally-facing.

Today's single `TallyLedgerRole::Purchase` slot becomes a small matrix:

```
purchase_ledger[ local | interstate ][ rate_percent ]  ->  Tally ledger name
tax_ledger[ cgst | sgst | igst ]                       ->  Tally ledger name
rounding_ledger                                        ->  Tally ledger name
```

The four ledgers observed — `Local Purchase Taxable @ 18%`,
`Local Purchase Taxable @ 5%`, `Interstate Purchase Taxable`,
`Interstate Purchase Taxable 5%` — are what the factory happens to use today.
**They are not seeded.** The matrix ships empty and refuses to post rather than
guessing, exactly as the unmapped Sales ledger should have (today it silently
falls back to a hardcoded `'Sales Account'`, which is the failure mode this
avoids repeating).

`Rounding Off` appeared on 52 of 92 vouchers. Whether Tally produces it or the
accountant enters it is unknown from the data and is part of Q39.

## 4 · Extension: vendor defaults belong to the vendor

Payment terms are **not** global — the data shows 45 days on 51 of 92 orders,
60 on 17, and also 30 and 15. A single setting would be wrong for most vendors
the day it was saved.

So `vendors` gains `payment_terms_days` and a delivery-terms default, and the
PO form pre-fills from the vendor and remains editable per order. **One fact in
one place**: the term describes the vendor, so it lives on the vendor.

The per-line `ORDERNO` is neither configuration nor a vendor default — it is a
property of the order line and belongs on `purchase_order_lines`. Note it is
**distinct from the voucher number** in Tally, which is directly relevant to
Q35(c) — whose PO number is authoritative.

---

## What is deliberately NOT in this design

- **No seeded tax rate. None.** Leave days were seeded as a labelled starting
  point because a wrong leave figure is corrected at review. A wrong tax rate is
  money, and it would look exactly as authoritative as a right one. The
  container ships empty.
- **No inferred item→rate mapping.** It is tempting to read the Day Book and
  assign each item the rate it was last booked at. That would encode a guess as
  data, and the evidence specifically shows items moving between rates.
- **No default `Rounding Off` behaviour** until Q39 says who produces it.
- **No back-fill of `effective_from` for existing rows.** Any row already in
  `gst_rates` has an unknown start date; the migration must ask rather than
  invent one. (A live count is needed — see below.)

## Open, and not the agent's to answer

The accountant must confirm **which items are 5% and which are 18%**, and this
plan cannot proceed past the container without it. The two readings genuinely
disagree:

- Published Indian GST rates put paper and cardboard packaging at 5% since
  22-Sep-2025 while plastic packaging stays at 18% — which would make the split
  a **material distinction visible in every month**.
- The data shows 5% **only** in April, May and June, with July and August 18%
  only.
- The owner understands the recent change as "everything is 18% now".

Those cannot all be true. The configuration must therefore be able to hold
**both** rates with dates, and the accountant decides which item is which. That
is Q39.

## Sequencing, and one thing to check first

1. **Checked live already — and the answer reorders everything below.**

   - `gst_rates` holds **6 rows**. So the migration is not trivial: each needs
     an `effective_from` the accountant supplies. Six is small enough to ask
     about one at a time.
   - **Only 11 of 655 items carry an `hsn_sac_code`.** The sampled code is
     `3923` — plastics packaging, consistent with 18%.

   **That second figure is the real blocker, and it is larger than this
   design.** Rate resolution runs item → HSN → rate-as-at-date. With 644 items
   carrying no HSN, effective-dating the rate table correctly would still
   resolve nothing for 98% of the catalogue. `GstComputationService:47-50`
   already throws `missingHsnCode` rather than guessing, which is the right
   behaviour and means the failure is loud — but it means the container being
   ready does not make the ERP able to compute tax.

   **But it is NOT data entry — it is a one-line fetch change.** Checked, in
   the three parts the reviewer asked for:

   **(a) Does the masters pull fetch HSN? No.** `exportItems`
   (`tally-sync-agent/src/tally/masters.ts:154-161`) requests exactly
   `'Name, Parent, BaseUnits, GUID, AlterID'`. HSN is simply not asked for.

   **(b) Is it stored unused anywhere? No.** Zero `hsn` references in the agent,
   in `SyncMastersRequest`, or in the item sync path. It is not fetched, not
   transmitted, not dropped on the floor — it was never in the pipeline.

   **(c) How much would a mapping cover? It is already done — 98.3%.**
   **644 of 655 live items already carry `tally_stock_item_guid`**, and **all
   644 of them lack an HSN**. The 11 that have an HSN are precisely the 11 that
   are *not* Tally-mapped. There is no name-matching exercise to do and no
   fuzzy join to get wrong: the GUID join exists, on almost every row, and
   `LedgerSyncService`-style GUID matching is already the house pattern.

   So the code change is: add the HSN field to `exportItems`' fetch list, carry
   it in the masters payload, validate it, and store it on the item. One field,
   along a LIGHT request path that has run safely for months.

   **"One field" describes the diff and MISLEADS about the delivery.** It has an
   operational tail, and calling it one line invites someone to schedule it as
   an afternoon:

   - `exportItems` lives in the **agent**, not the server. `main` carries agent
     **0.2.0**; the newest branch is **0.3.5**. HSN would ship in **0.3.6** —
     the same bump the finance pull needs — which means a published release
     **installed on the factory PC**, not a deploy.
   - **A masters pull is a TALLY READ.** Since 0.3.4 nothing reads Tally
     automatically. It is triggered by a person from the tray
     (`Pull Masters from Tally` → `runMastersSync`), so somebody at the factory
     has to run it before a single HSN arrives.
   - It therefore sits under the **same discipline as any other read**: the
     quiet window, the single Tally gate, and the probe-before-heavy contract.
     Masters is a LIGHT request class and the cheapest read available — but it
     is still a read, and it is not exempt.

   Realistic shape: a small diff, a released agent version, an install, and one
   operator-triggered pull inside a quiet window.

   **Normalise the HSN format BEFORE any rate row exists** — `4819.10.10` and
   `48191010` are the same code, and once a rate row is keyed on one spelling
   the other becomes a second, silently unmatched code.

   **AND TALLY'S HSN CODES SETTLE Q39.** Parsed from the Day Book: 141 HSN tags,
   10 distinct, under `GSTHSNNAME`. They split exactly along the disputed line —
   `4819.10.10`, `48191010`, `4808` (paper and paperboard cartons and boxes)
   against `39232100`, `39233090`, `39076190`, `39012000` (plastics), plus
   `3204.17.90` (colorants). And the items carrying a **paper** HSN — 200 Ml
   Brute Tray, 500ML IFF Tray, and the 170/100/30/15ml Master Boxes — are the
   very ones measured earlier as appearing at **both** 5% and 18%.

   That is the published rate change made visible in the factory's own data:
   paper and cardboard packaging moving, plastics not. It does not settle Q39 by
   itself — the accountant still confirms, and the July cut-off still needs
   explaining — but the accountant now has the codes to answer from rather than
   a memory.

   One wrinkle to handle when storing: **the same HSN appears in two formats**,
   `4819.10.10` and `48191010`. Normalise on store, or two spellings of one code
   become two rate rows.
2. Migration: effective-date `gst_rates` (+ the resolution helper and tests).
3. `GstComputationService` resolves as-at a date; docblock corrected; tests
   pinning that an April document keeps April's rate.
4. Purchase ledger matrix in Tally Sync → Settings, shipping empty.
5. Vendor payment-term defaults.

Steps 2 and 3 are worth doing **regardless of Q39's answer**, because they fix a
live mispricing risk on Sales invoices that exists today. Step 4 waits on Q39.
