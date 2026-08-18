# Master prompt audit — what the ERP actually is, and what the prompt gets wrong

**Date:** 2026-08-16 · **Basis:** `main` @ `9a9cbe3`, deployed and live · factory
knowledge validated `sound` · 6 read-only discovery agents + direct inspection.

This document does three things, in order:

1. **Establishes what the application actually is** — measured, not assumed.
2. **Names the intentional engineering decisions** the master prompt would
   destroy if followed literally.
3. **Separates the prompt's genuine improvements** from its wrong premises, and
   rebuilds the plan on the evidence.

Nothing here is a factory decision. Where the prompt asks for something the
owner has not agreed to, it is recorded as a question, per `AGENTS.md`.

---

## 0 · Headline

The master prompt is written for a half-built application. **This application is
not half-built.** It is a live, deployed, heavily-tested manufacturing ERP with
157 migrations, 133 backend test files, ~80 frontend routes, 41 immutable owner
decision records and 531 commits in two months. It runs a real factory.

The prompt's engineering *discipline* is largely excellent and worth adopting.
Its *premises* about this system are substantially wrong, and several of its
instructions would delete deliberate, hard-won design — in one case rebuilding
a feature that was removed because it corrupted the live Tally company.

Read §3 before starting any work described in the prompt.

---

## 1 · What the ERP actually is (measured)

### 1.1 Scale, by module (backend PHP)

| Module | Files | Lines | Reality |
|---|---:|---:|---|
| Production | 227 | 24,865 | **The real product.** Everything else is periphery. |
| TallySync | 35 | 3,914 | Mature, 15 dedicated test files |
| Inventory | 57 | 3,375 | Real |
| Sales | 34 | 2,268 | 1,043 of it is one cost-insight read service |
| Quality | 52 | 1,854 | Real |
| Procurement | 33 | 1,640 | Real, but no Tally link |
| CRM | 29 | 1,029 | Real slice, **zero tests**, hidden from nav |
| Core | 22 | 961 | |
| HRMS | 31 | 979 | |
| Payroll | 28 | 893 | |
| Maintenance | 27 | 893 | |
| Finance | 19 | 681 | Smallest. **Hidden from nav.** |
| Compliance | 17 | 598 | Holds all GST computation |

Sales minus its cost-insight service is **1,225 lines** for the entire
order→delivery→invoice lifecycle. Production is twenty times that. The centre of
gravity of this product is the shift floor, not the sales desk.

### 1.2 The Tally architecture (this is the part the prompt most misreads)

```
ERP domain event  →  tally_sync_entries (JSON payload, NOT XML)
                            ↑ polled every 90s over HTTPS
                     on-prem Electron agent (v0.3.7) on the factory PC
                            ↓ POSTs text/xml to 127.0.0.1:9000
                          Tally
```

- **Laravel never contacts Tally.** `grep -rn "xml" backend/app/Modules/TallySync`
  returns **zero hits**. The cloud stores an XML-agnostic JSON payload; the Node
  agent builds every voucher's XML locally.
- **There is no scheduler.** No Laravel `withSchedule()`, no `schedule()` call,
  no `cron:` key in any of the 18 GitHub workflows, no systemd unit. The only
  cadence in the entire system is the agent's 90-second `setInterval`.
- **There is no "sync run".** No `sync_run_id`, no run table, no correlation id
  grouping a poll cycle. A cycle leaves no persistent trace beyond per-entry
  `delivered_at`.
- **No XML is archived.** No `xml` column exists in any migration. Tally's raw
  response is truncated to 2,000 chars into a local file log on the factory PC
  and **never leaves the premises**.
- **Statuses are four**, not fourteen: `pending`, `synced`, `failed`,
  `dismissed`.

Voucher types actually enqueued today, with the string that reaches the wire:

| ERP event | `tally_voucher_type` | Wire `VOUCHERTYPENAME` |
|---|---|---|
| Invoice issued | `Sales` | `Sales` (builder marked *unvalidated*, emits **no GST**) |
| Journal posted | `Journal` | `Journal` |
| GRN received | `Receipt Note` | `Receipt Note` |
| Delivery dispatched | `Delivery Note` | `Delivery Note` |
| Production approved (batch mode, **default**) | `Manufacturing Journal` | **`Stock Journal`** |
| Production approved (shift mode) | `Stock Journal` | `Stock Journal` |

**There is no Purchase Order voucher and no Purchase voucher.** Neither exists.

### 1.3 Idempotency is already solved — four layers deep

The prompt (§31) demands idempotency as though it were missing. It is one of the
most carefully built parts of the system:

1. **`delivered_at` handshake** — `pending()` reads rows, *then* stamps them;
   the ordering is load-bearing and documented.
2. **Agent-side pure decision function** (`postDecision.ts:53-83`) — four
   outcomes: `ack-only`, `post`, `report-only`, `refuse`. An agent with no local
   memory of a delivered voucher **refuses** rather than risking a double post.
3. **On-disk post journal** written *before* the ack attempt.
4. **Server-side terminal guards** — `isInTally()` blocks fail, retry, dismiss
   and release.

Retry is a one-shot re-authorisation that **regenerates** the payload from
source rather than replaying it. There are 13 dedicated tests
(`VoucherPostedOnceTest.php`) plus 11 agent-side tests including the four
regression cases from issue #168.

### 1.4 Master mapping — already identity-based where it counts

The prompt's §116 ("never guess a Tally identity at posting time") is a good
rule. It is **already followed** for the things that matter most:

- **Stock items** match on `items.tally_stock_item_guid` (unique) — never name.
- **Godowns** match on `warehouses.tally_guid`.
- **Finished-goods identity is frozen at completion** into
  `shift_production_entries.finished_item_id`, so a later configuration change
  cannot retro-alter a posted voucher.
- Ambiguity is met with **refusal**, not guessing: the scrap item is matched by
  SKU then exact name and never by pattern, precisely because "Pet Scrap",
  "PET Scrap - Amber" and "PET Scrap - Lumps" coexist.

**Where §116 is genuinely violated — and the prompt is right to flag it:**

- `customers` has **no** `tally_ledger_id` column. The link is
  `'party_ledger' => $invoice->customer->name` — **a name string resolved at
  voucher-build time**. If the ERP name drifts from the Tally ledger name, the
  voucher misses.
- Only the `sales` ledger role is ever read by a builder; the other seven roles
  in `TallyLedgerRole` are mapped but unused.

### 1.5 Infrastructure constraints

- Hostinger shared hosting. **No Redis.** Queue, cache and session are all the
  `database` driver.
- **A push to `main` deploys to the live factory automatically** (`deploy.yml`),
  with a `paths-ignore` for docs-only pushes.
- CI already runs: factory-knowledge validation, frontend typecheck + vitest +
  build, Pint, PHPUnit, and the agent's compile + contract tests.
- Cron availability on the host has **never been verified**. `TECHNICAL-DOCS.md`
  §8 explicitly leaves the hosting decision open and flags scheduler needs as a
  thing to *check*, not assume.

---

## 2 · The governance the prompt does not know about

This repo is not governed by engineering judgement alone. `AGENTS.md` and the
factory knowledge system impose rules that outrank any engineering plan:

- **The owner is the only authority for factory business decisions.** An agent
  proposes; the owner decides. A transcript, an inference, a test result or an
  agent's memory is **never** a decision.
- **41 immutable decision records** in `docs/factory/decisions/`, tool-written
  only (DEC-20260806-012), superseded rather than edited.
- **8 constitution entries (FC-01..FC-08)** — durable boundaries.
- **30 open owner questions.** Nothing in `PENDING-OWNER-QUESTIONS.md` is a fact.
- **Review chain:** Builder (with testing evidence) → Cursor review → Codex
  verification → owner. Work lands on a branch and is **not merged** before that
  chain completes.
- **Memory is not evidence.** A factory claim needs an artifact.
- **Never invent a factory value.** A derived bag weight once reached live and
  had to be withdrawn (PR #128). That precedent is why interpolation is banned.

The master prompt's §7 ("do not repeatedly ask for permission… push branches,
open PRs, run migrations") is written as though this governance does not exist.

---

## 3 · Corrections to the master prompt

Five categories. **A** wastes work, **B** destroys deliberate design, **C**
exceeds an agent's authority, **D** is blocked, **E** is right and should be
kept.

### A · Factually wrong about this system

| § | The prompt says | What is true |
|---|---|---|
| **16** | "The business currently uses **three Tally synchronization runs per day**. Preserve this behaviour. **Discover the actual configured run times.**" | There are no configured run times to discover. **No scheduler exists anywhere.** The "three a day" figure is a *consequence*, not a schedule: DEC-20260807-010 posts one consolidated Stock Journal per (production_date, shift), and there are three shifts — "roughly three vouchers a day". Release is event-driven (shift end + idle hold), never clock-driven. |
| **17** | Store `sync_run_id`, `scheduled_time`, `records_selected/sent/succeeded/failed/skipped/conflicted`, `mode` per run. | No run concept exists to instrument. This is a **new subsystem**, not observability added to an existing one. It presupposes §16's scheduler. |
| **28** | "Determine whether production generates Manufacturing Journal or Stock Journal. **The XML decides.**" | Already determined and recorded. FC-04 defines the production **Stock Journal** shape; DEC-20260805-001 rests on 38 real Stock Journals. The word "Manufacturing" appears **zero times** in all of `docs/factory/`. The ERP's internal `Manufacturing Journal` label is a *dispatch key only* — the wire says `Stock Journal`, deliberately, so it works whether or not the client has Tally BOM enabled. |
| **30** | Adopt a 14-value status model; "avoid ambiguous statuses such as only true/false". | Statuses are already a 4-value enum, not booleans. Expanding to 14 values touches every guard in `TallySyncService`, the agent's decision function, and 15 test files — for statuses that mostly describe a scheduler that does not exist. |
| **31** | Idempotency as though absent. | Already four layers deep with 24 dedicated tests. §31 should read "**do not regress** idempotency", with the existing tests as the contract. |
| **39–40** | "Add View XML / Copy XML / Download XML" and "keep Request XML / Response XML". | **The cloud has never seen XML.** It stores JSON; the agent builds XML on the factory PC; Tally's response never leaves the premises. Showing real sent-XML in the cloud UI requires the agent to *upload* it first — a new data flow, with an FC-06 review (payloads carry rates), not a UI feature. |
| **13/93** | "Use the actual names found in XML"; "provide real counts, derive from XML/database, do not estimate." | Correct in spirit, but see **D** — the XML is not in the repo. Also: the one census that exists is from a Tally company named **"Testing"**, and it lists **no Sales voucher type at all** among 3,811 vouchers, while its receipts reference sales bills. That gap must be named, not reasoned around. |
| **50** | "Investigate the incorrect `420`-style numeric box… If it represents configured pack quantity, remove it." | Note: `420` also appears in the evidence as a **bill reference format** (bare numbers `647`, `420`, `421` in the receipts export). Confirm which artefact is meant before acting. *(Shift-floor specifics pending — see §5.)* |

### B · Would destroy deliberate design

| § | The instruction | Why it must not be followed as written |
|---|---|---|
| **44–46, 83** | Build ERP↔Tally reconciliation by matching ERP transactions against Tally transactions. | **Reading from Tally was deliberately removed.** v0.3.3 removed the Stock Summary read trigger entirely; v0.3.4 removed the automatic masters loop ("no automatic reads from Tally"). Reads were hanging and are **suspected of corrupting the live Tally company on 08-Aug-2026**. Q36 asks the owner which half-hour of the day is quietest *precisely because* the finance pull must be **one deliberate, human-triggered read**. An unattended reconciliation loop is the exact failure mode that was engineered out. |
| **16** | "Preserve" three daily runs by building a scheduler. | Building one would **replace** DEC-20260807-011's release gate, which exists because a 90-second poll otherwise freezes each shift's voucher after the first approval, producing one voucher per approval instead of one per shift. The decision also forbids hardcoded clock times ("7am/3pm/11pm are today's shift ends, not constants"). |
| **8, 117** | Safety gates listed, but stock reconciliation and master-data work treated as routine. | DEC-20260806-009: syncing stock to Tally means **matching the difference**, never re-applying an opening balance — "a later snapshot receipted on top of an earlier one doubles the stock silently". The prompt's §58 "no unexplained balance mutations" is compatible; its §44 auto-matching is not. |
| **59** | Full lot traceability: "Supplier → PO → GRN → **Raw Material Lot → Production Consumption → Production Batch**". | **FC-01 forbids the middle link.** A resin bag belongs to no machine and no batch; a bag scan is a *pour record, not consumption*; batch consumption is **calculated**. DEC-20260807-006 killed the per-machine Bin Bay page; DEC-20260807-007 says the common resin input will **never** be weighed or counted and the day-bin balance is a permanent estimate with no re-anchor. Building bag→batch provenance is explicitly prohibited. |
| **7** | Autonomy: push branches, open PRs, run migrations, "do not repeatedly ask for permission". | `main` **auto-deploys to the live factory**. `AGENTS.md` mandates Builder → Cursor → Codex → owner before any merge. Live master-data changes go through manual workflows, **dry-run first, read the dry run before writing**. |
| **107** | Introduce a suggested role set (Production Operator, Supervisor, … System Admin). | Roles already exist and carry decided semantics — FC-05 (four eyes: QC ≠ completer, approving accountant ≠ signing plant manager), FC-06 (purchase rates Owner/Accounts only), DEC-20260810-001 (costing rate visible to Owner/Plant Manager/Accounts only, never Supervisor). Redesigning roles risks silently widening rate visibility. |

### C · Contradicts a recorded owner decision — not an agent's call

| § | The instruction | The decision it crosses |
|---|---|---|
| **24, 25, 57, 80 (Phase 6), 84** | Build the Sales Order → Dispatch → Invoice → Tally Sales lifecycle; "Sales Orders must be fully investigated… whether ERP originates them". | **DEC-20260809-003 (owner, 09-Aug):** *"ALL real sales are invoiced directly in Tally — the ERP Sales module is demo-scale… e-invoicing (IRN/QR) is NOT in use today."* Live holds **one** ERP invoice against Tally's 553 receipts. Phase 6 as written builds a lifecycle the owner has placed in Tally. Moving sales into the ERP would be a **new owner decision**, and must be asked, not assumed. |
| **33–43 (Control Center), 45** | Surface Finance/reconciliation dashboards. | **DEC-20260812-001:** CRM and Finance **stay hidden** from the ERP surface until the factory uses them; adoption is a one-line source edit, deliberately not a toggle anyone can flip on a live floor. A finance surface built today "would ship invisible". |
| **18–21 (PO lifecycle), 79 (Phase 5)** | Build the PO→Tally flow. | **Direction confirmed, build explicitly gated.** DEC-20260812-002 says POs are raised in the ERP from now on and sent to Tally as a Purchase **Order** voucher that **must not touch accounts or stock** — but the same record says *"Nothing changes on the day this is recorded"*, and **Q35(d) — does the accountant want an order voucher in Tally at all — blocks whether it is written at all.** Also gated by Q39 (per-rate purchase ledger) and Q40 (dual units). |
| **51** | Audit and change production estimation formulas. | Formula inputs are decided: DEC-20260806-003 (masterbatch 2.5% of bottle weight, editable per run), DEC-20260805-005 (one unit weight per run), DEC-20260807-003 (paper "CT" is an observation, not a standard), FC-07 (clear bottles take no masterbatch). Q18 keeps specific pack counts contested. Changing a formula is a factory decision. |
| **62** | Never silently map masters — flag MATCHED / UNMAPPED / MULTIPLE MATCH / CONFLICT. | Good rule, and the *spirit* is already enforced by refusal. But note DEC-20260810-003: unset Tally identities are **left editable, never guessed**, and Q33 warns explicitly *"Do NOT re-key… would create a SECOND 490 spec"*. A conflict-resolution UI that invites a bulk "fix" is more dangerous here than the current refusal. |
| **48** | On multiple Tally matches, offer "Create Separate SKUs". | Half-supported. DEC-20260810-003 confirms **each packaging variant may carry its own Tally identity** — so one product → several Tally items is right. But DEC-20260806-011 says duplicate rows for one bottle are **pack variants of a single product, not separate products**, and Q42 resolved only the SKU's *purpose*; the format in `docs/SKU-SCHEME-DESIGN.md` is **proposed, not confirmed**, and carries no packing dimension. "Create separate SKUs" is therefore not the recorded answer. |

### D · Blocked on evidence that is not in the repo

The prompt's §11–13, §63, §64, §65 (file inventory, XML catalog, golden fixtures,
round-trip tests) rest on Tally XML. **`find . -name '*.xml'` returns exactly one
file: `backend/phpunit.xml`.**

| Evidence | Status | Consequence |
|---|---|---|
| `Transactions.xml` — 38 real Stock Journals, the ground truth behind FC-02/03/04 | **MISSING** — "DELETED from Downloads since — ask the factory to re-share" (**Q31**) | No golden-fixture phase can begin. |
| 12-Aug Day Book (POs), PO pending register (183 open lines / 42 items), Receipts (145), Statistics screenshot | **EXTERNAL** — held outside the repo, sha256-pinned, deliberately not committed because they carry purchase rates (FC-06); **Q38** asks whether they may be | Usable by a human with the files; not by an agent working from the repo alone. |

A "build the XML catalog" phase scheduled before Q31 is answered will produce
invented structure. That is precisely the failure `AGENTS.md` names.

### E · Genuinely right — adopt these

These are real improvements and several identify actual defects:

1. **§116 / customer→ledger mapping.** Correct and important. `party_ledger` is a
   **name string resolved at posting time** and `customers` has no ledger FK.
   This is a live defect worth fixing.
2. **§27 — do not merge Journal / Stock Journal / Manufacturing Journal into one
   generic "Journal".** Correct, and currently muddled: the ERP's
   `Manufacturing Journal` label emits a `Stock Journal`. Independently
   filterable categories are the right ask.
3. **§35–37, §42–43 — filters and CSV export on the sync page.** The page today
   has **no filters at all** — only sort-by-status — and **no CSV export**. This
   is the highest-value, lowest-risk improvement in the entire prompt.
4. **§41 — a human-readable voucher summary.** Right instinct; the JSON payload
   view already half-does this for production vouchers and should be generalised.
5. **§98–99 — retry with a reason, and a `NEEDS REVIEW` dead-letter state instead
   of infinite retry.** Partly present (`dismissed` is terminal, `resolution_log`
   records fixes); a genuine review state is a real gap.
6. **§104 — distinguish `created_at` / `business_date` / `voucher_date` /
   `sync_date`.** Correct and already partly honoured; worth making explicit.
7. **§103 — timezone discipline.** Already implemented exactly as asked
   (`config('tally-sync.factory_timezone')`, `app.timezone` stays UTC). Keep as a
   regression guard.
8. **§92 — no claim without evidence**, and **§119 — agents are workers, not
   sources of truth.** These match `AGENTS.md` and should be kept verbatim.
9. **§114 — fix the transaction model before the UI.** Correct.
10. **Test gaps the prompt implies are real and measurable:** zero tests for
    `SalesOrderService`, `DeliveryService` stock decrement, `OverDeliveryException`,
    carton-scan dispatch guards, `InvoiceService`, invoice→Tally, all of CRM, and
    all of Finance. No frontend test exists for `tally-sync`.

---

## 4 · Defects found during the audit

These were not in the prompt. They are real, evidenced, and stand on their own
merit. Ordered by severity.

### 4.1 · P1 — Purchase rates are served ungated on two Procurement payloads (FC-06)

FC-06: *purchase rates and supplier details are Owner/Accounts only; floor and
sales logins never see cost or supplier.*

`MaterialLotResource` honours this with care — it **omits** the rate keys
entirely (rather than nulling them, because a null rate is a real state for
opening stock), gated on `finance.view` / `finance.manage`. Its own comment
records why: leaving it open *"handed the GRN purchase rate to any inventory
viewer — the exact figure the owner limited to Owner and Accounts."*

The same number is returned unconditionally two files away:

- `backend/app/Modules/Procurement/Http/Resources/PurchaseOrderLineResource.php:17`
  → `'unit_price' => $this->unit_price,`
- `backend/app/Modules/Procurement/Http/Resources/GoodsReceiptNoteLineResource.php:19`
  → `'unit_cost' => $this->unit_cost,`

Both are served under `api.php:184`, guarded by `module:procurement` only.
`procurement` and `finance` are **independent** permission catalog entries
(`PermissionService.php:26` vs `:51`), so a procurement-only user holds neither
`finance` permission. `GoodsReceiptNoteLineResource` nests the *gated*
`MaterialLotResource` inside the *ungated* line — the lot correctly omits
`receipt_rate_per_kg` while its parent prints `unit_cost`, which migration
`2026_08_02_100001:12-13` names as the very same number.

**Not tested.** `MaterialLotCostVersionTest` covers what an *inventory-only* user
sees; no test covers a **procurement-only** user against
`/procurement/purchase-orders` or `/procurement/goods-receipts`.

> **Live exposure depends on whether any role actually holds `procurement.*`
> without `finance.*`.** Roles are created at runtime, not seeded, so this must
> be read off the live Roles screen — not guessed, and not from dev fixtures
> (the 09-Aug shift-rail defect came from exactly that mistake).

### 4.2 · P1 — `stock_balances` is not a projection of `stock_movements`, and nothing checks

`StockMovementService.php:20-24` asserts *"stock_movements is an append-only
ledger."* Both halves of that sentence are unenforced:

- **The balance is mutated independently.** `StockMovementService.php:347` and
  `:381` write `stock_balances.quantity` directly. **Nothing anywhere sums
  `stock_movements` to verify it.** Every read goes to the balance row.
- **Append-only is not enforced.** No `booted()`, no `static::updating` /
  `deleting` guard exists in Inventory, Procurement or Production models. And
  one path deletes movements outright:
  `backend/app/Console/Commands/ResetTestData.php:265`.
- **No test asserts balance == Σ movements.**

The only external detector is `TallyStockReconcileService`, which compares ERP
against **Tally** — not against the ERP's own movement history. So a drift
between ledger and balance is invisible until Tally disagrees, and would then be
"corrected" toward Tally rather than diagnosed.

This is the strongest argument in the whole audit *for* the prompt's §58
("auditable ledger, no unexplained balance mutations") — but the fix is an
internal invariant + test, not a new reconciliation UI.

### 4.3 · P2 — The stock ledger records direction, not purpose

`StockMovementType` has exactly four values: `receipt`, `issue`, `transfer_in`,
`transfer_out`. Opening balances, production consumption, production output and
Tally reconciliation adjustments are **all** plain `receipt`/`issue`,
distinguished only by free text in `reference` / `notes` — and recovered by
string matching (`issuesForReference()`). There is no `adjustment` type and no
period `closing` concept at all.

The prompt's §58 enumerates exactly the movement kinds that are missing. Adding
a `purpose`/`reason` dimension is a genuine, well-evidenced improvement.

### 4.4 · P2 — Dead statuses that no code can ever write

| Enum value | File | Consequence |
|---|---|---|
| `SalesOrderStatus::Cancelled` | `Sales/Models/Enums/SalesOrderStatus.php:11` | A confirmed sales order can never be cancelled — no endpoint exists |
| `InvoiceStatus::Paid` | `Sales/Models/Enums/InvoiceStatus.php:9` | **Nothing can ever mark an invoice paid.** There is no payments table, no `markPaid`, no receipt model. `AccountsReceivableService::outstanding()` filters `status != Paid`, so **receivables grow forever** |
| `PurchaseOrderStatus::Cancelled` | `Procurement/Models/Enums/PurchaseOrderStatus.php` | Unreachable; no cancel route |

### 4.5 · P2 — No correction path on transactional documents

No `show`, `update` or `destroy` on sales orders, deliveries, invoices,
quotations, journal entries, purchase orders or goods receipts. A wrong GRN
cannot be corrected through any endpoint — `receipt_key` is *replay
idempotency*, not amendment, and a mismatched payload hash is refused outright.
The only post-hoc stock reversal on received material is the QC-rejection issue.

For the PO this is deliberate and documented (a Tally mirror "is corrected in
Tally and re-mirrored, never edited here"). For the GRN it appears to be an
omission rather than a decision.

### 4.6 · P3 — Untested guards on live-money paths

Verified absent by grep, not inferred:

- `OverReceiptException` — the core partial-receipt guard — has **zero** tests.
- `OverDeliveryException` — untested.
- `DeliveryService` stock decrement — untested.
- Carton-scan dispatch guards (including the DEC-20260807-013 quality-rejected
  refusal) — untested.
- `InvoiceService`, invoice issue, invoice→Tally Sales voucher — untested.
- `PurchaseOrderService::send()`'s `InvalidStatusTransitionException` — untested.
- All of CRM, all of Finance — zero tests.
- No frontend test for `tally-sync`.

### 4.7 · P3 — Unvalidated Tally voucher builders shipping in production

Three builders carry an explicit self-warning in their own docblocks:
`salesInvoice.ts:19-32` (*"BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A
REAL TALLY INSTANCE… doesn't yet emit GST tax ledger entries (CGST/SGST/IGST)"*)
and `receiptNote.ts:17`. The Sales invoice payload is **tax-exclusive** — no
CGST/SGST/IGST line reaches Tally at all. Given DEC-20260809-003 (real sales are
invoiced in Tally directly), this is currently low-blast-radius, but it is a
loaded gun if anyone starts issuing ERP invoices.

Related: `TallySyncService.php:136-146` puts `tally_order_no` and
`order_due_dates` on the Receipt Note payload, but `receiptNote.ts` neither
declares nor emits them — so the PO reference never actually reaches Tally.

### 4.8 · P3 — Dead code and one unreachable module

- `POST /tally-sync/items` (`api.php:304`) plus `ItemSyncService` plus the
  `tally-sync:items` token ability are **unreachable from the shipped agent** —
  only `/masters` is called.
- Three stock-snapshot routes, `TallyStockSnapshotController`,
  `TallyOpeningStockService` and `TallyStockReconcileService` have **no UI at
  all** (`grep "stock-snapshot" frontend/src` → zero hits); reachable only via
  API or the manual GitHub workflow.
- `mastersPollIntervalSeconds` is configured but read by no loop.

### 4.9 · Knowledge-system hygiene (from the factory audit)

- **An unrecorded conflict on Q30(b):** `TALLY-EVIDENCE-2026-08-12.md` §B asserts
  *"Bill-wise detail is ON"* (395 `BILLALLOCATIONS`, 176 `Agst Ref`), while
  `PENDING-OWNER-QUESTIONS.md` Q30 keeps that half **open** for the accountant.
  Measured evidence vs absent owner confirmation — per `SOURCE-PRIORITY.md` this
  must be **named, not silently resolved**. A plan reading only the evidence doc
  would build ageing on an unconfirmed answer.
- **Stale bookkeeping:** the Q-numbering preamble says both "continue from Q27"
  and "continue from Q43" in the same block; only Q43 is current.
- **Q14's own example is still live:** immutable DEC-20260806-001 cites
  `sources/manifest.yaml`, renamed to `manifest.json`, and validation still
  reports sound — i.e. file-path references are not validated the way DEC-/FC-
  ids are.

### 4.10 · P1 — The Tally wire key is the item **name**, and `name` is editable through the API with no guard

`SKU-SCHEME-DESIGN.md:29-30` states the rule plainly: *"The thing that must never
be bulk-edited is `name` — that is what Tally matches."*

Nothing enforces it. `UpdateItemRequest.php:21` accepts
`'name' => ['sometimes','string','max:255']`, exposed at
`PUT /api/v1/inventory/items/{item}` (`routes/api.php:142`) with an edit form at
`ItemsPage.tsx:238-239`.

Meanwhile **every voucher line carries the name, and no GUID ever reaches
Tally**. Confirmed across both sides:

- Laravel payloads: `TallySyncService.php:304, :329, :747, :761` (production),
  `:56, :112, :162` (invoice / GRN / delivery).
- Agent XML: `manufacturingJournal.ts:82`, `stockJournal.ts:74`,
  `salesInvoice.ts:38`, `receiptNote.ts:64` — all
  `<STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>`.
- **No GUID appears in any voucher builder.** GUIDs appear only on the *inbound*
  masters/stock-summary read.

`items.tally_stock_item_guid` is a genuine stored identity — unique, matched on
upsert, never on name — but at post time it functions only as a *preview warning
flag* (`VoucherPreviewService.php:177-186`), reached after the item was already
re-found **by name** at `:170`.

**Consequence:** one name edit in the ERP — or one rename in Tally — silently
breaks posting for that item, and the failure surfaces days later as a Tally
rejection, not at the moment of the edit.

This is the strongest possible evidence for the prompt's §116. The fix is not a
new subsystem: it is a guard on `name`, and a GUID cross-check at voucher build.

### 4.11 · P1 — A live hole in the local-fixture posting gate (shift granularity)

Two independent implementations of "is this a local fixture", and they disagree.

The top-level guard is correct — `TallySyncService.php:215-221` uses
`effectiveItem()?->isLocalFixture()`, which honours **either** the
`is_local_fixture` column **or** the `LOCAL-` SKU prefix (`Item.php:89-107`).

The **sweep** that pulls a shift's other entries into the same voucher tests only
the prefix, and only on the *base* item — `TallySyncService.php:620-623`:
```php
->whereDoesntHave('item', fn ($q) => $q->where('sku', 'like', 'LOCAL-%'))
```

- **Hole B — live today.** The sweep tests `item`, never `finishedItem` /
  `effectiveItem()`. A real product whose *packaging identity* points at a
  `LOCAL-` fixture is correctly skipped when it is itself approved, but is
  **swept into another entry's voucher** when a shift-mate is approved.
  `SaveProductionStandardPackagingRequest.php:53, :88-93` validates the identity
  as existing and active — with **no `isLocalFixture()` check** — and the
  frontend picker filters on `is_active` alone.
- **Hole A — latent, arms itself on the SKU rename.** A column-flagged fixture
  whose SKU no longer starts `LOCAL-` passes the sweep. Nothing can set the flag
  without the prefix *today* (`is_local_fixture` is fillable but absent from
  `UpdateItemRequest`), so this goes live the moment the planned SKU programme
  runs — which is the entire subject of `SKU-SCHEME-DESIGN.md`.

**Neither hole is tested in either direction.** `LocalFixtureFlagTest` proves the
model honours the column; nothing proves the sweep does.

### 4.12 · P1 — The schema cannot represent the case DEC-20260810-003 was raised for

`2026_07_29_120001:106-109` creates `UNIQUE(production_standard_id, mode)` as
`psp_standard_mode_unique`, never dropped since. **A standard can hold at most
one tray packing.**

DEC-20260810-003's raising case is precisely *two tray counts of one product* —
`"B.200 Ml Round Pet Bottle Amber 18gms"` vs `"… - 520 Nos"`, one product, two
packs, two Tally names. The schema cannot hold it.

Failure mode is a 500, not a 422: `refuseExactDuplicate()`
(`ProductionStandardPackagingController.php:157-175`) matches on mode **and all
four counts**, so it catches exact twins only. A second tray option with
*different* counts passes validation and dies on the unique index as a
`QueryException`. The modal offers all three modes on Add even when all three
exist (`ProductStandardsPage.tsx:2544` disables the mode select only on edit).

**Related, and still open:** the 490/box variant **does not exist on live.**
Migration `2026_08_10_191000` ran on 11-Aug and did nothing, because no live
standard is named `200ML RA` (the live family is BRUTE / DOME / KOREAN / ROUND).
It logged a warning and *"reported that as success"*. The migration's own comment
forbids the tempting fix: *"Do NOT re-key this migration to a guessed name"* —
that is **Q33**, the owner's to answer.

### 4.13 · P2 — `sku` is re-seeded from `name` on every masters pull

`ItemService.php:84` seeds `'sku' => $this->uniqueSkuFrom($data['name'])`, where
`uniqueSkuFrom()` is `trim($name)` with a `-2` collision suffix (`:114-124`).

`SKU-SCHEME-DESIGN.md:8` describes this in the **past tense** ("the masters pull
evidently filled `sku` from `name`"). It is current, live code. Any SKU renaming
programme is silently undone for every newly-pulled Tally item unless this is
changed in the same change.

Two further dependencies the design doc does not list:
- **The SKU is used as a barcode today**, contradicting the doc's *"not a
  barcode"*: `ItemsPage.tsx:288` renders `<BarcodeDisplay code={barcodeItem.sku}>`.
  A rename invalidates every item barcode previously printed from that screen.
- **Nothing refuses a `LOCAL-` SKU typed onto a real item.** Because
  `isLocalFixture()` returns true on either signal, doing so **silently stops
  that item's vouchers posting**.

### 4.14 · P2 — Pack-quantity precedence disagrees between writer and reader

The declared precedence is packaging → item master, frozen at Start Batch
(`ShiftProductionEntryService.php:373-377`).

The expected-boxes metric reads a **different** chain
(`ShiftProductionEntryService.php:2862`, pouches at `:2874`):
```php
$nosPerBox = $entry->nos_per_box ?? $entry->item?->nos_per_box;
```
It consults neither the packaging row nor `config_snapshot`. **A run whose
packaging says 490/box, completed with the pack field left blank, is measured
against the item master's figure.**

Of the five pack keys written into `config_snapshot` at `:374-377`, only
`nos_per_box` is ever read (twice, as a second fallback). `nos_per_tray`,
`nos_per_pouch`, `pouches_per_box` and `trays_per_box` are **written and never
consumed**.

This substantiates the prompt's §49–51 instinct — but the fix is **precedence and
a snapshot that is actually read**, not removing the operator's input field.

### 4.15 · P3 — Two independent scrap-item resolvers, both failing silently

`TallySyncService.php:496-506` and `ShiftProductionEntryService.php:2135-2145`
are byte-for-byte duplicates: exact SKU lookup, then exact name lookup, against
one config string (`production.scrap.rejected_item_sku`).

The docblock explains why it must not be a pattern match — *"'Pet Scrap',
'PET Scrap - Amber', 'PET Scrap - Lumps' and 'Pet Bottles Scrap' all exist in
this factory's masters, and a near-miss books real weight against the wrong
one."* Correct. But on a miss both return `null` and **no scrap line is posted at
all, silently** — against FC-02, which requires scrap to be booked inward. If the
SKU programme runs without updating the config key, the SKU branch misses.

### 4.16 · P2 — `packing_lines` are validated in full and then silently discarded

`CompleteBatchRequest.php:102-121` defines the complete `packing_lines.*`
contract and `:147-273` enforces cross-line arithmetic. The frontend builds and
sends them (`ShiftProductionEntryPage.tsx:4362-4366`).

**Nothing persists them.** `grep -rn "packing_lines" backend/app/` hits only the
FormRequest and one unrelated docblock; `ShiftProductionEntryService` never reads
the key, and no `packing_lines` table exists. `PackingLinesTest.php` asserts only
the aggregates that survive (`:217-220`) and the 422 refusals — never a stored
line.

**Per-mode packing detail is lost at completion; only the totals survive.**

### 4.17 · P3 — A stale comment claims an approval stage that does not exist

`backend/routes/api.php:555-556` states the chain is *"PM verifies → Accountant
reconciles → **MD final approval** → Tally."*

There is **no MD stage**. Only `pmApprove` and `accountantApprove` exist
(`ShiftProductionEntryController.php:102-118`), and the frontend agrees:
`ApproveProductionPage.tsx:1294` — *"The accountant is FINAL; there is no MD
stage"* — with `STAGES` holding exactly two entries. The accountant is the
posting gate. Code is authoritative; the route comment is stale and should be
corrected before it misleads another planner.

### 4.18 · P3 — Shift Summary has zero automated coverage

`ShiftSummaryService::report()` (180 lines, six date-filtered sources) is
**untested**. `grep -rn "shift-summaries" backend/tests/` returns nothing; the
only test touching the table asserts a unique constraint.

Two honesty caveats worth carrying into any reporting work:
- `machines_running` / `machines_down` filter rows by date but test **current**
  state, so for a past date they read "still open as of now".
- `efficiency_percent` divides by `target_production_kg`, a **manually typed
  supervisor input**, null whenever nobody typed it — on a card titled
  *"Computed KPI Report"*.

### 4.19 · P3 — An entire ingest surface with no caller

`POST /production/shift-production-entries/page` (`api.php:546` →
`ingestPage()` → `ShiftPageEntryService`) accepts a whole paper page of rows
(`IngestShiftPageRequest.php:38-65`). **No frontend calls it** — zero hits in
`frontend/src`. It is exercised only by tests. Either it is unfinished work or
a deliberate API-only path; nothing records which.

---

## 5 · Corrections to the prompt's Production sections (§49–55)

The Production audit overturns four of the prompt's premises.

### §50 — the "420 box" is not where the prompt says it is

`grep -rn "420" frontend/src/features/production/` returns **one hit**:
`CartonTracePage.tsx:96`, a CSS `maxWidth: 420`.

**Start Batch has nine fields, and the only number the operator types is "Active
Cavities."** `StartBatchRequest.php:17-124` accepts no pack-quantity, pieces or
box field of any kind — a "420" typed at Start could not reach the server.

The 420-class number lives in the **Complete Batch** dialog, and there are two
candidates:
- **`nos_per_box` / "Pcs/carton"** (`ShiftProductionEntryPage.tsx:7164-7166`,
  packing lines at `:6718-6741`) — the likelier referent, given the 490/520
  family this factory actually packs.
- **`quantity_produced`** (`:6956-6990`) — whose server validator's docblock
  literally uses `"420"` as its example (`CompleteBatchRequest.php:18-22`).

**Neither should simply be "removed".** `nos_per_box` at completion is how the
floor records the box it actually packed, which is a real observation
(DEC-20260807-015: real packing is "the counted boxes plus tape"). The genuine
defect is §4.14 — the *metric reader* ignores the configured value — not the
presence of the field.

### §49 — already satisfied; the prompt's fear is unfounded

Shift Floor asks the pack question **only when the product genuinely offers a
choice**, and then as a radio pick, never a typed number:

```tsx
// ShiftProductionEntryPage.tsx:5715
{(batchPreview?.variants?.length ?? 0) > 1 && (   // "Which standard is this run?"

// ShiftProductionEntryPage.tsx:5750
if (!chosen || chosen.packagings.length < 2) return null;   // "How is it packed?"
```

One variant ⇒ no standard question. Fewer than two packagings ⇒ no packing
question. Half-stated workbook rows are shown **disabled**. The 490/520 numbers
come from `production_standard_packagings` and are never typed at Start.

### §51 — pack quantity is **not** confused with estimated pieces

`nos_per_box` / `nos_per_tray` / `nos_per_pouch` appear in no pieces formula.
They are only ever **divisors of an already-computed piece count**
(`BatchEstimationService.php:97-102`, `ProductionCalculationEngine.php:143-158`).

The formula is real and versioned — `ProductionCalculationEngine.php:44-73`:
```php
$cycles = (int) bcdiv($seconds, $cycleTime, 0);   // production_v2_floor
return $cycles * $cavities;
```
with configuration → standard → item-master precedence
(`BatchEstimationService.php:63-68`), downtime netting (`:92-118`), and two
deliberately separate rounding stages (FLOOR on cycles = physics, ROUND on boxes
= reporting convention).

This exact confusion **existed and was already fixed**: efficiency was moved from
box-grain to piece-grain because 14,322 actual vs 13,333 expected displayed as
75% (`ShiftProductionEntryService.php:2880-2887`). Re-opening the formulas
without cause risks re-introducing it — and the inputs are owner-decided
(DEC-20260806-003, DEC-20260805-005, DEC-20260807-003, FC-07).

### §52 — completion is already durable

Completion is a server-side compare-and-swap inside `DB::transaction`
(`ShiftProductionEntryService.php:454-559`); a concurrent double-complete throws.
`grep -rn "localStorage\|sessionStorage\|indexedDB" frontend/src/features/production/`
returns **zero hits** — there is no browser state to lose. Start-batch drafts use
URL query params (`startBatchResume.ts:91-127`), so a refresh keeps them and
closing the tab does not.

The real durability gap is §4.16 (`packing_lines` discarded), which the prompt
does not mention.

### §54 — Shift Summary already does what the prompt asks

Date picker (`ShiftSummaryPage.tsx:128-134`), Shift A/B/C radio (`:117-127`),
whole-day scope (`:111-115`), and every source server-filtered by the client's
chosen date (`ShiftSummaryService::report()` — `whereDate('production_date', …)`
at `:74, :92, :148, :165, :177, :189`). The date is **required from the client**;
nothing derives `today()`. It reads persisted history, not live floor state.

What it actually needs is **tests** (§4.18) and honesty fixes on two KPI inputs —
not a rebuild.

### §53 — Completed Today is a real table with a real, narrow flaw

It is a genuine server-backed `<Table>` (`ShiftProductionEntryPage.tsx:5306`,
columns `:5395-5449`, 20s polling). The flaw is the window:
```ts
// ShiftProductionEntryPage.tsx:1813-1815
const completedToday = (entries?.data ?? [])
    .filter((e) => e.batch_status === 'completed' && e.production_date === today)
    .slice(0, 15);
```
Only page 1 (20 rows) is fetched, then capped at 15 — a **client-side filter over
a server-paginated window**. On a busy day, completed batches silently vanish
from the list. That is worth fixing; "replace/repair into a proper data table" is
not.

### §55 — CEC does not exist at all

`grep -rni "\bcec\b"` over all source and all of `docs/` returns **zero
matches**. There is no CEC route, controller, service, export or download, and
no CEC sample or format authority anywhere in the repo.

**This is net-new work with no specification.** The prompt says to "use the
provided manual production book / CEC sample as the formatting authority" — that
sample has not been provided. Building it without one means inventing a factory
document format, which `AGENTS.md` forbids.

Note also: every existing export is **client-side CSV** generated from
already-fetched JSON (`ReportsPage.tsx:121, 313, 477` via `frontend/src/lib/csv`).
There is **no server-side export endpoint in Production at all** — so the
prompt's §43 ("do not export only the rows rendered in the browser") describes a
real, systemic gap.

---

## 6 · The rebuilt plan

The prompt's 13-phase sequence (Phase 0 Discovery → Phase 12 Release) assumes a
system being brought to life. This one is alive and running a factory. Sequencing
by *module* is therefore wrong: it would spend Phase 6 building a sales
lifecycle the owner has placed in Tally, while a live posting hole (§4.11) and a
live confidentiality gap (§4.1) sit unfixed.

The correct axis is **what is blocked, and by whom.** Four tracks. A and B need
no owner input and can start immediately. C cannot start at all until the owner
answers. D is a schema change that unblocks a recorded decision.

> Note: `DEVELOPMENT-PLAN.md`'s status section is dated **2026-07-19** — a month
> stale. Two competing phase plans would send agents to different maps. Whichever
> plan is adopted must supersede the other explicitly.

### Track A — correctness defects. No decision required. Start here.

Ordered by blast radius, not by effort.

| # | Work | Evidence | Gate |
|---|---|---|---|
| **A1** | Gate `unit_price` / `unit_cost` on the two Procurement resources the way `MaterialLotResource` already does; add a test for a **procurement-only** user | §4.1 | Read live Roles first — do not infer from dev |
| **A2** | Guard `items.name` against edit (or require an explicit confirm + provenance stamp), and cross-check the GUID at voucher build so a name drift fails **loudly at the edit**, not days later in Tally | §4.10 | — |
| **A3** | Close local-fixture Hole B: make the shift sweep test `effectiveItem()` like the top-level guard does; refuse a fixture as a packaging identity. Test both holes in both directions | §4.11 | Ship before any SKU rename — Hole A arms on that change |
| **A4** | Add the ledger invariant: a test (and a check command) asserting `stock_balances` == Σ `stock_movements`; enforce append-only on the model | §4.2 | — |
| **A5** | Persist `packing_lines`, or delete the contract that pretends to accept them | §4.16 | Persisting is the likelier intent — confirm |
| **A6** | Fix the `completedToday` window — filter server-side instead of slicing page 1 | §5 (§53) | — |
| **A7** | Tests for the untested money/stock guards: `OverReceiptException`, `OverDeliveryException`, delivery stock decrement, carton-scan dispatch guards (incl. DEC-20260807-013), `ShiftSummaryService::report()` | §4.6, §4.18 | — |
| **A8** | De-duplicate the two scrap-item resolvers into one; make a miss **loud**, not a silent null (FC-02 requires the scrap line) | §4.15 | — |
| **A9** | Correct the stale 4-stage/MD comment at `api.php:555-556` | §4.17 | — |
| **A10** | Decide the dead statuses: either wire `SalesOrderStatus::Cancelled`, `InvoiceStatus::Paid`, `PurchaseOrderStatus::Cancelled` or remove them. `InvoiceStatus::Paid` is the urgent one — receivables currently grow forever | §4.4 | Removal vs wiring is a product call |

### Track B — high value, low risk, no decision required

These are the prompt's genuinely good ideas, reduced to what this architecture
can actually support.

| # | Work | Prompt § | Note |
|---|---|---|---|
| **B1** | **Filters on the Tally Sync page** — date range, status, voucher type, source module, document number, party/item | §35–37 | The page has **no filters at all** today. Highest value-to-risk ratio in the entire prompt |
| **B2** | **Server-side CSV export honouring those filters** | §42–43 | Must be server-side; every existing export is client-side over a fetched page |
| **B3** | **Distinguish the journal categories** so Journal / Stock Journal / (ERP-labelled) Manufacturing Journal are independently filterable, and surface the label-vs-wire divergence honestly | §27 | Do **not** rename the wire value — `manufacturingJournal.ts:16-18` emits `Stock Journal` deliberately |
| **B4** | **Generalise the human-readable voucher summary** beyond production vouchers | §41 | The JSON payload view already half-does this |
| **B5** | **A real `needs_review` state** replacing infinite retry, with reason + attempt count surfaced | §98–99 | `dismissed` is terminal but is an operator's judgement, not a system state |
| **B6** | **A UI for the stock snapshot / reconcile services** that exist with no screen | §4.8 | Read-only surfacing of what the workflow already does |
| **B7** | Explicitly name the four date fields (`created_at`, `business_date`, `voucher_date`, `sync_date`) wherever they are shown | §104 | Partly honoured already |

**Deliberately excluded from Track B, with reasons:**

- **§39–40 "View XML / Request + Response"** — the cloud has never seen XML.
  Delivering this means the agent uploading its built payload and Tally's raw
  response, which is a new data flow across the factory boundary and needs an
  FC-06 review (payloads carry quantities; responses may carry more). If it is
  wanted, it is its own scoped piece of work, not a UI ticket.
- **§17 sync-run instrumentation** — presupposes a scheduler that does not exist.
- **§30's 14-status model** — would touch every guard and 15 test files to
  describe states this architecture does not have.

### Track C — blocked on the owner. Do not start.

Each of these is a question, not a task. Per `AGENTS.md`, they go to
`PENDING-OWNER-QUESTIONS.md` and stop there.

| Blocked work | Blocked by |
|---|---|
| PO → Tally (prompt §18–21, Phase 5) | **Q35(d)** — does the accountant want an order voucher in Tally *at all*? Plus Q39 (per-rate purchase ledger), Q40 (dual units). DEC-20260812-002 confirms the direction but says *"nothing changes on the day this is recorded"* |
| Sales lifecycle in the ERP (§24, 25, 57, Phase 6) | **DEC-20260809-003** places all real sales in Tally. Moving them is a new owner decision |
| ERP↔Tally reconciliation (§44–46, Phase 9) | **Q36** — which half-hour is Tally quietest. Any pull must be ONE deliberate human-triggered read; automatic reads were removed after the 08-Aug corruption |
| CEC export (§55) | No CEC sample, format or authority exists anywhere. Building one means inventing a factory document |
| XML catalog + golden fixtures (§11–13, 63–65) | **Q31** — `Transactions.xml` is lost and must be re-shared. **Q38** — may the 12-Aug exports be committed (they carry rates, FC-06)? |
| The SKU format programme | Q42 resolved only the SKU's *purpose*. The `TYPE-SIZE-SHAPE-COLOUR-WEIGHT` format is **proposed, not confirmed**. Its own doc sequences the agent HSN fetch first — and that does not exist (`grep -rni hsn tally-sync-agent/src` → zero hits) |
| The 490/box variant | **Q33.** The migration no-op'd on live and its comment forbids re-keying to a guessed name |
| Finance / CRM surfaces (§33–45 dashboards) | **DEC-20260812-001** keeps both hidden until the factory uses them |

### Track D — the schema change that unblocks a decision

**D1 — drop `psp_standard_mode_unique`, replace with a constraint that permits
two packings of the same mode with different counts** (§4.12).

DEC-20260810-003 is a *current* owner decision whose raising case the schema
cannot represent. Until this changes, a second tray pack is a 500 error, and the
490-vs-520 case the decision was written about cannot be entered at all. This is
the one structural change with a recorded decision already behind it.

Must ship with: a 422 (not a `QueryException`) on genuine duplicates, a mode
select that does not offer an impossible combination, and a test for the
different-counts case that `PackagingTallyIdentityTest.php:356` currently
sidesteps with `delete()`.

### What to keep from the prompt's engineering method

Adopt substantially unchanged — these match `AGENTS.md` and are good practice:

- **§92** — no claim without evidence; report per-capability, not "it works".
- **§119** — agents are workers, not sources of truth; validate every material
  claim against code, database, runtime, browser, tests.
- **§114** — fix the transaction model before the UI.
- **§117** — never alter real data to make a test pass.
- **§118** — test create/update/retry/failure/duplicate/partial/cancel/
  historical/concurrent/permission-denied, not the happy path.
- **§70–72** — an implementer's own "looks good" is not verification. Note this
  repo already has a stronger version: Builder → Cursor → Codex → owner.
- **§89** — parallelise only genuinely independent work.
- **§9** — durable engineering memory. This document is the first instalment.

**Amend §7 (autonomy) before any agent acts on it.** As written it authorises
pushing branches and running migrations. In this repo `main` auto-deploys to the
live factory, and `AGENTS.md` requires the review chain before any merge. §7 must
be rewritten to end at "open a PR and stop".

---

## 7 · Clarifications received 2026-08-16, and how the plan changes

Seven clarifications were given after the discovery pass. Four **confirm** what
the evidence found; three **change** the plan. Recorded here so the reasoning is
not lost.

### 7.1 Confirmed by the evidence — no change needed

1. **"Three daily syncs" means preserve the current business behaviour, not
   build a clock scheduler.** This is exactly what the evidence supports:
   shift-based Stock Journal releases gated by shift-end + 15-minute idle hold
   (DEC-20260807-010, DEC-20260807-011). §16 of the prompt is amended to
   *"preserve the existing release gate; introduce no scheduler."*
2. **No continuous automated reads from Tally.** Matches the deliberate removal
   in agent v0.3.3/v0.3.4 and Q36. Direct reads stay manual and deliberate.
   Prompt §44–46 is amended accordingly.
3. **Sales ownership does not move from Tally to the ERP** without a new explicit
   decision. Matches DEC-20260809-003. "Check everything" means **inspect, trace,
   display, filter and test** every real transaction type — not re-home it.
4. **Preserve shift-level aggregation; do not assume one voucher per batch.**
   Matches DEC-20260807-010 and DEC-20260807-014.

   > **Trap worth naming:** `config/tally-sync.php:26` defaults
   > `voucher_granularity` to **`'batch'`**, but the live `.env` was flipped to
   > **`'shift'`** on 07-Aug-2026 (DEC-20260807-014; the
   > `flip-voucher-granularity` workflow defaults to `shift`). **Anyone reading
   > the config file alone will draw the wrong conclusion about live
   > behaviour.** The two shift-granularity holes in §4.11 are live *because* of
   > this, and any test written against the packaged default tests the wrong
   > mode.

### 7.2 Changed — the Tally Sync Control Center becomes the priority

The Control Center is promoted from "Track B item" to **the primary deliverable**,
covering every transaction type, statuses, filters, details, failures, responses
and CSV export. Track A's correctness defects still ship first or alongside,
because two of them (§4.1, §4.11) are live and unbounded, but the Control Center
is the goal the rest serves.

### 7.3 Changed — the visibility requirement stands; the design changes

*"The cloud currently not holding XML does not cancel the visibility
requirement."* Accepted, and this is a better design than the prompt's §39–40.

The sync detail surface is therefore built around the **normalized chain**, which
the cloud already holds end to end:

```
ERP transaction  →  voucher type  →  master mappings  →  agent payload  →  agent result
   (module,           (Stock Journal /    (item → GUID,      (the JSON        (ack / error /
    document,          Sales / Receipt     godown, ledger     actually          Tally response
    business date)     Note / …)           role)              delivered)        summary)
```

Every link exists today: `tally_sync_entries.syncable_*`, `tally_voucher_type`,
the GUID/godown/ledger mapping tables, the JSON `payload`, and
`error_message` + `resolution_log`.

**Separately investigate a sanitized generated-XML snapshot pushed *from* the
agent** — the agent already builds the XML and already sees Tally's response
(truncated to 2,000 chars into a local file log). Uploading a sanitized snapshot
is a bounded change on the agent side. It must **not** be done by moving XML
generation to the cloud.

Constraints that investigation must respect:
- **FC-06 review before anything is uploaded.** Voucher payloads carry
  quantities; Tally responses may carry more. Purchase-adjacent vouchers must not
  become a rate-disclosure path.
- Retention and storage limits — the prompt's §102 (XML archive) needs a decided
  retention period, not an unbounded table.
- The agent is on the factory PC and must stay resilient; upload failure must
  never block or delay a post.

### 7.4 Changed — Purchase Orders move from "blocked" to "staged, gated"

The stated requirement: **ERP is the operational PO source.** Opening/confirming
an eligible PO makes the appropriate Tally PO transaction available for sync;
receipts follow the correct accounting/inventory flow; closing synchronizes the
final state. **Prove the exact Tally contract from the supplied XML before
enabling live writes.**

This is consistent in direction with **DEC-20260812-002** ("purchase orders are
raised in the ERP from now on, not in Tally") — so the direction is not a new
decision, it is a recorded one.

Two things must be said plainly rather than worked around:

- **Today the flow is the exact opposite.** `PurchaseOrder` is documented as
  *"a read-only mirror of an order that lives in Tally — corrected there, never
  here"* (`PurchaseOrder.php:19-23`), there is **no `enqueuePurchaseOrder()`**,
  and the agent's own test asserts a `Purchase Order` voucher type **throws**
  (`stockJournal.test.js:68-73`). Reversing this is real work, not a switch.
- **DEC-20260812-002 states that Q35(d) — does the accountant want an order
  voucher in Tally at all — "blocks whether it is written at all."** That is the
  decision record's own wording. The build can legitimately proceed to a
  *proven-contract, not-enabled* state without crossing it, but **enabling live
  writes requires Q35(d) answered.** Q39 (purchase ledger per local/interstate ×
  rate) and Q40 (dual units) gate correctness of the payload itself.

**Staged plan:**

| Stage | Work | Gate to leave the stage |
|---|---|---|
| **P0** | Obtain the PO XML contract evidence | **Q31/Q38** — the exports are outside the repo; `Transactions.xml` is lost |
| **P1** | Derive the exact Tally PO voucher contract from that XML; write golden fixtures | Contract proven against real structure, not inferred |
| **P2** | Build `enqueuePurchaseOrder()` + agent builder behind a **disabled** flag; prove by test that it touches **neither accounts nor stock** (DEC-20260812-002 requires this "stated in the code and proved by a test") | All tests green, dry-run only |
| **P3** | Receipt flow: make the existing Receipt Note actually carry `tally_order_no` / `order_due_dates` — today they are on the payload but the builder neither declares nor emits them (§4.7) | — |
| **P4** | Enable live writes | **Q35(a)–(e), Q39, Q40 answered** |

Also required, and currently missing: PO **amendment** and **close** endpoints.
`api.php:191` exposes only `index`/`store`/`send`; `Closed` is derived from full
receipt and there is no manual close or cancel route (§4.5). "Closing
synchronizes the required final state" cannot be built until closing is
expressible.

### 7.5 Confirmed and strengthened — FC-06

FC-06 is authoritative. Three actions, in order:

1. **Verify live role exposure, read-only.** No existing workflow can do this —
   `tally-sync-status.yml` and `read-server-log.yml` are the only read-only live
   workflows and neither dumps roles. So either the Roles screen
   (`/administration/roles`) is read by a person, or a **new read-only workflow**
   is added that lists roles and their permissions and writes nothing. Live only,
   never dev — the 09-Aug shift-rail defect came from trusting dev fixtures.
2. **Protect the fields consistently regardless of what that read shows.** The
   inconsistency between `MaterialLotResource` and the two Procurement resources
   is a defect whether or not a role currently exploits it.
3. **Add a procurement-only regression test** — a user holding `procurement.*`
   and **not** `finance.*` must not see `unit_price` or `unit_cost`.

### 7.6 Standing instruction

**No major workflow changes until this discovery/audit is complete and the phase
plan is agreed.** Track A defects are correctness fixes, not workflow changes,
and §4.1 / §4.11 are live — they should be raised for approval first and
separately, rather than waiting on the full plan.

---

## 8 · The rebuilt master phase plan (v2, after clarifications)

Replaces the prompt's Phase 0–12. Built from the actual architecture, sequenced
by *what blocks what*, with the owner-gates named. Each phase ends with the
prompt's Phase Completion Contract (§73) — that part of the prompt is kept.

```
PHASE 0   Discovery + audit                    ← THIS DOCUMENT · DONE
PHASE 1   Live-safety fixes                    no owner gate · start immediately
PHASE 2   Sync Control Center — foundation     no owner gate
PHASE 3   Sync Control Center — every type     no owner gate
PHASE 4   Agent XML/response snapshot          FC-06 review gate
PHASE 5   Ledger + packaging schema            D1 has a decision behind it
PHASE 6   Purchase Order contract              Q31/Q38 → Q35 · staged, flag-off
PHASE 7   Regression + reporting honesty       no owner gate
PHASE 8   Release readiness                    —
──────────────────────────────────────────────────────────────────────
HELD      Sales in ERP · CEC · reconciliation-by-read · SKU format · Q33
```

### Phase 1 — Live-safety fixes (Track A, first slice)

The two live defects and their guards. Small, surgical, PR each.

| Task | Ref |
|---|---|
| **P1-01** Read live roles (read-only) — a person on `/administration/roles`, or a new read-only workflow | §7.5 |
| **P1-02** Gate `unit_price` / `unit_cost` on `PurchaseOrderLineResource` and `GoodsReceiptNoteLineResource` exactly as `MaterialLotResource` does | §4.1 |
| **P1-03** Procurement-only regression test (holds `procurement.*`, not `finance.*`, sees no rate) | §7.5 |
| **P1-04** Close local-fixture Hole B (sweep tests `effectiveItem()`); refuse a fixture as packaging identity; test both holes both directions | §4.11 |
| **P1-05** Guard `items.name` edits; GUID cross-check at voucher build so drift fails at the edit | §4.10 |
| **P1-06** De-duplicate scrap-item resolvers; loud miss | §4.15 |
| **P1-07** Correct the stale MD-stage comment | §4.17 |

**Exit:** all four gates green (CI + Cursor + Codex + owner), deployed, live
smoke via `tally-sync-status.yml`.

### Phase 2 — Sync Control Center: foundation

The registry the UI needs, built *on* `tally_sync_entries`, not replacing it.

| Task | Notes |
|---|---|
| **P2-01** Normalized read model over `tally_sync_entries`: source module, source entity, document number, business date, voucher type, party/item summary, direction, status, attempts, last error, resolution log | Adapt existing columns; the prompt's §29 shape is a *target*, not a table to create blindly |
| **P2-02** Server-side filtering: date range, status, voucher type, source module, document number, party/item, shift, machine (where applicable) | The page has zero filters today |
| **P2-03** Server-side CSV export honouring filters, with a range cap and a "Download All" backend job | Every current export is client-side; this must not be |
| **P2-04** `needs_review` state + reason + attempt count; retry refuses permanent validation failures | Extends the 4-value enum minimally, keeps every existing guard |
| **P2-05** Journal / Stock Journal / (ERP-labelled) Manufacturing Journal as independent filter categories, with the label-vs-wire divergence shown honestly | Do **not** rename the wire value |
| **P2-06** Header counts: today's total / synced / pending / failed / needs review, and per-type counters **for types actually present** | Prompt §33–34 |
| **P2-07** Frontend tests for the tally-sync feature (currently zero) | |

**Exit:** filters + CSV proven in browser on live-shaped data; contract tests on
the read model; no change to what reaches Tally.

### Phase 3 — Sync Control Center: every real transaction type

*"Inspect, trace, display, filter and test all real transaction types."* The
types that exist **today**, and what each needs:

| Type | Exists? | Work |
|---|---|---|
| Stock Journal (production, shift + batch modes) | ✅ mature | Detail drawer: consumption/production tables already exist — generalise |
| Receipt Note (GRN) | ✅ | Detail; surface that `tally_order_no` is on the payload but not emitted (§4.7) |
| Delivery Note | ✅ | Detail; test the DEC-20260807-013 refusal path end-to-end |
| Sales (invoice) | ✅ code, ⚠️ unvalidated builder, no GST | Detail; **mark as unvalidated in the UI**; do not encourage use (DEC-20260809-003) |
| Journal (finance JE) | ✅ code, module hidden | Detail; visible in the sync page even though Finance nav is hidden — a posted JE would still sync |
| Purchase Order | ❌ | Phase 6 |
| Purchase voucher / Payment / Receipt / Contra / Credit-Debit Note | ❌ inbound only, in Tally | Show as **"lives in Tally"** categories in the census, not as ERP-originated rows. Counts come from the Statistics screen evidence, not from ERP tables |

| Task | |
|---|---|
| **P3-01** Detail drawer per type: ERP source → voucher type → mappings (item GUID, godown, ledger role) → agent payload → agent result | The normalized chain from §7.3 |
| **P3-02** Human-readable summary per type (§41), generalised from the production view | |
| **P3-03** Timeline per entry: created → approved → delivered → acked/failed → retried (§97) — from existing timestamps | `created_at`, `delivered_at`, `synced_at`, `released_at`, `resolution_log` |
| **P3-04** Mapping-state surfacing: for each line, whether item/godown/ledger resolved by identity, by name-only, or unmapped | This is where §116/§62 becomes visible without inventing a conflict table |
| **P3-05** Tests per type: create / retry / failure / duplicate-refused / dismissed / needs-review / permission-denied | Prompt §118 kept |

### Phase 4 — Agent-side sanitized XML + response snapshot

Investigation first, then build only if the FC-06 review passes.

| Task | |
|---|---|
| **P4-01** Design: agent uploads `{ entry_id, xml_sha256, sanitized_xml, tally_response_summary, agent_version }` on ack/fail; upload failure never blocks the post | |
| **P4-02** FC-06 review: enumerate every field that could carry a rate or private content per voucher type; define the sanitizer; retention period decided | Gate |
| **P4-03** Storage: bounded table or file store with retention; `payload_hash` on the entry (§32) as a *fingerprint*, not identity | |
| **P4-04** UI: "What the agent sent" / "What Tally answered" panels in the detail drawer, XML formatted + copy | Only after P4-02 |
| **P4-05** Agent release via the existing ritual (build on CI, review gate, manual publish) | `releaseContract.test.js` governs |

### Phase 5 — Ledger invariant + packaging schema

| Task | Ref |
|---|---|
| **P5-01** `stock_balances == Σ stock_movements` invariant: test + check command; append-only enforced on the model | §4.2 |
| **P5-02** Movement *purpose* dimension (opening / consumption / output / adjustment / reconcile) alongside direction; backfill from `reference`/`notes` where unambiguous, else `unknown` | §4.3 |
| **P5-03 (D1)** Replace `psp_standard_mode_unique` so two same-mode packings with different counts are representable; 422 not 500; mode select honesty; different-counts test | §4.12, DEC-20260810-003 |
| **P5-04** Pack-quantity precedence: metric reader consults the packaging row / snapshot; snapshot keys actually read | §4.14 |
| **P5-05** Persist `packing_lines` (or delete the contract) | §4.16 |
| **P5-06** Wire or remove dead statuses; `InvoiceStatus::Paid` first | §4.4 |

### Phase 6 — Purchase Order → Tally (staged, flag-off)

Exactly the P0–P4 staging in §7.4. Live writes stay **disabled** until Q35 is
answered. Also delivers PO amend/close endpoints, since "closing synchronizes"
requires closing to exist.

### Phase 7 — Regression + reporting honesty

| Task | Ref |
|---|---|
| **P7-01** Tests for `OverReceiptException`, `OverDeliveryException`, delivery decrement, carton-scan guards, PO `send()` transition | §4.6 |
| **P7-02** `ShiftSummaryService::report()` tests; label the two non-computed KPI inputs honestly | §4.18 |
| **P7-03** Completed Today: server-side filter, not a sliced page | §5 |
| **P7-04** Decide `ingestPage` — finish or document as API-only | §4.19 |
| **P7-05** Full-application regression across every adopted module (prompt §111 kept) | |

### Phase 8 — Release readiness

Prompt §86 kept as written, plus: `DEVELOPMENT-PLAN.md` status brought current or
explicitly superseded by this plan.

### HELD — needs the owner before a line is written

| Item | Question |
|---|---|
| Sales lifecycle in ERP | New decision superseding DEC-20260809-003 |
| CEC export | A sample + format authority; none exists |
| ERP↔Tally reconciliation by reading Tally | Q36; and a decision that a deliberate read is wanted |
| SKU format programme | Format confirmation; agent HSN fetch first (does not exist) |
| 490/box variant | Q33 |
| Committing XML/exports to the repo | Q31, Q38 |
| Finance / CRM surfaces | DEC-20260812-001 |

---

## 9 · Three further requests (2026-08-16, later) — my reading and recommendation

### 9.1 "Add the log and add it phase-wise"

**Reading:** a durable, per-phase engineering log — the prompt's §9/§73/§87
(engineering memory, phase completion contract, phase board) — so no phase
disappears into a chat session.

**Recommendation — build it as three files, not ten.** The prompt's ten-file
memory set (`MASTER-PLAN`, `BUSINESS-RULES`, `ARCHITECTURE`, `DATA-MODEL`,
`TALLY-CONTRACT`, `TALLY-XML-CATALOG`, `TEST-MATRIX`, `RECONCILIATION`,
`PHASE-STATUS`, `OPEN-DECISIONS`) would **duplicate what this repo already keeps
elsewhere and better**:

| Prompt file | Already exists as | Verdict |
|---|---|---|
| `BUSINESS-RULES.md` | `docs/factory/FACTORY-CONSTITUTION.md` + `CURRENT-DECISIONS.md` (tool-written, immutable, validated) | **Do not duplicate.** A second business-rules file will drift and lose to the canonical one under `SOURCE-PRIORITY.md` |
| `OPEN-DECISIONS.md` | `docs/factory/PENDING-OWNER-QUESTIONS.md` | **Do not duplicate** — same reason |
| `ARCHITECTURE.md` | `TECHNICAL-DOCS.md` + `CLAUDE.md` | Extend, don't fork |
| `TALLY-XML-CATALOG.md` | Cannot exist yet — no XML in the repo (Q31/Q38) | **Held** |
| `RECONCILIATION.md` | Held with the reconciliation track (Q36) | **Held** |
| `MASTER-PLAN.md`, `PHASE-STATUS.md`, `TEST-MATRIX.md` | Nothing equivalent | **Build these three** |

So `docs/engineering/` grows to:

```
docs/engineering/
  MASTER-PROMPT-AUDIT.md   ← this document (Phase 0 output)
  MASTER-PLAN.md           ← the §8 plan, standalone, the thing agents read
  PHASE-LOG.md             ← append-only: one entry per phase → per task,
                              each with the §73 contract fields:
                              scope · changes · migrations · APIs · UI ·
                              tests · browser evidence · data verification ·
                              known limitations · open decisions · commits ·
                              PR · PASS / NOT READY
  TEST-MATRIX.md           ← per phase: unit / feature / contract / DB /
                              frontend / E2E / build — PASS / FAIL /
                              NOT TESTED / BLOCKED (prompt §70 kept)
```

`PHASE-LOG.md` is **append-only by convention** — a phase entry is amended by a
dated addendum, never rewritten — mirroring how the decision records work.

### 9.2 "Clean configuration"

**Reading:** ambiguous. Two very different things fit the words, and I will not
pick silently.

**(a) Configuration *files* — dead keys, wrong defaults, drift.** Real and
already evidenced by this audit:

- `config/tally-sync.php:26` defaults `voucher_granularity` to `'batch'`, but
  **live runs `'shift'`** (DEC-20260807-014). The default lies about production.
  Either flip the packaged default to match live, or state in the file that live
  overrides it — today a reader draws the wrong conclusion.
- `mastersPollIntervalSeconds` in the agent config is **read by no loop**
  (removed in v0.3.4).
- `tally-sync:items` token ability, `POST /tally-sync/items` route and
  `ItemSyncService` are **unreachable from the shipped agent** (§4.8).
- `TallyLedgerRole` has eight mapping roles; **only `sales` is read** by any
  builder (§1.4).
- The `manufacturing`/`stock journal` label divergence (§3-A §28).

This is a **bounded, no-decision cleanup**. Recommend it as **Phase 1 task
P1-08** — with one caution: `TallyLedgerRole`'s unused roles are *forward
scaffolding* for Phase 6 (purchase ledgers per DEC-20260812-003) and should be
kept, not removed.

**(b) Configuration *data* — the product/machine master.** Also has an evidenced
backlog: two duplicate-config ambiguities were closed by DEC-20260810-002, but the
490 variant is absent from live (Q33), Q18 keeps two pack counts contested, and
Q25/Q26/Q32 hold open product-master mismatches. **This is factory data, every
item of it owner-gated**, and lives in the HELD list — not something an agent
"cleans".

**I have assumed (a).** If (b) was meant, it goes to `PENDING-OWNER-QUESTIONS`.

### 9.3 "An agentic inside the application"

**Reading:** an in-app AI assistant for ERP users — most plausibly on the Tally
Sync Control Center ("why did this voucher fail?", "what will this post as?").

**This is architectural — a new subsystem — and it needs its own brainstorm
before a design is proposed.** Recorded here so it is not lost, with the
constraints already known:

- **Hosting.** No Redis, no persistent queue worker, no WebSocket, `database`
  queue driver on shared hosting (`TECHNICAL-DOCS.md` §8). Any assistant that
  streams or runs long jobs collides with this immediately.
- **FC-06.** An assistant that reads ERP data must respect the same role gates
  as the API — it cannot become a rate-disclosure side channel. It must go
  through `/api/v1` with the user's own token, never a service account.
- **AGENTS.md hard lines.** It must not post a voucher, create/cancel a batch, or
  change stock as a side effect. **Read-and-explain first; act never, until a
  separate decision.**
- **Evidence discipline.** It must cite the row / decision / test it is reading,
  never assert. The same rule this audit followed.
- **What it should be first:** a *read-only explainer* over the normalized chain
  from §7.3 — given a sync entry, narrate ERP source → mappings → payload →
  result → likely fix, citing `resolution_log` and the existing `fix.sentence`
  advice. That already exists as data; the assistant is a presentation layer.
  This is the smallest useful version and it inherits every safety property of
  the Control Center.

**Recommendation:** treat as **Phase 3.5** — after the Control Center exists (it
needs the normalized chain to read from), before Phase 4 — and open its own
brainstorm with the questions above. Not before then; an assistant over a page
with no filters explains nothing.

### 9.4 "Use multi agents, workflow agent, testing everything to complete this"

**Reading:** run the phase plan with the orchestration the prompt describes
(§2–6, §70–72), with mandatory independent QA and a regression gate per phase.

**Constraint the prompt does not know about:** this repo's review chain is
Builder → **Cursor** → **Codex** → owner (`AGENTS.md`). The prompt's
"Sonnet QA → Opus review" *adds to* that, it does not replace it. Practical
shape per phase:

```
Builder agent (with tests)  →  QA agent (independent, different model,
                                 runs the §70 matrix, browser proof)
        →  Reviewer agent (challenger: business rules, accounting, races,
             FC-/DEC- compliance)
        →  PR opened, chain: Cursor → Codex → owner
        →  merge (auto-deploys to live)  →  live smoke (tally-sync-status.yml)
        →  PHASE-LOG.md entry + TEST-MATRIX.md row
```

Parallelism only across **independent** phases (§89): Phase 2 (Control Center
read model) and Phase 5 (ledger invariant / packaging schema) touch different
files and can run concurrently; Phase 1 must land before either.

**Standing instruction stays in force:** no major workflow change until the plan
is agreed. That gate is the next message.

