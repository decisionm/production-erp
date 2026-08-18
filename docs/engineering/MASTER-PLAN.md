# Master Plan — Production ERP

**Status:** APPROVED as the *working* engineering plan · 2026-08-16 · **rev 2**
**Authority:** the lead's written directive in the engineering session of 2026-08-16 ("Phase 0 is approved and considered complete… The current Phases 2–8 plan shape is approved as the working plan"), recorded verbatim in `PHASE-LOG.md` (Phase 0 addendum). This is an **engineering** approval by the lead who runs this program — it is *not* a factory business decision and creates no `docs/factory/decisions/` record. Every owner-gated item in this plan (Q-nn, DEC-nn) still needs the owner.
**Derived from:** `MASTER-PROMPT-AUDIT.md` §8 (Phase 0 output). That document holds the
evidence; this one holds only the plan, so agents have one short thing to read.

> Governance: `AGENTS.md` and `docs/factory/` outrank this file. A phase gated on an
> owner question (Q-nn) does not start until that question is answered in
> `PENDING-OWNER-QUESTIONS.md`. Every phase ends with a `PHASE-LOG.md` entry.


Replaces the prompt's Phase 0–12. Built from the actual architecture, sequenced
by *what blocks what*, with the owner-gates named. Each phase ends with the
prompt's Phase Completion Contract (§73) — that part of the prompt is kept.

```
PHASE 0   Discovery + audit                    ← THIS DOCUMENT · DONE
PHASE 1   Live-safety fixes                    no owner gate · start immediately
PHASE 2   Sync Control Center — foundation     no owner gate
PHASE 3   Sync Control Center — every type     no owner gate
PHASE 3.5 Sales visibility (first-class)       Sales stays Tally-originated (DEC-20260809-003)
PHASE 4   Agent XML/response snapshot          FC-06 review gate
PHASE 4.5 Download / Export Center             CEC slot BLOCKED until a sample exists
PHASE 5   Ledger + packaging schema            D1 has a decision behind it
PHASE 6   Purchase Order contract              Q31/Q38 → Q35 · staged, flag-off
PHASE 7   Regression + reporting honesty       no owner gate
PHASE 8   END-TO-END CHAIN REGRESSION          Sonnet QA + browser proof · product not complete until it passes
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

### Phase 3.5 — Sales and Sales Order visibility (first-class deliverable)

**Decision preserved:** real sales are invoiced in Tally (DEC-20260809-003). The ERP
does not become the sales system of record unless a *new* owner decision says so.
**What the ERP must do regardless:** make every Sales / Sales Order transaction it
can see **visible, searchable, filterable, traceable and downloadable.**

Honesty about the evidence base, so nobody builds on sand: the ERP has **no read
path from Tally today** (removed in agent v0.3.3/0.3.4 after the 08-Aug corruption;
Q36 gates any deliberate read). So "everything visible" is built in two layers:

| Layer | Source | Available now? |
|---|---|---|
| **A** | ERP-originated sales orders, deliveries, invoices (`sales_orders`, `deliveries`, `invoices` + lines) and their `tally_sync_entries` (Delivery Note, Sales) | **Yes** — demo-scale today, real data if the factory ever uses the module |
| **B** | Tally-side Sales / Sales Order vouchers | **Only after** a sanctioned, human-triggered read exists (Q36 + a decision that a read is wanted). Until then the Sales page **states plainly** that Tally-side sales are not mirrored, rather than showing an empty table as if it were the truth |

| Task | |
|---|---|
| **P3.5-01** Sales Orders / Deliveries / Invoices: server-side search + filters (customer, status, date range, item, document number) and a real `show` endpoint per document — none exists today (§4.5) | |
| **P3.5-02** Traceability: SO → delivery (carton scan, DEC-20260807-013) → invoice → `tally_sync_entries` (Delivery Note / Sales) rendered as one chain on the document page; the Tally status of every invoice exposed (prompt §25) | |
| **P3.5-03** Wire or remove `SalesOrderStatus::Cancelled` and `InvoiceStatus::Paid` (§4.4) — an SO that can never be cancelled and an invoice that can never be paid are not "visible", they are misleading | |
| **P3.5-04** Mark the Sales-invoice Tally builder as **unvalidated / no GST** in the UI wherever an invoice's Tally status is shown (§4.7); do not encourage posting real invoices from the ERP while DEC-20260809-003 stands | |
| **P3.5-05** Layer B placeholder: a clearly-labelled "Sales in Tally" panel that says *not mirrored — deliberate reads only*, linking to the decision, so the gap is stated, not hidden | |
| **P3.5-06** Tests: search/filter/show; carton-scan → delivery → SO chain; permission gates; the empty-state honesty | |
| **P3.5-07** Downloads for all of the above land in Phase 4.5's Center, not as one-off buttons here | |

**Exit:** a user can find any ERP sales document by any of its identifiers, follow it
to its Tally entry, and the page never implies Tally-side sales are present when
they are not.

### Phase 4 — Agent-side sanitized XML + response snapshot

Investigation first, then build only if the FC-06 review passes.

| Task | |
|---|---|
| **P4-01** Design: agent uploads `{ entry_id, xml_sha256, sanitized_xml, tally_response_summary, agent_version }` on ack/fail; upload failure never blocks the post | |
| **P4-02** FC-06 review: enumerate every field that could carry a rate or private content per voucher type; define the sanitizer; retention period decided | Gate |
| **P4-03** Storage: bounded table or file store with retention; `payload_hash` on the entry (§32) as a *fingerprint*, not identity | |
| **P4-04** UI: "What the agent sent" / "What Tally answered" panels in the detail drawer, XML formatted + copy | Only after P4-02 |
| **P4-05** Agent release via the existing ritual (build on CI, review gate, manual publish) | `releaseContract.test.js` governs |

### Phase 4.5 — Download / Export Center (first-class deliverable)

**Why a Center:** every export that exists today is **client-side CSV over an
already-fetched page** (`ReportsPage.tsx:121, 313, 477`; the Tally sync page has none).
The prompt's §43 rule — *never export only the rows rendered in the browser* — is
therefore violated everywhere. One server-side export subsystem fixes it once.

Shape (adapt to the existing module pattern — an `Exports` capability inside each
owning module's Service, surfaced by one Core route group and one Core page; **not**
a new module that reaches into other modules' Eloquent):

```
GET  /api/v1/exports                     catalogue: what this user may export
POST /api/v1/exports/{kind}              enqueue with the same filter params the list endpoint takes
GET  /api/v1/exports/{id}                status · row count · download when ready
GET  /api/v1/exports/{id}/download       the file
```
Runs on the `database` queue (no Redis on this host); small ranges may stream
synchronously; large ranges are a job with a row cap that is **stated**, never silent
(prompt §43, "no silent caps"). Every export honours the caller's permissions —
**FC-06 applies to the file exactly as to the screen** (no rate columns for
non-finance).

| Export kind | Owning module | Status at plan time |
|---|---|---|
| Shift Summary (date · shift A/B/C · all) | Production | build — data exists, no server export |
| Production report / completed batches | Production | build — client-side today |
| **CEC** (date · shift · all shifts) | Production | **BLOCKED — no CEC sample or format authority anywhere in the repo.** The slot ships visibly disabled with the reason. Requires the owner's CEC sample; then Completed Production = Shift Summary = CEC is asserted by test |
| Purchase orders / receipts (filters as the list) | Procurement | build; rate columns finance-only |
| Sales orders / deliveries / invoices (Layer A) | Sales | build (Phase 3.5 filters) |
| Tally sync entries (filters as Control Center) | TallySync | build — Phase 2 P2-03 moves here |
| Tally sync "run"/history CSV | TallySync | build once history exists (P3-03 timeline) |
| Reconciliation / traceability (existing) | Production/Inventory | migrate from client-side |

| Task | |
|---|---|
| **P4.5-01** Export contract + Core routes/page; catalogue is permission-filtered | |
| **P4.5-02** Migrate the three existing client-side exports server-side (no feature loss) | |
| **P4.5-03** Shift Summary + production exports | |
| **P4.5-04** Procurement + Sales + Tally-sync exports (FC-06 on the file) | |
| **P4.5-05** CEC slot: disabled with reason; the moment a sample lands, the format is a **golden test**, not a guess | |
| **P4.5-06** Tests: filters honoured; row cap stated; permission-filtered columns; a non-finance user's file has no rate; browser proof of one download per kind | |

**Exit:** every export in the product goes through the Center; none is client-side;
CEC is either implemented against a sample or visibly blocked — never invented.

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

### Phase 8 — End-to-end chain regression, then release readiness

**The product is not complete until this chain passes**, with Sonnet as the
independent QA gate and browser proof at every link. Module-by-module green is not
the bar; the *chain* is.

```
Product / SKU configuration
  → Purchase (PO → GRN → lot)          [PO→Tally only if Phase 6 live-write gate passed]
  → Inventory (ledger == balance)
  → Production (start → complete → QC → PM → Accountant)
  → Shift Summary  (= Completed Production)
  → CEC            (= Shift Summary — or BLOCKED, stated)
  → Tally          (Stock Journal per shift, released by shift-end + idle; agent ack)
  → Sales visibility (Layer A documents traced to their Tally entries; Layer B stated)
  → Downloads      (one export per kind from the Center, FC-06 on every file)
```

| Task | |
|---|---|
| **P8-01** Chain script: a documented, repeatable walk with named fixtures (dev DB, never live), one representative product through every link | |
| **P8-02** Assertions at each link — the transaction model, not the screen: stock movements sum to balances; the shift voucher contains exactly the approved entries; identities frozen at completion; Shift Summary totals equal completed production; each export equals its list | |
| **P8-03** Sonnet independent QA runs the walk **without** the implementer's notes and reports per link: PASS / FAIL / NOT TESTED / BLOCKED | |
| **P8-04** Browser proof at every link (screenshots + the API responses behind them), attached to the phase log | |
| **P8-05** Full-application regression across every adopted module (prompt §111): login · roles · masters · products · purchase · inventory · production · sales · reports · downloads · Tally | |
| **P8-06** Release readiness (prompt §86 kept): no P0, no unresolved P1 data-integrity issue, all migrations accounted for, all tests green, production build passes, browser smoke passes, sync dry-run passes, rollback documented, monitoring ready. `DEVELOPMENT-PLAN.md` brought current or explicitly superseded by this file | |

**Verdict rule:** any link FAIL or NOT TESTED → the phase is `NOT READY`, full stop. A
link that is BLOCKED by a named owner gate (CEC sample, Q35 live-write) is recorded as
BLOCKED and the chain result is `PASS WITH DEFERRED ITEMS` — the product is **not
called complete** while a BLOCKED link remains.

### HELD — needs the owner before a line is written

| Item | Question |
|---|---|
| Sales lifecycle in ERP | New decision superseding DEC-20260809-003 |
| CEC export | A sample + format authority; none exists. **The Download Center ships CEC's slot visibly BLOCKED** (Phase 4.5) — never an invented layout |
| ERP↔Tally reconciliation by reading Tally | Q36; and a decision that a deliberate read is wanted |
| SKU format programme | Format confirmation; agent HSN fetch first (does not exist) |
| 490/box variant | Q33 |
| Committing XML/exports to the repo | Q31, Q38 |
| Finance / CRM surfaces | DEC-20260812-001 |

---
