# Tally → ERP Finance Pull — Discovery + Design (no build yet)

**Status: DESIGN FOR OWNER REVIEW — nothing here is built, and nothing in
this document's production contacts Tally.** Owner's goal: accounts/finance
data from Tally visible in the ERP — a read-only Finance view, and
per-customer outstanding on the CRM customer page. Owner's hard constraint,
verbatim: *"Tally hang aga koodathu — careful."* Tally remains the single
source of financial record; the ERP only ever displays snapshots that say
when they were taken.

---

## 1 · What the evidence already establishes (zero Tally contact)

### 1.1 The chart of accounts is fully known

Past masters pulls (agent ≤ 0.3.3 era) landed the complete ledger list in
the ERP database — **1,892 ledgers with their groups**, readable today from
the ERP alone (`/api/v1/tally-sync/settings`). What it shows:

- **Sundry Debtors: 230 ledgers**, plus ~400 more customer ledgers filed
  under REGIONAL groups (Tamil Nadu Region 105, Puducherry Region 91,
  Chennai 76, Villupuram 27, Kerala 17, Trichy 14, Madurai 10, Erode 10,
  Salem 9, Andhra 9, Coimbatore 8, Thanjavur 8, Bangalore 7, ...).
  **Owner-confirmed 09-Aug (DEC-20260809-002): the regional groups are
  ALL customers** — Phase 1 treats them as debtors alongside Sundry
  Debtors. The confirmation is of the BUSINESS meaning only: whether the
  groups technically nest under Sundry Debtors in Tally's group tree
  stays unverified until the first pull reads group parents, so Phase 1's
  pull fetches each group's parent and VERIFIES rather than assumes.
- **Sundry Creditors: 630 ledgers** — the supplier book Q28's
  accounts-payable question would draw on.
- **Banking is real and busy**: 8 bank accounts + 5 OD/CC lines across
  Axis, ICICI ×2, IDFC, Indian Bank, IOB, SBI, South Indian Bank — plus
  per-bank interest groups and loan groups (SIDBI, Ugro, HDFC vehicle).
  Receipts/Payments/Contra vouchers are certain to exist in volume.
- **Compliance is run in Tally**: full GST set (Output/Input CGST/SGST/
  IGST, RCM payable), TDS payable under 192/194C/194I(a)/194I(b)/194J/
  194Q, TCS on scrap 206C — plus legacy VAT/Excise ledgers (long history).
- **Sales are booked directly in Tally — owner-confirmed 09-Aug
  (DEC-20260809-003)**: 18 Sales-Accounts ledgers split by category and
  tax ("Sale of PET Bottles – Local (18%)", interstate, caps, jars,
  scrap – PET lumps & runners, mould development charges), with Sales
  Return ledgers. The ERP's own Sales module is demo-scale; ALL real
  invoicing lives in Tally, and **e-invoicing (IRN/QR) is not in use
  today**. The bill-wise half of Q30 stays open for the accountant.
- Expense structure: Direct/Indirect/Administrative/Selling, Employee
  Cost (+ Production), Finance Charges, provisions, staff advances.

### 1.2 What the lost export had, and what survives

`Transactions.xml` (30-Jul full export, read 05-Aug, UTF-16) held **38
Stock Journals** — every surviving citation from it is a production
finding (FC-02/03/04, resin/masterbatch decisions, scrap booked inward in
31/38 at Rs.17–32/kg). **The file is deleted** (manifest:
`tally-transactions-xml`, status *missing*); whether it also contained
Receipts/Payments/Contra/Sales vouchers was never recorded. So **voucher-
type usage counts cannot be stated from repo evidence** — that is exactly
what the re-share (Q31) answers with zero live reads.

### 1.3 What a receipt/outstanding looks like in their books — unknown

Per-customer outstanding needs to know whether the accountant keeps
**bill-wise details** (Tally's per-invoice `BILLALLOCATIONS`, enabling
aging/FIFO knock-off) or only running ledger balances. No surviving
evidence answers this. It is THE design fork for Phase 2 (Q30).

---

## 2 · The 08-Aug root cause, precisely

TallyPrime's XML gateway computes each request's report **synchronously on
the application's UI thread**. The v0.2.0 one-shot Stock Summary asked one
request to value the closing position of the entire catalogue — minutes of
computation with the UI frozen; the operator, seeing Tally hang, force-
killed TallyPrime; Tally writes its company data continuously, and the
kill landed mid-write — that is the corruption. v0.3.0's group-chunking
still wedged it twice (first chunk unbounded); v0.3.1's canary passed on a
named group but the following 12-item ungrouped fetch was itself heavy.
The lesson the design must answer, mechanism-specific: **never issue a
synchronous request whose computation cost was not proven small
immediately beforehand** — because one oversized request freezes the UI,
a frozen UI invites the kill, and the kill corrupts the books. v0.3.2
encoded that as probe-every-scope + per-item fallback + on-disk poison
blacklist; this design reuses that machinery unchanged, and adds that
ledger-balance reads are intrinsically far cheaper than stock valuations —
but the rule is applied anyway, because "cheap" is an assumption and the
probe makes it a measurement.

---

## 3 · Phased design

### Phase 1 — ledger masters + closing balances → read-only screens

**Data pulled** (one accountant-pressed run): ledger name, parent group,
closing balance, as-of date — for Sundry Debtors, Sundry Creditors, the
regional customer groups, and bank/cash groups — plus each GROUP's own
parent, so DEC-20260809-002's nuance is closed by measurement: the first
pull verifies whether the regional groups nest under Sundry Debtors
instead of assuming it. Nothing else.

**Server side:**
- `tally_finance_snapshots`: one row per run (id, requested_by,
  requested_at, status: queued → collected → completed/failed/cancelled,
  as_of, error) and `tally_finance_snapshot_lines` (snapshot_id, ledger,
  group, closing_balance as decimal, is_dr).
- **One-shot job**: the Finance screen's "Pull balances from Tally"
  button (accountant permission) creates ONE queued snapshot row. The
  agent, on its normal 90-second cloud poll, is handed the job at most
  once (`collected_at` stamped on handout, exactly like voucher
  delivery). No cron, no schedule, nothing recurring — Phase 1 has NO
  automatic pulls, matching the factory's standing rule.
- **Quiet-window guard**: the server refuses to create or hand out a job
  outside the configured window (env, factory wall-clock via
  `tally-sync.factory_timezone` — never `now()` against a wall-clock
  string). Default window: after evening shift end. Override requires the
  owner-level permission, audited.
- **Kill switch**: a queued job is cancellable from the same screen until
  collected; a server-side `finance_pull_enabled` flag (env) refuses job
  creation entirely. The agent re-confirms the job is still wanted
  (one cheap cloud GET) immediately before its first Tally request.

**Agent side (v ≥ 0.3.6 — never by weakening 0.3.3/0.3.4):**
- The removed reads stay removed; this is a NEW, job-gated path — the
  agent still initiates nothing on its own.
- Reuses the 0.3.1/0.3.2 machinery as-is: the single Tally gate (one
  request in flight, ever), probe-before-heavy per scope (probe = a
  count/first-item fetch for the group; only a passing probe permits the
  chunk), chunked by ledger group with a hard per-chunk cap, per-ledger
  fallback for oversized groups (Sundry Creditors at 630 WILL chunk),
  the on-disk poison blacklist naming any ledger whose fetch times out,
  resume-where-it-stopped, single-flight, tray narration, and the
  0.3.2 abort rule (operator restarts Tally first, then re-runs; the run
  resumes, never restarts).
- No auto-retry anywhere: a failed run reports and stops; the accountant
  decides to press again.

**ERP screens (read-only):**
- Finance module: "Tally balances (as of <date> — pulled <when>)" —
  grouped totals (Debtors, Creditors, Banks/Cash) and the ledger list
  under each, searchable. Every screen carries the as-of stamp and the
  sentence that Tally is the source of record.
- CRM customer page: an "Outstanding (Tally)" figure resolved by exact
  ledger-name match (same normalised-name discipline as item matching —
  whitespace-folded, never fuzzy; an unmatched customer shows "no Tally
  ledger matched" rather than a guessed figure). Mapping exceptions are
  data (a customer↔ledger table), not code.

### Phase 2 — receipts / bills-outstanding detail (design only, gated)

Per-invoice outstanding with aging needs bill-wise details; receipts
listing needs Receipt voucher pulls. **Gated on Q30 (bill-wise on?) and
the Q31 re-shared export** showing real Receipt voucher shape. Same
safety shape, same one-shot job pattern, date-windowed requests (never
"all history"), probe first. Not specified further until discovery lands
— designing against an assumed voucher shape is how the first Receipt
Note builder went wrong.

### Non-goals (all phases)

- No writes to Tally's finance side, ever, from this feature.
- No automatic scheduling in Phase 1 — one press, one run.
- No ERP-side accounting truth: figures display with provenance; the ERP
  never computes its own "outstanding" to rival Tally's.

---

## 4 · Questions

**Question status (09-Aug):** Q29 RESOLVED (DEC-20260809-002 — regional
groups are all customers; group-tree nesting verified at first pull, not
assumed). Q30 PARTLY RESOLVED (DEC-20260809-003 — all sales direct in
Tally, no e-invoicing; the bill-wise half stays open and still gates
Phase 2). Q31 OPEN — the owner is mailing the accountant for the full
Day Book XML export (all voucher types) and for Tally's quietest
half-hour.

**For the owner to ask the accountant in person:**
1. Which vouchers do you enter in a normal day — Receipts, Payments,
   Contra, Journal, Sales, Purchase? Roughly how many of each?
2. When a customer pays, do you knock the receipt off against specific
   invoices (bill-wise) or just the running balance?
3. Which bank accounts are live today (8 + 5 OD exist as ledgers — how
   many actually move)?
4. Are the regional groups (Chennai, Puducherry, ...) all customers?
   Whose idea is the grouping — yours or inherited?
5. What would you actually want to SEE on an ERP screen — party balances,
   overdue lists, collection follow-ups? (Their answer shapes Phase 1's
   screen, not its safety.)
6. A quiet half-hour for the one-button pull: which time of day is Tally
   most idle?

---

*Prepared 09-Aug-2026 from repo evidence only (pulled ledger list, the
knowledge system, SITE-CHECKLIST crash history). No Tally contact was made
in producing this document. Build starts only after the owner reviews.*
