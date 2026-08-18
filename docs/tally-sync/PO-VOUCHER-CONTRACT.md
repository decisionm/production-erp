# Purchase Order voucher — the structure contract (Phase 6, P6-03)

**Status: STAGED, FLAG OFF.** The ERP builds a Tally `Purchase Order` voucher
for an ERP-raised purchase order (DEC-20260812-002) and stages it in the sync
queue — but ONLY while `tally-sync.purchase_orders_enabled` is `true`, and it is
`false` by default. The first live write to the factory's real Tally is an
OWNER GATE (Q35(d)); it never happens unattended, and no test, seeder, command
or workflow flips the flag. Until the owner opens the gate, sending a PO
records `tally_staging: {state: 'disabled'}` on the order and enqueues nothing.

This document is **structure only**: the tag tree, cardinalities, value KINDS
and signs as measured on the factory's real exports, and what the builder emits
against that. **No value from the exports appears here** — not a rate, an
amount, a vendor, a GSTIN, an item name, a ledger name or a voucher number
(FC-06, Q38). Every example value in the code, the tests and the golden is
synthetic ("Vendor Alpha", "ITEM_A", rate 1.0000).

| Where | What |
|---|---|
| `backend/config/tally-sync.php` → `purchase_orders_enabled` | the flag (env `TALLY_SYNC_PURCHASE_ORDERS_ENABLED`, default `false`) |
| `backend/app/Modules/TallySync/Services/TallySyncService.php` → `enqueuePurchaseOrder()` | the cloud half: ONE `tally_sync_entries` row, nothing else; refuses with named reasons |
| `backend/app/Modules/TallySync/Providers/TallySyncEventServiceProvider.php` | the `PurchaseOrderSent` listener: flag off → `disabled`; on → enqueue or `refused`; never throws out of `send()` |
| `backend/tests/Feature/TallySync/PurchaseOrderTallyStagingTest.php` | the cloud proof (counts before/after; refusals; FC-06 gating; catalogue) |
| `tally-sync-agent/src/tally/voucherBuilders/purchaseOrder.ts` | the agent half: the XML, in the exports' order and signs |
| `tally-sync-agent/tests/purchaseOrder.test.js` + `tests/fixtures/purchase-order.golden.xml` | the agent proof (type, order, signs, cardinalities, refusals, escaping, byte-for-byte golden) |

## 1. Evidence — the raw exports live OUTSIDE the repo (Q38)

The structure below was measured on the owner's 12-Aug-2026 Day Book export
of the LIVE Tally (1-Apr-26 to 10-Aug-26): **107 `Purchase Order` vouchers**
(15 + 92), read locally with `iconv -f UTF-16LE`, printing nothing but tag
names, booleans, signs and shape patterns. The files are registered in
`docs/factory/sources/manifest.json` (status `external`) and are **never
committed, copied into the repo, or quoted with values**:

| Manifest id | File | sha256 |
|---|---|---|
| `tally-daybook-po-20260812` | `DayBook_1.xml` (15 vouchers) | `18aee2a886ec74cbd33711e63f6ccf8c5bba8f3790f2dfe127ad5ce8bdcc852d` |
| `tally-daybook-po-20260812-part2` | `DayBook_2.xml` (92 vouchers) | `7a411e5f43ddd162215e07c45f7e8a1d8fb885f04441284ec5052b256f631598` |
| `tally-po-pending-register-20260812` | `Purchase Orders.xml` — a FLAT pending register (`DORDATE / DORNAME / DORITEM / DORPNDGQTY / DORRATE / …` repeating, no `<VOUCHER>`), **not used by the builder** | `aa4cd8e084daa0d060648416e7ad940be7c308c6479f8e8ad3368628143f20d2` |

`Recepits.xml` in the same set is the accounting *Receipt* voucher (a false
friend for "Receipt Note") and is irrelevant here.

## 2. The voucher as Tally exports it — tag tree, cardinalities, kinds

Counts are over the 107 vouchers; "line" = one `ALLINVENTORYENTRIES.LIST`
(201 in all: 199 on the 105 live vouchers, 1 each on the 2 cancelled — those
two lines carry no allocation); "allocation" = one `BATCHALLOCATIONS.LIST` (232).

```
ENVELOPE › HEADER › TALLYREQUEST
         › BODY › IMPORTDATA › REQUESTDESC (REPORTNAME, STATICVARIABLES › SVCURRENTCOMPANY)
                             › REQUESTDATA › TALLYMESSAGE xmlns:UDF="TallyUDF"
  › VOUCHER  REMOTEID VCHKEY VCHTYPE="Purchase Order" ACTION="Create"|"Cancel" OBJVIEW="Invoice Voucher View"
      DATE                     yyyymmdd                        107/107
      PARTYGSTIN               15-char GSTIN string            105/107 (absent on the 2 cancelled)
      PLACEOFSUPPLY            a state NAME                    105/107
      VOUCHERTYPENAME          = "Purchase Order"              107/107
      PARTYNAME                = PARTYLEDGERNAME               105/107 (absent on the 2 cancelled, like PARTYGSTIN)
      PARTYLEDGERNAME          the vendor's ledger name        105/107 (absent on the 2 cancelled)
      VOUCHERNUMBER            Tally's own numbering           107/107
      REFERENCE                = VOUCHERNUMBER on 102/107      107/107
      BASICBASEPARTYNAME       present 105/107; = PARTYLEDGERNAME on 104/105
      BASICDUEDATEOFPYMT       free text (" N Days")           92/107
      PERSISTEDVIEW            = "Invoice Voucher View"        107/107
      EFFECTIVEDATE            yyyymmdd                        107/107
      ISCANCELLED              No 105 · Yes 2 (ACTION="Cancel" on those 2)
      USETRACKINGNUMBER        No                              107/107
      ISINVOICE                No                              107/107
      NARRATION                                                0/107 (the accountant types none)
      (no ISORDER, no ORDERTYPE, no DESTINATIONGODOWNNAME anywhere: order-ness is the TYPE + ISINVOICE=No)
      (~250 further tags per voucher — GUID, ALTERID, VCHSTATUS*, GST*/VAT* status flags — Tally's own bookkeeping)
      ALLINVENTORYENTRIES.LIST                                 1..8 per voucher (58 have 1, 25 have 2, 17 have 3, 7 have 4–8)
        STOCKITEMNAME          the Tally stock-item name
        ISDEEMEDPOSITIVE       Yes                             199/199
        RATE                   "<decimal>/<unit>"              199/199 (3 dp in this export)
        AMOUNT                 signed decimal, NEGATIVE        199/199 (= −rate×qty on 197; 3 dp)
        ACTUALQTY, BILLEDQTY   " <decimal> <unit>" (leading space; 3 or 4 dp) — 14 lines add " = <alt qty> <alt unit>" (dual-unit lines, Q40)
        BATCHALLOCATIONS.LIST                                  1 per line on 194; 5, 8, 9 on the 5 multi-due-date lines
          GODOWNNAME           the godown name                 232/232
          BATCHNAME            = "Primary Batch"               232/232
          INDENTNO
          ORDERNO              = the voucher's VOUCHERNUMBER   221/232 (per voucher: equal on every allocation on 99/107, distinct on every allocation on 5, mixed on 1, no allocations on the 2 cancelled — the ORDERNO is, in the usual case, the voucher number repeated per line, not an independent line-level order number; Q35(c))
          TRACKINGNUMBER       present, never == ORDERNO       232/232
          AMOUNT               NEGATIVE; = the line's when 1 allocation (194/194), summing to it when several (5/5)
          ACTUALQTY, BILLEDQTY same form as the line
          ORDERDUEDATE JD="<int>" P="<d-Mmm-yy>"               232/232 — the element TEXT repeats P (232/232)
        ACCOUNTINGALLOCATIONS.LIST                             exactly 1 per line (199/199)
          LEDGERNAME           the purchase ledger
          ISDEEMEDPOSITIVE     Yes                             199/199
          ISPARTYLEDGER        No · LEDGERFROMITEM No
          AMOUNT               NEGATIVE, = the line's           199/199
      LEDGERENTRIES.LIST                                       1–5 per voucher (2: 28 · 3: 28 · 4: 48 · 5: 1 · 1: 2 cancelled)
        the PARTY line          ISPARTYLEDGER Yes · ISDEEMEDPOSITIVE No · AMOUNT POSITIVE · LEDGERNAME == PARTYLEDGERNAME   105/105
        the tax line(s)         ISPARTYLEDGER No · ISDEEMEDPOSITIVE Yes · AMOUNT negative                                 105/107 have ≥1
        a rounding line         ISPARTYLEDGER No · ISDEEMEDPOSITIVE Yes · ROUNDTYPE/ROUNDLIMIT present · AMOUNT either sign
```

Ordering, as exported: `ALLINVENTORYENTRIES.LIST` precede `LEDGERENTRIES.LIST`
on 107/107; inside a line the order is `STOCKITEMNAME … ISDEEMEDPOSITIVE …
RATE › AMOUNT › ACTUALQTY › BILLEDQTY › BATCHALLOCATIONS.LIST × n ›
ACCOUNTINGALLOCATIONS.LIST`; inside an allocation `GODOWNNAME › BATCHNAME ›
INDENTNO › ORDERNO › TRACKINGNUMBER › … › AMOUNT › ACTUALQTY › BILLEDQTY ›
ORDERDUEDATE`; inside a ledger entry `LEDGERNAME › … › ISDEEMEDPOSITIVE › … ›
ISPARTYLEDGER › … › AMOUNT`.

### The sign rule (measured, emitted verbatim)

Tally's usual "debit negative, credit positive":

| Block | ISDEEMEDPOSITIVE | AMOUNT sign | Evidence |
|---|---|---|---|
| inventory line | Yes | negative (−rate × qty) | 199/199 |
| BATCHALLOCATIONS | — | negative, summing to the line | 232/232 |
| ACCOUNTINGALLOCATIONS (purchase ledger) | Yes | negative, = the line | 199/199 |
| party LEDGERENTRIES | No (ISPARTYLEDGER Yes) | positive = −(sum of every other amount) | 105/105 |
| tax / rounding LEDGERENTRIES | Yes | negative (tax); either (rounding) | not emitted |

The goods and the purchase ledger are the debit side, the vendor the credit
side; the voucher balances to the last digit. With no tax and no rounding
line the party amount is exactly the sum of the line amounts, and the builder
computes it as an exact decimal-string sum (never a float).

### ORDERDUEDATE — JD and P do NOT agree consistently

Every allocation carries `JD` (an integer), `P` (`d-Mmm-yy`) and the element
text (= `P`, 232/232). Measured: `JD == excelSerial(P) − 1` — i.e. **days
since 1899-12-31** — on **130 of 232 (56%)**, the modal rule. Of the **102
that do not**, **97 equal `excelSerial(the voucher's own DATE) − 1`** — the
ORDER date, not the due date (the second pattern) — and **5 are neither**;
13 JD values pair with more than one P. They are therefore **not a
formatting pair**, and the two patterns together read as Tally deriving `JD`
from a date it holds rather than from `P`. Builder rule (unchanged by the
second pattern): derive BOTH from the ONE due date the ERP holds — `JD =
excelSerial(due) − 1`, `P = d-Mmm-yy` (no zero padding, 3-letter Title-case
month, 2-digit year), text = `P`. **The first owner-gated live post is the
check** on this rule; if Tally re-derives JD from P (or from the voucher
date) either pattern in the exports is explained and the rule is harmless; if
it does not, the first live voucher's due dates are read back before a second
is sent.

## 3. What the builder emits — and why each omission is deliberate

`buildPurchaseOrderXml(payload, companyName)` emits, in the exports' own order:

```
<VOUCHER VCHTYPE="Purchase Order" ACTION="Create" OBJVIEW="Invoice Voucher View">
  DATE · PARTYGSTIN? · VOUCHERTYPENAME=Purchase Order · PARTYNAME · PARTYLEDGERNAME ·
  VOUCHERNUMBER · REFERENCE? · BASICBASEPARTYNAME · NARRATION · PERSISTEDVIEW=Invoice Voucher View ·
  EFFECTIVEDATE · ISCANCELLED=No · USETRACKINGNUMBER=No · ISINVOICE=No
  ALLINVENTORYENTRIES.LIST × line
    STOCKITEMNAME · ISDEEMEDPOSITIVE=Yes · RATE · AMOUNT(−) · ACTUALQTY · BILLEDQTY
    BATCHALLOCATIONS.LIST × schedule (or ONE for the whole line)
      GODOWNNAME · BATCHNAME=Primary Batch · ORDERNO=VOUCHERNUMBER · AMOUNT(−) · ACTUALQTY · BILLEDQTY ·
      ORDERDUEDATE JD P (text = P)?     ← only when the schedule has a due date
    ACCOUNTINGALLOCATIONS.LIST: LEDGERNAME=purchase ledger · ISDEEMEDPOSITIVE=Yes · AMOUNT(−)
  LEDGERENTRIES.LIST: LEDGERNAME=party ledger · ISDEEMEDPOSITIVE=No · ISPARTYLEDGER=Yes · AMOUNT(+ sum of lines)
```

PARTYNAME and BASICBASEPARTYNAME repeat PARTYLEDGERNAME (105/105 and 104/105
of the live vouchers; the 2 cancelled name no party at all). NARRATION is the
agent's convention on every builder (no real order carries one) and is empty
when the PO has no notes.

**Why it is safe to post — by TYPE, not by omission (DEC-20260812-002).** A
Purchase Order is an ORDER voucher: Tally posts it to neither accounts nor
stock, and it does that because of the voucher TYPE (VCHTYPE / VOUCHERTYPENAME
`Purchase Order`, ISINVOICE No) — not because any ledger block is left out.
Every real order carries the party ledger and the purchase ledger per line and
still moves nothing, so the builder carries them too. The agent test asserts
the type; the cloud test counts `stock_movements`, `stock_balances`,
`material_lots`, the journal tables and the GRN/PO tables before and after an
enqueue and finds them unchanged — one `tally_sync_entries` row is the only
write.

| Not emitted | Present in the exports | Why it is deliberate |
|---|---|---|
| tax ledger line(s) (CGST/SGST or IGST) | 105/107 | the ERP computes no GST on a purchase order, and the ledger NAMES are the owner's/accountant's to give — **Q35(e)**, **Q39** (the rate is not a property of the item or the vendor; the local/interstate ledger split is measured but the rate rule is not). Never invented. |
| rounding line | most vouchers | no computation, no name (Q39 asks whether it is always the same ledger and whether Tally or a person produces it). |
| `TRACKINGNUMBER` | 232/232, never == ORDERNO | `USETRACKINGNUMBER` is No on all 107 — nothing to track by; the ERP's GRN keeps its own `receipt_key`. |
| `INDENTNO` | 232/232 | no indent in the ERP. |
| `PLACEOFSUPPLY`, `GSTREGISTRATIONTYPE`, `CMPGSTIN`, `GST*` / `VAT*` flags | 105/107 | the state NAME and registration kinds Tally wants are not values the ERP holds as Tally values; GST computation on POs is out of scope. |
| `BASICDUEDATEOFPYMT` | 92/107 | free text (" N Days"), not a date the ERP knows. |
| `GUID`, `ALTERID`, `MASTERID`, `VCHKEY`, `REMOTEID`, `VCHSTATUS*`, audit lists | 107/107 | Tally's own bookkeeping — an import must not supply them. |
| unit suffix on RATE / ACTUALQTY / BILLEDQTY | 199/199 (`/unit`, ` qty unit`) | emitted ONLY when the payload names `unit`; the cloud sends none today (**Q40** — `Item.uom` is Tally's base unit at the last pull but is user-editable and carries no provenance, so the ERP cannot vouch it IS the Tally symbol; and dual-unit lines are unresolved). Bare decimals are the form the live Stock Journals already post with. A unit is never mapped from an ERP UOM by guess. |
| the dual-unit " = alt qty alt unit" form | 14/199 | Q40 — the ERP's line holds one quantity. |
| `ACTION="Cancel"` / `ISCANCELLED=Yes` | 2/107 | the ERP never rewrites Tally's book; a cancelled (or short-closed) ERP order is cancelled/closed in the ERP (Procurement's `cancel` / `close` actions) and — because staging happens once, on send — was either never staged, or its staged queue row is dealt with by the ERP itself: **still uncollected** (Pending, never handed to the agent) → the ERP dismisses that row and records `tally_staging.state = 'dismissed'` (`cancelled_before_delivery` / `closed_before_delivery`) — that is the ERP's own queue, not Tally's book; **already collected / synced / failed** → the row is left exactly as it is and `tally_staging.after = 'cancelled_after_delivery'` / `'closed_after_delivery'` is recorded on the order — the Tally side is the owner's (Q48). Alter/Cancel of a posted PO would be a NEW category of Tally write and is an owner question, not built. |

### Names are never invented (DEC-20260812-002 (iii))

| Name | Source | Missing → cloud refusal reason (recorded on the PO, `tally_staging.state = 'refused'`) |
|---|---|---|
| party ledger | `vendors.tally_ledger_name` — typed by Accounts, never populated from Tally (no Tally read) | `party_unmapped` |
| purchase ledger | `TallyLedgerRole::Purchase` via `TallyLedgerMappingService` (Settings → Ledger Mappings) — ONE role for now, **not** an env key, **no default** | `purchase_ledger_unmapped` |
| stock item | the item's exact name, only for a Tally-sourced item (`Item::isTallySourced()`, GUID recorded by the masters pull) that is not a local fixture | `item_unmapped` (item id + name) |
| godown | the ONE Tally-linked warehouse (`TallyGodownResolver::soleTallyGodownName()`); a PO names no receiving store until its GRN | `godown_unresolved` |
| lines | — | `no_lines` |
| flag | `tally-sync.purchase_orders_enabled` | `purchase_orders_disabled` (the listener records `state: 'disabled'` without calling the enqueue; the enqueue itself refuses too if called directly) |

All reasons are collected before refusing. The agent is the second lock: a
payload without a party ledger, a purchase ledger or a godown throws — there
is no default ledger, no default godown.

### VOUCHERNUMBER — Q35(c) is pending

Staged default: the ERP reference `PO-{id}` (the convention `GRN-{id}` already
uses live), sent as `voucher_number` with `voucher_number_source: 'erp'`; every
allocation's `ORDERNO` repeats it (221/232 real allocations do). `tally_order_no`
is a MIRROR's field and is never used for an ERP-raised order. Q35(c) — whose
number is authoritative — may change this BEFORE the first live write.

## 4. Payload (cloud → agent)

`enqueuePurchaseOrder()` writes, following the Receipt Note's key names:

```
voucher_type 'Purchase Order' · voucher_date (ISO; the agent writes yyyymmdd) · voucher_number "PO-{id}" ·
voucher_number_source 'erp' · party_ledger · party_gstin · purchase_ledger · godown · reference (= voucher_number) ·
narration (the PO's notes) · total_amount ·
lines[] { item, quantity, rate, amount, schedules[] { due_date (ISO), quantity, amount } }
```

`schedules[]` mirror `PurchaseOrderSchedule` (Tally's ORDERDUEDATE
allocations); a line with no schedule gets ONE allocation for the whole line
dated the order's `expected_date`, or — when there is no expected date —
`schedules: []`, and the agent emits one allocation for the whole line with
NO ORDERDUEDATE rather than a made-up date. No `unit` (Q40). Figures are plain
positive decimals; the signs are the agent's.

**The remainder rule — allocations always sum to the line, quantities AND
amounts.** One allocation per schedule at ITS OWN quantity × rate (4 dp, bc).
When the schedules promise LESS than the line (an under-scheduled line — the
ERP refuses schedules beyond the line at create/amend, so Σ ≤ line always),
the cloud appends ONE remainder allocation: `quantity = line − Σ schedule
quantities`, `amount = line amount − Σ schedule amounts` (so any rounding
lands on the remainder), `due_date = the order's expected_date` if there is
one, else `null` — and the agent emits no ORDERDUEDATE for a null due date,
exactly as it does for the unscheduled line. When the schedules cover the
line exactly there is no remainder row and the LAST schedule takes the amount
remainder (unchanged). The agent applies the same rule as a second lock: a
payload whose schedules under-cover the line without a remainder row is
topped up with the same undated remainder before it is built (`withRemainder`
in `purchaseOrder.ts`), never posted as allocations that do not add up. A
schedule's own quantity and amount are never inflated to absorb what it did
not promise. Proved by `PurchaseOrderTallyStagingTest` (100 @ 1 with [60] →
[60/60, 40/40]; 100 @ 2 with [30, 50] → [30/60, 50/100, 20/40 undated];
rounding; exact cover and unscheduled unchanged) and `purchaseOrder.test.js`.

**`purchase_orders.tally_staging` — what the order itself says** (written
only by `PurchaseOrderService::recordTallyStaging`; the TallySync listeners
call it): `{state, reasons: [{code, detail}], entry_id?, at, after?}` with
`state` one of `disabled` (flag off — the default), `refused` (named reasons,
no entry), `enqueued` (`entry_id`), `dismissed` (the staged entry was
withdrawn by the ERP because the order was cancelled / short-closed BEFORE the
agent collected it — reasons `cancelled_before_delivery` /
`closed_before_delivery`, `entry_id` kept). The optional `after` key
(`cancelled_after_delivery` / `closed_after_delivery`) is stamped when the
order was cancelled / closed AFTER the agent already collected the entry (or
it synced / failed): the entry is left untouched, and the Tally side is the
owner's question (Q48). With the flag off, cancel / close record nothing new
— the staging stays `disabled`.

**FC-06 on the entry.** `Purchase Order` is a supplier-party category
(`TallyTransactionCategory::partyIsSupplier`): `TallySyncEntryResource` nulls
`party` (with `party_withheld`), strips `party_ledger` / `party_gstin`, every
line's `rate` / `amount`, every schedule's `amount`, and `total_amount` for a
reader without `finance.view` / `finance.manage`; finance and the agent read
the payload whole. Refusal reasons carry an item id + name, a vendor id, a
role name — never a rate, an amount, a vendor name or a GSTIN.

## 5. The owner gates, in one place

| Gate | What it decides | Until answered |
|---|---|---|
| **Q35(d)** | does the accountant want an ORDER voucher in Tally at all | the flag stays `false`; the build exists but nothing is staged; the first live post is attended by the owner |
| **Q35(e)** | which ledgers a PO voucher must name — purchase ledger only, or tax (and rounding) too | no tax line is emitted; the purchase ledger is required (one role) |
| **Q35(c)** | whose PO number is authoritative | `PO-{id}` is the staged default |
| **Q39** | how the purchase ledger (and the GST rate) is chosen per line | ONE `TallyLedgerRole::Purchase` mapping; a per-rate ledger is a payload change, not a shape change |
| **Q40** | which unit governs a dual-unit line; whether `Item.uom` is the Tally symbol | no unit suffix; bare decimals |
| **Q38** | may the raw exports be committed | they stay outside the repo, sha256-pinned above |

## 6. What the DEPLOYMENT-RUNBOOK must say (integrator)

- New env key `TALLY_SYNC_PURCHASE_ORDERS_ENABLED` — optional, default `false`;
  **leave it unset/false on live** until the owner opens Q35(d). Flipping it is
  a deliberate, attended act; the first staged PO is read back in Tally before
  a second is sent.
- Purchase ledger: **not** an env key — `Settings → Ledger Mappings`, role
  `purchase`. Vendor ledgers: `vendors.tally_ledger_name` on the vendor form
  (Procurement, Phase 6). Nothing populates either from Tally.
- Agent **0.3.9** — built and tested on the branch, **NOT published**. An
  agent < 0.3.9 that meets a staged `Purchase Order` entry fails it loudly
  ("No XML builder for voucher type"), never posts it wrongly; with the flag
  off no such entry exists.
- Migrations (Procurement, Phase 6): `purchase_order_revisions`, the PO
  close/cancel/`tally_staging` columns, `vendors.tally_ledger_name` — additive,
  reversible. TallySync adds no migration.
- `TallyTransactionCategory::PurchaseOrder` moved from "lives in Tally /
  planned" to ERP-built (`source erp`, `erp_build built`); the summary's
  catalogue now shows an honest measured 0 for it on live, not a null.
