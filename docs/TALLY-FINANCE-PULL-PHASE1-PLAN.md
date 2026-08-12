# Tally Finance Pull — Phase 1 build plan (customer outstanding)

**STATUS: PLAN FOR REVIEW. Nothing here is built. No Tally contact was made in
producing it.** Prepared 2026-08-12 against `main`, the unmerged agent branch
`agent/v0.3.5-stock-journal-builder`, and PR #155's design at `bb9509f`.

Authority: the owner decided on 2026-08-12 that customer outstanding must be
visible in the ERP, which un-pauses PR #155's design. Customer NAMES came free
from ledgers already held here; PENDING MONEY can only come from Tally.

---

## 0 · Three collisions with the repo, surfaced first

### 0.1 A "read-only Finance view" would ship invisible

`DEC-20260812-001` (recorded 2026-08-12, in force) says: *"HRMS and Payroll are
adopted and visible in the ERP; **CRM and Finance stay hidden**."* `finance` is
absent from `frontend/src/lib/adoptedModules.ts:43-68`, and
`AppLayout.tsx:154-161` gates the Finance nav subtree on that Set. A screen
under `/finance/*` would be built, routed, permissioned — and unreachable.

**PROPOSAL:** surface the balances under **`tally-sync`**, which is adopted and
already has a nav subtree (`AppLayout.tsx:229-238`). This is the honest home,
not a workaround: the figure is a *Tally artifact with a timestamp*, not ERP
accounting — exactly as the stock snapshots already live under
`/api/v1/tally-sync/stock-snapshots` (`routes/api.php:281-283`). Adding
`'finance'` to ADOPTED_MODULES would relitigate DEC-20260812-001 by side
effect. **Whether Finance is adopted is an owner question, not part of this PR.**

### 0.2 The ERP already has a rival "outstanding" — never merge them

`backend/app/Modules/Finance/Services/AccountsReceivableService.php` exists.
The design's non-goal stands: *"the ERP never computes its own 'outstanding'
to rival Tally's."* The Tally figure is displayed with provenance and is never
summed with, reconciled against, or fallen back to that service's output.
**A screen showing two outstandings is worse than a screen showing none.**

### 0.3 "Reuse the 0.3.1/0.3.2 machinery UNCHANGED" is not literally achievable

The existing machinery is stock-item-shaped end to end — `<TYPE>StockItem</TYPE>`
(`stockSummary.ts:178`), StockItem fetch fields (`:130-134`), a plan built from
`exportItems` (`stockSummarySync.ts:200`), dedupe keyed on `item_guid`
(`:464-492`), a poison list keyed by item GUID (`stockReadState.ts:22-46`). A
ledger balance read needs `<TYPE>Ledger</TYPE>` and CHILDOF over ledger groups.

- **Reused byte-for-byte:** `tally/gate.ts` (28 lines, dependency-free by
  design). The single-Tally-gate invariant is inherited literally.
- **Reused as a contract, via a parallel copy:** plan → per-scope probe →
  bounded execute → per-item fallback → on-disk blacklist → honest coverage.

**The property to state in the PR, because it is true where "unchanged" is
not:** *no file on the stock-read path is edited by this work.*
`stockSummarySync.ts`, `tally/stockSummary.ts`, `stockReadState.ts` and
`tray.ts`'s removed-trigger comment are untouched — so 0.3.3/0.3.4 are not
weakened, they are not touched at all.

---

## 1 · Phase 1 scope

**IN** — one accountant-pressed run reads, for customer ledgers only: ledger
name + GUID, its ledger group, closing balance as at a date, and each customer
GROUP's own parent. Scope = Sundry Debtors + the regional customer groups by an
**explicit allow-list of group names**, never a tree walk — the same rule
`ImportCustomersFromLedgers.php:22-35` already enforces for the same
population.

Two read-only surfaces, both stamped with **two** timestamps — `as_of` (the
closing date asked of Tally) and `collected_at` (when the read happened):
a **Tally Balances** screen under Tally Sync, and an **Outstanding (Tally)**
column on Sales → Customers.

**OUT, explicitly**
- **Sundry Creditors (630 ledgers) and bank/cash groups.** The design draft put
  them in Phase 1; this plan removes them. The owner asked for customer
  outstanding — 630 creditor ledgers triple the request count of the very first
  live read for something nobody asked for.
- **Receipts, per-invoice ageing, bill-wise knock-off** — gated on Q30(b),
  which only the accountant can answer. Designing against an assumed voucher
  shape is how the first Receipt Note builder went wrong.
- Any write to Tally. Any ERP-computed outstanding. Any automatic pull.

---

## 2 · The safety shape, as engineering

### 2.1 One-shot job, at-most-once — the deliberate INVERSE of the voucher path

New tables `tally_finance_snapshots` (+ a **child** `..._lines` table, diverging
from the stock snapshot's JSON-lines precedent *with a stated reason*: finance
lines ARE queried one at a time — "the balance for customer X" — so they need an
index on `matched_customer_id`).

`TallySyncService::pending()` reads rows *before* stamping `delivered_at`
(`:808-821`) and that ordering is load-bearing and test-covered: an unacked
voucher must keep reappearing, because a lost ack must not lose a posting.

**A read job needs the opposite guarantee. Posting is the risk on that path;
reading is the risk on this one.** So `handOut()` does a conditional claim in a
transaction — `WHERE status='queued' AND collected_at IS NULL` — and a job
handed out but never reported back is **failed, never re-offered**. The
accountant presses again; the server never decides to read Tally twice. *This
divergence must be stated in the PR or a reviewer will read it as a mistake.*

**The cloud poll is not a Tally read — argue this explicitly.** The agent learns
of the job on its existing ~90s cloud poll (an HTTPS GET to the ERP). What
0.3.4 removed was the automatic **masters loop that read Tally**
(`main.ts:119-125`). The invariant preserved is *the agent initiates no Tally
request a person did not ask for*. A poll finding nothing queued touches Tally
zero times.

**Button permission.** There is no "accountant permission" — `PermissionService`
mints only `<module>.view`/`.manage`. **PROPOSAL:** `tally-sync.manage` **plus**
`hasAnyRole(['Accounts','Administrator'])`, which is how "the accountant" is
already expressed (`ShiftProductionEntryController.php:113`).

### 2.2 Quiet window on FACTORY wall clock

New `FinancePullWindow` service; comparison copies `ShiftVoucherReleaseGate.php:130`
exactly, localising through `config('tally-sync.factory_timezone')`. That file
records what forgetting costs: *"a bare parse would hold every voucher ~5.5h
past its real shift end."*

Enforced **twice** — at `request()` and at `handOut()` — because a job created
at 18:29 for an 18:30 window must not be collectable at 09:00 next morning.

**The window's value is unknown and must not be guessed.** It is **Q36**, open
on main. Ship with the env unset and the feature refusing to run until it is
set. A default window would be an agent choosing a factory fact.

### 2.3 Kill switch; cancellable until collected

`tally-sync.finance_pull_enabled`, env, **default false**, checked at
`request()` AND `handOut()` so flipping it off strands an already-queued job.
Cancel flips `queued → cancelled` under the same `collected_at IS NULL`
condition; after collection the button is gone and says why — *a cancel that
pretends to stop a request in flight is a lie on a screen*. A pre-flight
re-confirm runs immediately before the agent's FIRST Tally request.

### 2.4 Probe-before-heavy, chunked

New agent files mirroring the existing two one-for-one (`ledgerBalances.ts` ←
`tally/stockSummary.ts`; `financeSync.ts` ← `stockSummarySync.ts`;
`financeReadState.ts` ← `stockReadState.ts`, with a **separate electron-store
name** so no file on the stock path is edited).

- **The plan comes from two LIGHT requests, not a heavy one** —
  `exportLedgerGroups` + `exportLedgers`, the same request class the masters
  pull has served safely for months. The ERP's own `ledgers` table is carried in
  the job payload as a **cross-check**: divergence is reported, never silently
  resolved.
- **Probe compares against that plan.** A scope returning GUIDs outside it =
  Tally's group filter failing open = **ABORT with nothing heavy sent**. Kept
  even though a stranger *could* be a new ledger, because "probably a new
  ledger" is exactly the benefit of the doubt `stockSummary.ts:14-25` records
  three incidents against.
- **Chunk cap starts at the existing 40** and is config. At 40, Sundry Debtors
  (230) and every regional group over 40 fall to **per-ledger** mode. The first
  run logs per-chunk wall time; the cap moves on that measurement or not at all.
- **Cost, stated honestly:** ~630 ledgers, mostly per-item at 250ms plus request
  time ≈ **8–20 minutes** of quiet-window occupancy. Tell the accountant
  beforehand, not during.

### 2.5 Poison blacklist; single gate

Blacklist keyed by **ledger GUID**, own JSON file, same semantics: a timeout on
a single-ledger request → blacklist on disk *before anything else*, name it,
stop the run, skip it out loud, count it in coverage. *"An entry here is a
DIAGNOSIS, not a deletion."*

**Gate: `withTallyGate()` imported unchanged.** The process-wide invariant means
a finance read cannot race a voucher post — they share one queue. **The single
most valuable thing being reused, and it costs one import line.**

### 2.6 New version ≥ 0.3.6, without weakening 0.3.3/0.3.4

Branch from `agent/v0.3.5-stock-journal-builder`, bump to 0.3.6. The removed
reads stay removed — `tray.ts`'s and `main.ts`'s comment blocks are untouched.
The new tray item is **"Pull Customer Balances (job)"**, enabled only when the
cloud says a job is queued: not a "read Tally now" button, an "execute the job
the office queued" button.

---

## 3 · Group-parent measurement — a ladder, cheapest first

DEC-20260809-002 preserved the nuance deliberately: the owner confirmed the
**business** meaning, *"not Tally's group tree — whether they technically nest
under Sundry Debtors stays unverified until the first pull reads the group
parents."*

**The capture path has existed since the masters pull was built.**
`masters.ts:139-143` already fetches `'Name, Parent, GUID, AlterID'` for ledger
groups; `LedgerSyncService.php:20-23` hands them to `HierarchyUpsert::sync`;
`HierarchyUpsert.php:42,52` stores `tally_parent_name`.

**Step 1 — query the ERP. Zero Tally contact.**
```sql
SELECT name, tally_parent_name, parent_id FROM ledger_groups
WHERE name IN (<the customer group allow-list>);
```
Run on the **LIVE** instance, never dev fixtures. If `tally_parent_name` is
populated, **the nuance closes from data already in the ERP, before a single
Tally request.**

**Step 2 — if null:** an operator-triggered masters refresh (LIGHT only), then
re-run Step 1. **Step 3 — only if both fail:** fold it into the finance read's
plan step, which already fetches `Parent` — **zero additional Tally cost**.

Whatever it says is the measurement. If the regional groups do **not** nest
under Sundry Debtors, that changes **nothing** about Phase 1's scope — the
allow-list encodes the business meaning independently of the tree. What changes
is that a fact stops being unverified. The observation is relayed to the owner
and recorded via the decision skill; an agent does not promote a measurement to
a decision.

---

## 4 · Ledger → customer matching

### 4.1 `TL-{ledger_id}` is a good convenience key and a poor identity

True **within one database** (`LedgerSyncService.php:41` matches on
`tally_guid`; `Ledger` soft-deletes and the sync restores rather than
recreates, so ids are not recycled). It breaks the moment the database is not
that one: `ledgers.id` is a local autoincrement surrogate, so a rebuild,
reseed, restore or re-run makes `TL-42` silently name a different party.
**A wrong outstanding on a wrong customer is exactly the error nobody spots by
looking at a screen.**

**PROPOSAL:** resolve `TL-{n}` → `ledgers.id` → **`tally_guid`**, and attach the
balance to the GUID. That is what `LedgerSyncService.php:41` treats as identity
and what `StockSummaryPreviewService.php:19-22` states the rule for: *"A line
whose GUID matches nothing is reported as unmapped, never attached to the
nearest-looking product. Name similarity is how the wrong bottle gets an
opening balance."* The same sentence with "customer" for "bottle" is the whole
of §4. Resolution runs **once at ingest** and is stored.

### 4.2 Three named, counted buckets

- **(a) `matched`** — the only bucket that ever shows a figure against a
  customer.
- **(b) `customer_without_ledger_link`** — **not hypothetical; the import
  deliberately creates it**: name clashes skipped, code clashes skipped,
  soft-deleted `TL-` holders (code taken, customer gone), blank-named ledgers,
  and every customer made by hand.
- **(c) `ledger_without_customer`** — listed by name and amount, **excluded from
  any customer-facing total**.

### 4.3 The honest unmatched surface

Bucket (b) renders **"No Tally ledger linked"** — never a blank cell (a blank
reads as zero), never a guessed figure. Bucket (c) renders under a heading that
names it. Totals are **matched-only with the unmatched count beside them**: *a
total that quietly omits bucket (c) is a wrong number wearing a right number's
clothes.*

### 4.4 A disagreement with the design doc — resolve toward the code

The design doc (09-Aug) proposes resolving by *"exact ledger-name match"*.
**Main now contradicts that**: `ImportCustomersFromLedgers.php:191-198` refuses
to attach a same-name customer from another source, on the stated ground that
it *is not the same row*. Name-matching a balance would do silently at read
time exactly what the import refuses to do loudly at write time.

**Resolve in favour of the code** — which is what the design doc's own next
sentence asks for (*"Mapping exceptions are data … not code"*): the GUID
resolution above, plus a new `customer_tally_ledgers` link table populated by a
person, one row at a time, from buckets (b)/(c). **No name matching anywhere.**

---

## 5 · First PR — physically incapable of touching Tally

Not "policy says it won't" — `tray.ts:56-58` records what policy is worth: *"a
dangerous item beside them WILL eventually be clicked; policy is not a guard."*

**PR 1 contains zero changes under `tally-sync-agent/`**, so no built agent can
execute anything it queues, and `finance_pull_enabled` defaults **false**. There
is no code path from PR 1 to port 9000.

PR 1 = 3 migrations, 3 models, 3 services (`FinancePullWindow`,
`TallyFinanceSnapshotService`, `LedgerCustomerMatchService`), 1 controller,
1 request, 1 resource; config + routes edited; one read-only
`TallyBalancesPage.tsx` under Tally Sync; 3 test files (window boundaries across
UTC/IST, at-most-once handout + cancel + kill switch, the three buckets
including a soft-deleted `TL-` holder and a same-name non-match).

**PR 2** = agent v0.3.6, branched from `agent/v0.3.5-*`, zero edits to the three
stock-read files. **PR 3** = the Sales Customers column, after a real snapshot
exists so it is reviewed against real data.

**New questions continue from Q38.** Q36 already exists on main — cite it, do
not mint a duplicate.

### The order in which Tally is first touched

1. PR 1 merged, reviewed, deployed — **still zero Tally contact** (flag off).
2. §3 Step 1 run against live — the group-parent question may close with no
   Tally contact at all.
3. Q36 answered → the window env is set.
4. PR 2 merged, 0.3.6 released, the factory PC confirmed on 0.3.6.
5. **Owner and accountant present.** Flag on. Button pressed once, in the
   window. Tally is read for the first time.
6. First-run acceptance before any figure is believed.

---

## 6 · Risks and open questions

### What code can answer

The two most likely silent-wrong-numbers, both to be **measured, never
assumed**:

- **What sign convention does `Ledger.ClosingBalance` use?** The repo carries
  the scar: commit `05efa96` — *"Fix Stock Journal sides via ISDEEMEDPOSITIVE,
  not tag name (real root cause)"* — is exactly a wrong assumption about Tally's
  sign semantics reaching live. Store the raw string; show Dr/Cr only after one
  named customer is reconciled against Tally's own screen.
- **Does `SVFROMDATE = SVTODATE` give a correct ledger closing balance, or does
  it need the FY start?** Unknown. A ledger's closing balance is period-derived
  differently from stock. **The single most likely silent-wrong-number in the
  build.**

**First-run acceptance, before any figure is believed:** the accountant reads
**one** named customer's outstanding off Tally's own screen; the ERP must show
the same number, same sign, same as-of date. If not, nothing is published and
the sign/period questions reopen. A snapshot that has not passed this is
labelled unverified on screen.

### What only the accountant or owner can answer

1. **Q36 — the quiet half-hour.** Blocks the first real pull; does not block the
   build or review. No default will be invented.
2. **Q30(b) — bill-wise details on or off?** Blocks Phase 2 entirely.
3. **Which agent version is actually installed on the factory PC** — see the
   standing risk below.
4. **Are Sundry Debtors + the regional groups the complete customer
   population?** The allow-list must be read and approved by name.
5. **Is Finance adopted?** DEC-20260812-001 says no; §0.1 routes around it
   honestly. Changing that is a new owner decision.
6. **Q31 — the re-shared Day Book export.** Answers Phase 2's voucher shapes
   with **zero** live reads. Worth chasing regardless.
7. **Does the accountant accept 8–20 minutes of Tally occupancy** for the first
   run? Tell them the number beforehand.

### The standing risk that outranks the rest

**The whole safety inheritance is unmerged.** `main`'s agent is **0.2.0**;
0.3.1–0.3.5 exist only on unmerged branches. `TallySyncService.php:665-670` says
the Stock Journal label flips *"ONLY after the factory agent is confirmed
>= 0.3.5"*, implying it is not confirmed.

**If the live agent is 0.2.0, it still carries the Stock Summary tray trigger
that 0.3.3 removed — the most dangerous button in this system would be on the
factory desktop right now, and shipping 0.3.6 removes it.** Establish this
before the first pull. It is arguably the strongest argument for merging the
agent branches at all.

PR 1 does not depend on any of it. If the agent branches are never merged, PR 1
is inert and harmless — that is the point of the split.

---

*No Tally contact, no live reads, no workflow runs and no files modified in
producing this plan. Every element is a proposal for reviewer and owner.*
