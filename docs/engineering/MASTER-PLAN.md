# Master Plan — Production ERP

**Status:** APPROVED as the *working* engineering plan · 2026-08-16 · **rev 3 (2026-08-17)** — Phases 5–8 restructured around the FACTORY OPERATOR WORKFLOW at the lead's direction: after the Tally foundation (1–4) and the Download/Export foundation (4.5), the execution priority is Product/SKU configuration → Shift Floor → Estimation → Complete Batch → Completed Today → Shift Summary → CEC infrastructure → Purchase/Inventory → Sales visibility + Downloads. The final acceptance test is the operator workflow first, then accounting traceability. This is not to become a Tally Control Center project only.
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
PHASE 0   Discovery + audit                    DONE · PR #179
PHASE 1   Live-safety fixes                    PASS WITH DEFERRED · PR #180 · awaiting merge chain
PHASE 2   Sync Control Center — foundation     PASS WITH DEFERRED · PR #181 (stacked on #180)
PHASE 3   Sync Control Center — every type     PASS WITH DEFERRED · PR #182 (stacked on #181) · Q43 fail-closed
PHASE 3.5 Sales visibility (first-class)       PASS WITH DEFERRED · PR #183 (stacked on #182) · Q44 · Sales stays Tally-originated
PHASE 4   Agent XML/response snapshot          PASS WITH DEFERRED · PR #184 (stacked on #183) · agent 0.3.8 built, NOT published
PHASE 4.5 Download / Export Center             PASS WITH DEFERRED · PR #185 (stacked on #184) · CEC slot BLOCKED (exact reason) · synchronous, stated cap
──────────────────────────── OPERATIONAL WORKFLOW (rev 3) ────────────────────────────
PHASE 5   Product / SKU configuration          PASS WITH DEFERRED · PR #186 (stacked on #185) · Q45 · mapping values stay the owner's (Q33/Q43)
PHASE 5.5 Shift Floor → Complete → Today       PASS WITH DEFERRED · PR #187 (stacked on #186) · v3 estimation, legacy pinned · Q46
PHASE 5.7 Shift Summary + CEC infrastructure   PASS WITH DEFERRED · PR #188 (stacked on #187) · report contract + honesty keys · CEC data endpoint + golden harness · format BLOCKED · Q47
PHASE 6   Purchase chain + PO→Tally staged      PASS WITH DEFERRED · PR #189 (stacked on #188) · lifecycle/show/trace · flag OFF (Q35) · Q48
PHASE 7   Regression + hardening               INTEGRATED (gate pending) · PR #190 (stacked on #189) · suite green on sqlite AND MySQL 8 · Q49
PHASE 7.5 Store → Production material flow     NEW (lead, 17-Aug): Material Request → Store Issue → Issued-to-Production → Consumption → Return; Day Bin leaves the target workflow
PHASE 7.6 Configuration Lifecycle Contract     NEW (lead, 17-Aug): Create·View·Edit·Activate/Deactivate·Safe Delete·Audit, enforced in the BACKEND, across every master
PHASE 8   END-TO-END ACCEPTANCE: operator workflow, then accounting traceability, purchase chain, sales visibility + downloads
──────────────────────────────────────────────────────────────────────
HELD      Sales in ERP · CEC format · reconciliation-by-read · SKU format · Q33 (490/box) · Q35 (PO live write)
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
| **P2-03** ~~Server-side CSV export~~ → **moved to Phase 4.5** (built once in the Download Center on this read model) | Every current export is client-side; the Center fixes it once |
| **P2-04** ~~`needs_review` state~~ → **deferred to Phase 3** with reason (retry is manual only; no infinite loop to break; events now record the classification) | revisit with real-failure evidence |
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

*Outcome 2026-08-17:* P3.5-01/02/04/05/06 delivered; P3.5-03: `SalesOrderStatus::Cancelled`
WIRED (`POST sales-orders/{id}/cancel`, draft/confirmed with nothing delivered and no
invoice, row-locked), `InvoiceStatus::Paid` deliberately LEFT UNWIRED — receipts live in
Tally (DEC-20260809-003) and the pages say the ERP never marks an invoice paid; P3.5-07
→ Phase 4.5. Also closed the Phase 3 "Delivery has no replay key" gap (generic
`enqueue()` idempotent per document + voucher type). Lifecycle rules stated as engineering
defaults → **Q44**.

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

### Phase 5 — Product / SKU configuration (operational workflow, first slice)

**Why first:** everything downstream (Shift Floor, estimation, completion, Tally
identity, exports) reads configuration. Today the model is Product (`items`) →
standard (`production_standards`) → packaging variants (`production_standard_packagings`,
DEC-20260810-003, own Tally identity) — but the schema cannot represent two same-mode
packings with different counts (§4.12, D1), the pack-quantity reader disagrees with the
writer (§4.14), `packing_lines` are discarded (§4.16), and `sku` is re-seeded from
`name` on every masters pull (§4.13). **What stays the owner's:** the actual 490/520
mapping (Q33), any SKU→Tally identity that is ambiguous, cycle times, moulds — the ERP
builds the *capacity* to hold and review them; it does not invent a value.

| Task | What "done" is |
|---|---|
| **P5-01 (D1)** Replace `psp_standard_mode_unique` so two same-mode packings with different counts are representable; 422 not 500 on a duplicate; different-counts test | §4.12 |
| **P5-02** Variant model made explicit: one logical product → N SKUs (configured variants) → each with its production configuration and EXACT Tally identity (GUID + wire name); a `sku` is never re-seeded from `name` (§4.13) | Product page shows the tree; API `GET products/{id}/variants` |
| **P5-03** Multiple-Tally-match review: when a name resolves to more than one Tally item, the configuration surface OFFERS "create/link a separate SKU" and marks the variant `needs review` — never auto-picks (Phase 3's LineMappingResolver `ambiguous` state, Q43) | review queue + test |
| **P5-04** Pack quantity lives in configuration: precedence configuration → standard → item master, read by ONE reader used by estimation, completion and Tally (§4.14); `packing_lines` persisted or the contract deleted (§4.16) | one reader, one test per precedence rung |
| **P5-05** Ledger invariant `stock_balances == Σ stock_movements` (test + check command; append-only) and movement *purpose* (§4.2/§4.3) — kept here because production consumption reads it | invariant test green on dev + a live read-only check command |
| **P5-06** Configuration honesty on screen: a variant missing any of {standard, packaging, Tally identity} is shown as INCOMPLETE with the missing pieces named — never as a blank that Shift Floor will "ask again" | test + browser proof |

**Acceptance:** an operator opening Shift Floor for a fully configured SKU is asked
NOTHING that configuration already knows; an incompletely configured SKU says exactly
what is missing; a duplicate Tally name is a review item, never a guess.
**Owner-gated:** Q33 (490/box), any ambiguous SKU→Tally mapping, SKU format programme.

### Phase 5.5 — Shift Floor → Start → Estimation → Complete → Completed Today

The audit (`MASTER-PROMPT-AUDIT.md` §5) established what is already true — the pack
question is asked only when the product genuinely offers a choice (§49); the only
typed number at Start is Active Cavities and the "420" lives in Complete Batch as
`nos_per_box` (§50); pack quantity is a divisor, never in the pieces formula (§51);
completion is a server-side compare-and-swap (§52); Completed Today is a real table
with a sliced-page flaw (§53). This phase makes each of those a **tested contract**
and fixes the real defects — it does not rebuild what works.

| Task | What "done" is |
|---|---|
| **P5.5-01** Select the SKU once: Start Batch loads packaging, pack quantity, standard, mould and Tally identity FROM the Phase 5 configuration; the pouch/tray/standard radios appear only when the configuration holds a real choice (contract test per branch: 0/1/N variants, 0/1/N packagings) | tests + browser proof per branch |
| **P5.5-02** The Complete-Batch quantity fields are named for what they are (`nos_per_box` = the box actually packed; `quantity_produced` = pieces) and pre-filled from configuration; the "420 as example" docblock replaced by the real semantics; nothing typed at Start reaches the server as a pack quantity (already true — pinned) | request tests |
| **P5.5-03** Estimation verified against the production standards: cycle time × cavities × runtime − downtime → expected pieces (`production_v3_unified` since Phase 5.5 — cycles floored before cavities multiply, one engine for preview and entry, downtime netted on the entry side; entries stamped `production_v2_floor` keep their legacy computation), boxes = pieces ÷ pack quantity; recalculates on every input change; a table-driven test over the recorded standards (owner figures — never interpolated) and the piece-grain efficiency regression (§51) | `BatchEstimationService` + `ProductionCalculationEngine` contract tests |
| **P5.5-04** Completion reliable and durable: expected vs actual, good/reject, packs, downtime, efficiency; concurrent double-complete throws (pinned); the completed record is what Shift Summary and Tally read (identities frozen at completion) | lifecycle test + browser proof |
| **P5.5-05** Completed Today: SERVER-SIDE filter (date = today in factory time, status completed, machine/shift filters), paginated, columns machine · shift · SKU · expected · actual · good · reject · efficiency · approval/Tally state (Phase 3.5's TallySyncLinkService) — no client slice of page 1 (§53) | endpoint + test + browser proof |
| **P5.5-06** Start-Batch context preserved when configuration is missing: the operator can still start with what is known; the missing pieces are named on the batch and on Completed Today (never silently defaulted) | test |

**Acceptance (the operator's half of the final chain):** SKU → Shift Floor → Start Batch
→ Complete Batch, with expected/actual/good/reject/efficiency correct on the completed
record, Completed Today listing every completed batch of the day.

### Phase 5.7 — Shift Summary + CEC infrastructure

| Task | What "done" is |
|---|---|
| **P5.7-01** Shift Summary tests (§4.18): historical dates, Shift A/B/C, All Shifts; totals reconcile with completed production (`Σ completed batches == summary actual`), the two non-computed KPI inputs labelled honestly | `ShiftSummaryService::report()` contract tests + browser proof for a past date |
| **P5.7-02** CEC: **format BLOCKED — SOURCE DOCUMENT REQUIRED** stays until the owner's sample exists (HELD); everything around it is built: the CEC data endpoint (date · shift · all) returning the same figures Shift Summary shows, the export slot (Phase 4.5) wired to it, and the golden-file test harness that will assert `Completed Production == Shift Summary == CEC` the day a sample lands | endpoint + harness + the slot's reason verbatim |
| **P5.7-03** Reporting honesty sweep on Production reports (production / reconciliation / traceability): server-side, filters honoured, exports through the Center — **carried to Phase 7 (P7-05)**; not done in 5.7 | tests |

**Acceptance:** Shift Summary for any past date and any shift equals the completed
production of that date/shift; CEC infrastructure complete with the format visibly
blocked.

### Phase 6 — Purchase → GRN → lot → inventory → production consumption; then PO → Tally (staged, flag-off)

| Task | What "done" is |
|---|---|
| **P6-01** The purchase chain as a tested contract: PO (draft → sent → partially received → received; amend/close endpoints — "closing synchronizes" needs closing to exist) → GRN (receipt_key idempotency; `OverReceiptException` tests §4.6) → material lot → stock movement (purpose = opening/receipt) → balance (Phase 5 invariant) → production consumption (issue against a batch, purpose = consumption) | lifecycle tests + browser proof; FC-06 on every payload (Phase 1 gates kept) |
| **P6-02** PO server filters + show + trace (mirroring Phase 3.5's Sales shape) so the purchase chain is searchable and traceable end to end | tests |
| **P6-03** PO → Tally staged exactly per `MASTER-PROMPT-AUDIT.md` §7.4 P0–P4: contract proven from the supplied XML, payload builder, idempotency/retry, agent builder behind `tally-sync.purchase_orders_enabled=false`, browser-proven, **live write DISABLED until Q35 is answered** — the first live PO write is an owner gate and never happens unattended | contract tests; flag off; PHASE-LOG "owner-gated" |

**Acceptance (the accounting half of the final chain):** Purchase → GRN → Inventory →
Production consumption, traceable, with Tally visibility of the GRN (Receipt Note) as
today and the PO voucher staged behind the flag.

### Phase 7 — Regression + reporting honesty + hardening

| Task | Ref |
|---|---|
| **P7-01** Tests for `OverDeliveryException` (done 3.5), `OverReceiptException`, PO `send()` transition, carton-scan guards (done 3) | §4.6 |
| **P7-02** MySQL CI leg (the suite runs on sqlite; several constructs are MySQL-safe by reading only) + `assertEqualsCanonicalizing` on the six JSON-order tests | Phase 2 finding |
| **P7-03** Deferred hardening from the gates: agent snapshot for a post made while the cloud was down; snapshot show cap; TallySyncLinkService ranking for legacy duplicates; cancel/confirm actor + reason (with Q44) | PHASE-LOG deferred lists |
| **P7-04** Decide `ingestPage` — finish or document as API-only | §4.19 |
| **P7-05** Full-application regression across every adopted module (prompt §111 kept) | |

### Phase 7.5 — Store → Production material flow (lead's correction, 17-Aug-2026)

The lead has corrected the target workflow for material moving from the store to
production. **The Day Bin leaves the TARGET workflow** — but nothing is deleted blindly:
what is relied upon is determined first, then the operational workflow is refactored
safely and the historical rows stay exactly where they are.

```
Store Stock → Production Material Request → Store Issue → Scan/Handover
   → Issued-to-Production → Actual Consumption → Return unused (where applicable)
```

| Task | What "done" is |
|---|---|
| **P7.5-01** Audit first: what the Day Bin actually is, every writer and reader, what breaks if it stops being written, and what already exists to reuse (bags, lots, QC on arrival, warehouses) | a written audit with file:line, before a line is refactored |
| **P7.5-02** **Material Request**: request number · requested by · date/time · shift · machine/production area (where applicable) · SKU/batch (where known) · material · requested quantity + UOM · status. Production raises it; the Store gets a QUEUE | endpoints + tests |
| **P7.5-03** **Store Issue** against a request: partial fulfilment, remaining quantity, completed issue, cancellation, and unused-material RETURN | lifecycle tests, each state proven |
| **P7.5-04** **Scan/handover** for PET and raw-material bags: the actual bag/barcode scanned at handover, recording bag/lot identity, quantity/weight, the request, issued by, received by and timestamp. Replenishment is NOT daily (weekly / bi-weekly / consumption-driven); other consumables may be daily — nothing in the model may assume a daily cadence | tests incl. a non-daily cadence |
| **P7.5-05** **The accounting distinction, which is the heart of this change:** a Store issue is NOT consumption. Three states — `Store Stock → Issued to Production → Consumed` — plus `Issued to Production → Returned to Store`. Stock must never be deducted as production consumption at the moment the store issues it | ledger invariant holds at every state; `inventory:check-ledger` clean |
| **P7.5-06** Consumption traces back to its Store issue | trace tests |
| **P7.5-07** Day Bin retired from the target workflow: the live write doors closed, historical rows preserved and still readable, every reader migrated or explicitly retired | no data destroyed; readers named |

**HELD — one clause needs the owner (FC-01).** The lead also asks that consumption trace
to *the exact lot/bag*, and that a request may name a machine/area. `FACTORY-CONSTITUTION`
**FC-01** — sourced to the owner's own rulings of 01–06 Aug and reaffirmed in the owner's
06-Aug architecture brief — says the opposite in terms: *all machines draw from one common
resin loading point; a resin bag must never be represented as physically assigned to a
machine or a batch; batch consumption is calculated and the system must not claim physical
bag-to-machine or bag-to-batch provenance.* Everything else in this phase is compatible
with FC-01 (indeed FC-01 itself says a bag scan is a pour record, **not** consumption —
which is exactly the Store-issue-is-not-consumption rule). Only the bag→batch/machine
**provenance claim** collides. It is recorded as an owner question and **not built on an
agent's judgement**: either the owner supersedes FC-01 with a new decision (because the
floor now issues per machine/area), or consumption traces to the ISSUE and bag-level
identity stops at the store handover.

### Phase 7.6 — The Configuration Lifecycle Contract (lead's requirement, 17-Aug-2026)

The ERP is configuration-first — `Configuration → Operational Workflow → Transactions →
Reports/Tally` — and every applicable master must behave the same way. This is a
product-wide contract, not a Warehouse patch; the duplicated warehouses (FG / FG-STORE,
RM / RM-STORE) are one test case of it.

**Every applicable configuration/master entity supports:**
`Create → View → Edit → Activate/Deactivate → Safe Delete → Audit`

| Task | What "done" is |
|---|---|
| **P7.6-01** Audit every master and publish the matrix: `Configuration \| Create \| Edit \| Active-Inactive \| Delete-unused \| Dependency guard \| Duplicate guard \| Audit \| Tests`, each cell PASS / GAP / N/A | the matrix, in TEST-MATRIX.md |
| **P7.6-02** **Safe Delete, enforced in the BACKEND** (UI-only disabling is insufficient): hard delete ONLY when genuinely unused. The guard counts transaction history, stock/inventory, GRN/PO/Sales, production/batch, material requests/issues, child configuration, reporting dependencies, Tally/master mappings and every other FK/domain reference. Previously used → REFUSED, with Deactivate/Archive offered. **No destructive cascade to make a check pass** | per-entity dependency tests |
| **P7.6-03** One shared policy/service — dependency checks, delete eligibility, active/inactive, audit metadata, the API error contract, permissions — with domain-specific checks plugged in; NOT reimplemented per page | one mechanism, many registrations |
| **P7.6-04** The refusal EXPLAINS itself, with counts: "Cannot delete — used by 12 stock movements and 2 production batches. Deactivate instead." A `can` block on every configuration resource so the UI never re-derives eligibility | contract test on the error shape |
| **P7.6-05** Duplicate prevention: exact unique business codes where appropriate, normalised comparison where appropriate, WARN on likely duplicate names. Never auto-merge; **never merge records carrying transaction history without explicit evidence and an owner decision** | tests |
| **P7.6-06** The lifecycle test matrix: create · edit unused · delete unused · direct API delete unused · delete referenced → refused · direct API delete referenced → refused · deactivate referenced · inactive excluded from new operational selection · historical transactions still display the inactive configuration · reactivate where allowed · duplicate code refused · likely duplicate name handled · authorization · audit trail · Tally-linked configuration safety | one matrix, every applicable module |

**Convention note the reviewer must see, not have slipped past them:** `routes/api.php`
declares the repo append-only with no PUT and no DELETE anywhere, and CLAUDE.md says
anything deletable with transactional history uses soft deletes. A real Delete on unused
configuration is a deliberate, stated change to that convention — it is written down here
rather than introduced quietly.

### Phase 8 — End-to-end acceptance, then release readiness

**The product is not complete until this chain passes**, with Sonnet as the
independent QA gate and browser proof at every link. Module-by-module green is not the
bar; the *chain* is — and the OPERATOR'S chain comes first.

```
A · OPERATOR WORKFLOW
   SKU (configured once) → Shift Floor (asks nothing already known) → Start Batch
   → Complete Batch (expected · actual · good · reject · packs · downtime · efficiency)
   → Completed Today → Shift Summary (= completed production) → CEC (= Shift Summary, or BLOCKED stated)
   → Tally (Stock Journal per shift, released by shift-end + idle; agent ack; snapshot)
B · ACCOUNTING TRACEABILITY
   Purchase (PO → GRN → lot) → Inventory (ledger == balance) → Production consumption
   [PO→Tally only if the Q35 live-write gate passed]
B2 · STORE → PRODUCTION MATERIAL FLOW (Phase 7.5, the lead's 17-Aug correction)
   Production Request → Store Issue → Bag Scan → Production Receipt
   → Batch Consumption → Remaining / Return → Stock reconciliation
   with Store Stock / Issued-to-Production / Consumed kept DISTINCT at every step
D · CONFIGURATION LIFECYCLE (Phase 7.6)
   Every applicable master: Create → View → Edit → Activate/Deactivate → Safe Delete
   → Audit, with delete REFUSED (backend-enforced, explained with counts) once used
C · SALES VISIBILITY + DOWNLOADS
   Sales documents traced to their Tally entries (Layer A; Layer B stated) →
   one export per kind from the Center, FC-06 on every file
```

| Task | |
|---|---|
| **P8-01** Chain script: a documented, repeatable walk with named fixtures (dev DB, never live), one representative product through every link of A, then B, then C | |
| **P8-02** Assertions at each link — the transaction model, not the screen: configuration answers every Shift Floor question; expected pieces from the recorded standard; the completed record's figures; Completed Today == completed batches; Shift Summary totals == completed production; the shift voucher contains exactly the approved entries; stock movements sum to balances; each export equals its list | |
| **P8-03** Sonnet independent QA runs the walk **without** the implementer's notes and reports per link: PASS / FAIL / NOT TESTED / BLOCKED | |
| **P8-04** Browser proof at every link (screenshots + the API responses behind them), attached to the phase log | |
| **P8-05** Full-application regression across every adopted module (prompt §111): login · roles · masters · products · purchase · inventory · production · sales · reports · downloads · Tally | |
| **P8-07** **Chain B2 proven end to end:** Production Request → Store Issue → Bag Scan → Production Receipt → Batch Consumption → Remaining/Return → stock reconciliation, with the three states never collapsed into one (an issue is not a consumption) | walk + assertions |
| **P8-08** **The Configuration Lifecycle Contract holds** for every master rated applicable: the matrix is all PASS, delete-when-used is refused by the BACKEND with an explanation, and no duplicate active code can be created. **Phase 8 cannot be called PRODUCT READY while a material configuration gap remains** | the matrix, green |
| **P8-06** Release readiness (prompt §86 kept): no P0, no unresolved P1 data-integrity issue, all migrations accounted for, all tests green, production build passes, browser smoke passes, sync dry-run passes, rollback documented, monitoring ready. `DEVELOPMENT-PLAN.md` brought current or explicitly superseded by this file | |

**Verdict rule:** any link FAIL or NOT TESTED → the phase is `NOT READY`, full stop. A
link that is BLOCKED by a named owner gate (CEC sample, Q35 live-write, Q33 490/box) is
recorded as BLOCKED and the chain result is `PASS WITH OWNER-GATED ITEMS` — the product
is **not called complete** while a BLOCKED link remains.

### HELD — needs the owner before a line is written

| Item | Question |
|---|---|
| Sales lifecycle in ERP | New decision superseding DEC-20260809-003 |
| CEC format | A sample + format authority; none exists. **The Download Center ships CEC's slot visibly BLOCKED** (Phase 4.5); Phase 5.7 builds everything around it — never an invented layout |
| ERP↔Tally reconciliation by reading Tally | Q36; and a decision that a deliberate read is wanted |
| SKU format programme | Format confirmation; agent HSN fetch first (does not exist) |
| 490/box variant, ambiguous SKU→Tally mappings | Q33; Q43 (block or warn on duplicate names) — the ERP holds the capacity (Phase 5), the owner supplies the mapping |
| First live PO write to Tally | Q35 — never unattended |
| Committing XML/exports to the repo | Q31, Q38 |
| Finance / CRM surfaces | DEC-20260812-001 |
| ERP sales-document lifecycle defaults | Q44 |
| Bag → batch / machine consumption provenance (Phase 7.5) | Collides with **FC-01**; either a new owner decision superseding it, or consumption traces to the ISSUE and bag identity stops at the store handover |
| Merging any two masters that already carry transaction history (e.g. the duplicate warehouses) | Explicit evidence + an owner decision; never an agent's call |

---
