# PET Bottle Production → Tally Sync — Master Plan

Reconciles `TALLY-PRODUCTION-SYNC-PLAN.md` (original architecture plan) and `PET-Production-Tally-Sync-Brief.md` (PET-specific business brief, sharper on several decisions) into one current document. Those two are now **superseded by this file** — treat it as the single source of truth going forward so the two don't quietly drift apart.

Companion to `TECHNICAL-DOCS.md` §6 (base Tally integration architecture already built) and `DEVELOPMENT-PLAN.md` (overall phase sequencing).

---

## 0. Status — what's actually built vs. planned

| | Status |
|---|---|
| Outbound Tally queue (Sales Invoice, Journal Entry → `tally_sync_entries`) | **Built** — `App\Modules\TallySync`, one-way, cloud-side only |
| Shift / Machine master data (`Shift`, seeded Morning/Afternoon/Night) | **Built** |
| Batch-lifecycle shop-floor capture (`ShiftProductionEntry` start/complete, Shift Floor page, approval gate) | **Built** — Phase 2a, see §10 |
| Idle Time Report (downtime, mold-change, power interruption, stock count logging) | **Built** — Phase 2b, see §10 |
| Shift KPI Summary (computed rollup: Efficiency %, Rejection %, Machines Down, Idle Time, Mold Changes) | **Built** — Phase 2a/2b, see §10 |
| Local agent (tray app) | **Scaffolded** — `tally-sync-agent/` (Electron/TS). Poller, cloud API client, Tally response parsing, tray/settings UI, and packaging all work end-to-end. XML voucher builders are unvalidated templates — see that project's README |
| Production → Tally voucher sync | **Not started** — blocked on Phase 2 (real data model) and real-Tally validation of the agent's XML |
| Masters pull from Tally (items, godowns) | **Not started** |
| Batch/QR generation + label printing | **Not started** |
| Dispatch scanning | **Not started** |
| Dashboard (wastage, yield, sync-health) | **Not started** |

---

## 1. The business problem (confirmed specifics)

- **Business:** PET bottle manufacturing unit, Puducherry, India. 10 machines running, various items.
- Production is currently hand-entered into Excel across shifts; the accountant does intermediate calculations there and posts to Tally **the next morning** — a full day's lag between real production and what Tally shows.
- Cartons are labelled **by hand** (date/month + "packed by" name written on).
- **"Real-time" is defined as shift/hour granularity, not millisecond** — the actual target is "Tally reflects inventory at shift close," a large improvement over next-day, not a live telemetry feed.
- **Process (confirmed):** single-stage, single cycle — resin → bottles → packed into cartons **within the same shift** → warehouse. Bottles are never carried as loose inventory across shifts; nothing sits half-finished overnight.

---

## 2. Core architecture decision

> **Tally is not the real-time hub. Our own database is.** Tally is the downstream accounting/inventory ledger we sync *to* at shift close.

Tally is a desktop app that can't push events and may be offline at any given moment — building live dashboards or real-time logic on top of it directly is a losing battle. Making our own database the source of truth means:
- Real-time capture and dashboards are fully in our control, independent of whether Tally or the sync agent is even running at that moment.
- Tally sync becomes a **queue-and-retry (eventual consistency) problem** — the same shape the existing `TallySync` module (§ built) already uses for Sales Invoices and Journal Entries. Production sync extends that same pattern rather than inventing a new one.

**Four layers:**

| Layer | Role | Difficulty | Status |
|---|---|---|---|
| 1. Capture | Operators log production per shift/machine into the app | Easy | **Built** (needs extension, §4) |
| 2. Our database | Source of truth for production; feeds dashboards & notifications | Standard | **Built** |
| 3. Sync engine | Local agent near Tally, outbound queue, pushes on shift close, polls masters | **Specialized / highest technical risk** | Not started |
| 4. Tally | Accounting & inventory ledger | — | Existing, untouched |

---

## 3. Tally integration mechanics

### The hard constraint (unchanged from the original plan)
Tally exposes a built-in **HTTP-XML gateway on port 9000**, local to whatever machine Tally runs on — not cloud-reachable. Enabled via `F1 → Settings → Advanced Configuration → HTTP Server`. This is why every sync direction routes through a **local agent** (§11), never a direct cloud-to-Tally call.

### Mechanics
- Three action types: **export** (read), **import** (write), **execute**.
- XML structure: `ENVELOPE → HEADER → BODY`. JSON has been supported since Tally Release 7.0 — worth checking whether JSON is viable for the customer's version, since it's meaningfully easier to work with than hand-built XML.
- POST to `http://<Tally-IP>:9000`. The target company must be **loaded** in the Tally UI for requests to resolve.

### Practical gotchas (from real integration experience — treat as load-bearing)
- **Reverse-engineer the XML by example, don't hand-write it from the docs.** Create the exact voucher you want in Tally's own UI, export it as XML, and copy that tag structure into the request template. This applies to every voucher type we add (Manufacturing Journal, and eventually Delivery Note/Sales for dispatch).
- **Errors hide inside HTTP 200.** Tally returns 200 even when a voucher import fails — the real result (`LINEERROR`, created/altered/ignored counts) is in the response body. The agent must parse the body on every request, never trust the status code alone.
- **Incremental sync uses AlterID, not timestamps.** Every master and voucher in Tally has a monotonically increasing `AlterID` plus a stable `GUID`/`MasterID`. Pulling masters means tracking "last AlterID seen" and requesting only what's changed since — masters and vouchers are tracked as separate AlterID sequences.
- **Idempotency is mandatory.** Every write must be safe to retry — a network blip after Tally accepted a voucher but before our agent got the ack must never double-post that shift's production. Use a stable, deterministic voucher reference per source entry (matching the existing `SPE #{id}` / `WO #{id}` convention already used elsewhere in `TallySync`) so a retried import is recognizably the same voucher, not a duplicate.

### Split ownership by entity (key decision — avoids the conflict-resolution swamp)
True field-level bidirectional sync (an item editable in both systems, merge conflicts resolved automatically) is a materially harder problem than this project needs. Instead, split by entity type into two clean **one-directional** flows:

- **Masters flow DOWN, Tally → app:** stock items, BOMs, godowns/locations, ledgers, units. Pulled via AlterID polling, cached locally in our DB. Tally remains the source of truth for these; the ERP-side UI treats Tally-sourced fields as read-only (matches the original plan's item-matching recommendation — see §4 for the concrete schema note).
- **Production flows UP, app → Tally:** shift output → **Manufacturing Journal**, one per shift/line (§4).

"Bidirectional sync" reduces to two independent one-way pipelines, each of which is individually simple.

---

## 4. Production data model — what's built, what the real model needs

### What Phase 1 shipped (`ShiftProductionEntry`)

Fast capture, deliberately lighter than a full costed Work Order: `shift_id`, `work_center_id` (machine), `item_id`, `warehouse_id`, `quantity_produced`, `quantity_scrap`, `scrap_reason_id`, `operator_id`, `notes`. Submitting immediately receives the produced quantity into stock through `StockMovementService`, referenced `SPE #{id}`.

This is the right shape for **"log what came off the machine in five seconds"** and is already live. It is **not yet** the shape needed to generate a correct Tally Manufacturing Journal for PET bottle production specifically — that needs more structure, detailed next.

### What the real PET production model needs

Because bottles are packed same-shift (§1), the model collapses to **one Manufacturing Journal per shift per line**, not a chain of separate transfer vouchers. That single voucher needs:

- **Consumption:** PET resin + masterbatch/additives — **actual issued quantities (kg)**, not a BOM-standard figure. This is new: `ShiftProductionEntry` today records no raw-material input at all (Option A deliberately deferred costed consumption — see the original plan's §4 rationale). PET wastage tracking specifically needs this captured per shift.
- **Production:** packed cartons as the finished SKU (Nos), produced **directly into the warehouse godown** — already how `ShiftProductionEntry` behaves (no separate transfer step needed, consistent with the confirmed single-cycle process).
- **Scrap, split into two kinds** (currently just one undifferentiated `quantity_scrap` field):
  - **Regrind** — recoverable, re-enters stock as a valued item.
  - **Non-recoverable loss** — purge, contamination, burnt lumps.
- **Batch number** — generated once at packing (`LINE-SHIFT-YYYYMMDD-seq`), becomes the thread tying the carton QR label, the Tally voucher's batch/mfg-date fields, and the dispatch-scan reference together (§6). Not modeled at all yet.
- **Approval gate status** (`pending → approved → synced`) — see §4a. Not modeled at all yet; today, submitting a `ShiftProductionEntry` writes stock immediately with no review step.

### Headline cost metric

```
Resin wastage = actual resin consumed − (bottles produced × nominal gram weight)
```

Over-weight bottles quietly burn resin — likely the single biggest cost leak in PET production, so this gets top billing on the dashboard (§7) once raw-material consumption is captured.

*Future option, not needed now:* if blow-molding wastage ever needs separating from packing wastage, or bottles need to carry as loose inventory across shifts, split into two chained Manufacturing Journals (resin→bottles, then bottles+cartons→packed carton). The confirmed single-cycle process doesn't need this today — don't build it speculatively.

### 4a. The human approval gate (decision)

Do **not** auto-fire vouchers into Tally the instant a shift ends. Instead:
1. At shift close, the app creates a **pending** entry with all calculations pre-done (resin wastage, regrind credit, cost).
2. The accountant reviews and approves in one click.
3. Only the approved entry syncs to Tally.

This preserves the same control the accountant has today (minus the retyping and the day's lag), gives a clean idempotency boundary (only *approved* entries are ever eligible for sync — nothing in "pending" state can be double-processed), and **is the access-control point that compensates for Tally's gateway having no authentication of its own** (§5).

### 4b. The real project risk isn't the sync plumbing

The reason the accountant currently needs "next-morning work" is that the Excel sheet encodes **undocumented logic** — how regrind is credited back, how shared resin across items is split, rounding rules, valuation method. Extracting this logic from the accountant's head and codifying it once is harder and more important than the Tally connector itself. **Do this first, or in parallel with early Phase 2 work — not after.** See §9 and §10 for how this affects sequencing.

### Concrete schema extension needed (Phase 2)

Rough shape — to be refined once the Excel-logic interview (§9) is done, since that will reveal exact fields needed for valuation/rounding:

```
shift_production_entries  (extend existing table)
  + status                  pending | approved | synced | failed   (default: pending)
  + approved_by             → users, nullable
  + approved_at             nullable
  + batch_number             string, nullable — generated at packing

shift_material_consumption   (new — one row per raw material issued in a shift entry)
  id
  shift_production_entry_id  → shift_production_entries
  item_id                     → items          (resin, masterbatch, additive)
  quantity_issued             decimal (kg)

shift_scrap   (new — replaces the single quantity_scrap + scrap_reason_id pair)
  id
  shift_production_entry_id  → shift_production_entries
  type                        recoverable_regrind | non_recoverable
  quantity                    decimal
  scrap_reason_id             → scrap_reasons, nullable
```

`quantity_scrap`/`scrap_reason_id` on the existing table can stay for backward compatibility with anything already logged, or be migrated into `shift_scrap` rows — decide once real data exists to migrate.

---

## 5. Security (Tally gateway) — load-bearing, not optional

> **Tally's port-9000 gateway has essentially no authentication of its own** — no API key, no token, no username/password anywhere in the XML protocol. Whoever can reach the port can read ledgers and post vouchers.

- **Never expose port 9000 to the public internet.** LAN/firewall only.
- **TallyVault and Tally's user roles do not gate the XML gateway.** TallyVault encrypts data at rest; user roles govern interactive login. The gateway acts on whatever company is loaded, independent of those credentials.
- Treat port 9000 as an **unauthenticated local socket** in every design decision from here on.

**Where security actually comes from, given that:**
1. Tally bound to **localhost only** (or a locked-down LAN segment) — the agent runs on the same machine, or one that can reach `127.0.0.1:9000` / a trusted LAN address.
2. **The web app never talks to port 9000 directly, ever.** The agent makes **outbound, authenticated** calls to our cloud API (the existing Sanctum-scoped-token pattern) — all real authentication lives on that hop, not on the Tally side.
3. If remote access to the Tally machine is ever unavoidable, use a **tunnel** (Cloudflare Tunnel, VPN) with auth enforced on the tunnel itself, never relying on Tally.
4. The agent keeps its own **audit log** (which voucher, from whom, when — see §11) and the **shift-close approval gate (§4a) is the actual access-control checkpoint**, since Tally itself provides none.

---

## 6. Batch traceability & barcoding

**Principle: the batch number is the single thread.** Generated once at packing, the same code becomes:
1. The QR printed on the carton,
2. The batch name + mfg date on the Tally Manufacturing Journal,
3. The reference scanned at dispatch.

Result: Tally gives batch-wise stock/movement natively, and the whole chain is traceable end to end. This replaces both the handwritten date and the handwritten "packed by" name (which becomes automatic from the logged-in shift entry's operator field).

**Use QR, not a linear barcode** — more data density, and every modern phone/scanner reads it. Encode:
- Batch number — `LINE-SHIFT-YYYYMMDD-seq`
- Product SKU + mfg date (+ use-by, if applicable)
- Shift, line, and operator (auto from the shift entry — no more hand-written "packed by")
- Carton count / sequence number

### Batch-level vs. per-carton serial

- **Batch-level:** every carton in a shift shares one code. Simple, sufficient for basic recall/lot labelling.
- **Per-carton serial (recommended):** each carton gets a unique ID (batch + running number). Enables scan-verified truck loading, exact "which carton went on which truck" traceability, and short/over-shipment detection at dispatch. Small extra cost for meaningfully more capability at the point it matters most (shipping).

**Dispatch flow:** scan cartons onto the truck → app assembles the dispatch → posts to Tally as a Delivery Note / Sales voucher carrying the batch reference.

**Compliance note:** batch + mfg date on the carton is likely an FSSAI labelling requirement for this product category — confirm against current rules before finalizing the label template; this doubles as a compliance deliverable, not just traceability.

---

## 7. Dashboard & notifications

Both read from **our own database**, so they're live at shift close regardless of whether Tally sync has run yet or is currently failing.

**Dashboard metrics:**
- Units produced (bottles and cartons)
- Wastage, broken out: resin-weight variance (§4's headline metric) + rejected bottles + packing rejects
- Raw materials consumed: resin, additives, **and cartons/film** — not resin alone
- Yield
- **Sync-health panel:** queued / failed voucher counts (this already has a home — the existing `/tally-sync` retry dashboard — extend it rather than building a second one)

**Notification triggers:**
- Batch complete
- Shift close
- Yield / variance threshold breaches
- Tally sync failures

**Channels:** in-app, email, SMS, WhatsApp.

---

## 8. Hardware & printing

### Printing approach: app sends raw ZPL directly, no label design software

Printers speak **ZPL/TSPL** over network port **9100** (or USB). Build the label template once; from then on the app fills it and sends it directly — no BarTender/NiceLabel, no driver, no manual date entry. Operator's entire interaction: enter shift data → tap **Print**. This is what makes it workable for a non-technical shop floor — all complexity is hidden behind the app.

### How many printers

Driven by **packing points**, not machine count — a desktop printer does a label in ~1–2 seconds (hundreds/hour), so throughput is rarely the constraint; the real enemy is operators *walking* to fetch labels.

- **Centralized packing** (likely, given the confirmed single-cycle process): **1–2 printers** + 1 pre-configured spare.
- **Packing spread across the floor:** zone the machines — one printer per 2–3 adjacent machines (~3–4 total).
- **Dispatch/warehouse:** no printer needed for normal flow (scanning existing labels); add one only if pallet/dispatch-level labels are wanted later.

### Setup rules for a low-skill environment

1. Networked, **app-driven** printers with **static IPs** — not USB-tethered per machine.
2. Keep a **pre-configured spare** — swap = plug in, point the app at its IP.
3. **One label size, one ribbon type** across every printer — a single consumable to stock.
4. Printers assigned per station **in the app, once** — the operator only ever sees a "Print" button.
5. App-side **failover** — reroute a job to the nearest printer if one is down.
6. Dispatch-dock scanning must work **offline + queue** — dock network connectivity is reliably flaky.

### Cost (current India pricing, for budgeting)

| Item | Spec | Price (₹) |
|---|---|---|
| Label printer | Thermal-**transfer** (ribbon), desktop, 4", 203dpi — TSC TE210 / TVS LP46 / Zebra ZD230 | ~14,000–25,000 |
| — avoid | Direct-thermal (fades/smudges on cartons over time) | — |
| — overkill | Industrial metal-chassis printers (Zebra ZT411/ZT610, SATO CL4NX) | 1,00,000+ |
| Scanner (2D, QR), wired | | 3,000–8,000 |
| Scanner (2D, QR), wireless/Bluetooth — recommended for dispatch | | 6,000–15,000 |
| Scanner, rugged Android handheld (optional, later) | | 20,000+ |
| Consumables | Label rolls + wax/resin ribbon (ribbon is the recurring cost) | few hundred/roll |

**Starter budget:** ~₹20,000–35,000 for one printer + one wireless scanner + starter consumables. Buy from a local Chennai/Puducherry dealer for on-site service + AMC — this matters more than shaving a few thousand rupees off a marketplace price.

---

## 9. Effort & risk assessment

| Component | Complexity | Notes |
|---|---|---|
| Shift-capture app, DB, dashboard, notifications | Moderate | Standard web-app work — mostly extending what's already built |
| Barcode/QR printing (app → ZPL) | Low | Template built once |
| Dispatch scanning | Low–Moderate | Needs an offline queue |
| **Tally connector / sync engine (the tray app)** | **High — the main technical risk** | Local agent, idempotent writes, AlterID deltas, scrap/variance mapping into Tally's voucher shape |
| **Codifying the accountant's Excel logic** | **High — the main hidden risk** | Not a technical problem at all — an extraction/documentation problem. Easy to underestimate because it doesn't look like "real work" until you're blocked on it. |
| Split-ownership bidirectional design (§3) | Manageable | Would be a genuine swamp if attempted as true field-level bidirectional sync instead |

- **Proof of concept** (read a ledger from Tally, post one voucher): 1–2 days once the tray app skeleton exists.
- **Production-grade sync engine** (agent, AlterID deltas, retries, mapping, idempotency): weeks, not days. This is the most commonly underestimated piece of a project like this.

---

## 10. Phased build order

Supersedes both source documents' phase lists — this is the one to follow.

### Phase 0 — Decisions ✅ Done
Option A capture model (lightweight `ShiftProductionEntry`, not a full costed Work Order per shift); 3 shifts seeded (Morning 06:00–14:00, Afternoon 14:00–22:00, Night 22:00–06:00).

### Phase 1 — Fast shift/machine capture MVP ✅ Done
`Shift`, `ShiftProductionEntry`, "Log Production" page. Operators can log output in seconds; it hits stock immediately. See §4 for what it doesn't yet cover.

### Phase 1.5 — Codify the Excel calculation logic (do this next, in parallel with Phase 2 build)
Interview the accountant. Document every rule: how regrind is credited back, how shared resin across concurrent items is split, rounding conventions, valuation method. Write it down as explicit, testable business rules before encoding any of it — per §9, this is the highest hidden risk in the whole project and has nothing to do with the Tally plumbing.

**Exit criteria:** a written spec of the wastage/regrind/valuation logic that the accountant has reviewed and signed off as matching what they currently do by hand.

### Phase 2 — Real production data model + approval gate + dashboard

Expanded after checking the actual paper forms in use (`SWAASHPET POLYMERS PVT. LTD.` Production Report + Idle Time Report) against the schema — the gap was bigger than the original sketch. Split into two sub-phases: 2a extends what's already built and is the more urgent piece; 2b is a genuinely new data domain (downtime/changeover tracking) that didn't exist in this plan until the paper forms were checked.

**Companion doc:** `PRODUCTION-SUPERVISOR-UX-PLAN.md` covers the frontend shape of Phase 2 in detail — the mobile-first screen-by-screen flow, the batch lifecycle behind "production is entered once a batch completes," the read-only/computed field rules, and (§2 of that doc) how the design handles multiple supervisors/helpers working the floor ad hoc with no fixed machine assignment. The schema notes below have been updated to match its two backend-relevant conclusions: a batch-lifecycle status on `shift_production_entries`, and concurrency guards + `created_by` on every Phase 2b log.

#### Phase 2a — Match the Production Report + shift KPI summary

**Schema:**

```
items  (extend)
  + nominal_weight_grams   decimal, nullable
    — the "WT" column on the paper form; needed for the resin-wastage
      formula (§4) and to derive Kg figures from unit counts.

shift_production_entries  (extend)
  + batch_status              in_progress | completed   (default: in_progress)
    — the batch lifecycle, distinct from `status` below. A row is created at
      "Start Batch" (work_center_id + item_id only, quantities null,
      in_progress) and filled in + flipped to completed at "Complete Batch" —
      see UX doc §1. Nothing here changes the existing fact that this table
      already allows multiple rows per machine per shift (no unique
      constraint forces one-row-per-machine) — that's exactly what's needed
      now that one machine can run several different items per shift,
      separated by mold changes (UX doc §1).
  + batch_number              string, nullable
  + quantity_produced_kg      decimal, nullable   — Kg alongside the existing Nos figure
  + quantity_rejection_kg     decimal, nullable   — ditto for rejection
  + nos_per_tray, no_of_trays, nos_per_box, no_of_box   — packing counts, all integer/nullable
  + supervisor_signed_by      → employees, nullable, audit-trail only (see note below)
  + supervisor_signed_at      nullable
  + plant_manager_signed_by   → employees, nullable, audit-trail only
  + plant_manager_signed_at   nullable
  + status                    pending | approved | synced | failed   (default: pending)
    — separate concern from `batch_status` above: this is the accountant's
      Tally-sync approval workflow, not whether the batch itself is done running.
  + approved_by               → users, nullable   — the accountant's sign-off, the real gate (§4a/§5)
  + approved_at                nullable

shift_material_consumptions   (new — one row per raw material/masterbatch issued against one entry)
  id, shift_production_entry_id → shift_production_entries
  item_id            → items   (resin or masterbatch — already separate Items, e.g. MB-CLEAR/MB-BLUE)
  quantity_issued_kg  decimal

shift_scraps   (new — replaces the bare quantity_scrap/scrap_reason_id pair)
  id, shift_production_entry_id → shift_production_entries
  type              rejected_finished_good | lumps    — see open question below, needs Phase 1.5 confirmation
  quantity_nos       decimal, nullable
  quantity_kg        decimal, nullable
  scrap_reason_id    → scrap_reasons, nullable

shift_summaries   (new — one row per shift, plant-wide, not per machine)
  id, shift_id, production_date, supervisor_id → employees
  target_production_kg      decimal, nullable   — genuinely new, no existing source (§ shift KPI analysis)
  power_consumption_units    decimal, nullable   — genuinely new; distinct from Form 2's power *interruption* log
  remarks                    text, nullable
  created_by                 → users
    — with no fixed lead supervisor per shift (confirmed: floor staff work
      any machine ad hoc, not a zone assignment), this is whoever taps
      "Close Shift," gated behind a permission rather than open to any
      shift-floor login — see UX doc §2. `supervisor_id` above is a manual
      pick at shift start, independent of who ends up closing it.
```

**Note on the two floor-level signatures:** the paper form has *three* sign-off points (Production Supervisor, Plant Manager, then implicitly the accountant before anything reaches Tally). v1 records supervisor sign-off as **implicit** — whoever is logged in and submits an entry *is* that sign-off, no separate step. Plant Manager sign-off was originally modeled as audit-trail only too, but now reads more like a real second gate: the Plant Manager is a *different* person reviewing *after* the fact, which is a genuine second approval, not just a record. Revised open question (see §12): confirm whether Plant Manager review should block a shift from reaching the accountant's queue (Supervisor submits → Plant Manager approves → Accountant approves → syncs to Tally), or stay non-blocking as originally assumed. Either way, the accountant's approval remains the one hard gate before Tally sync, per §4a/§5.

**Backend:**
- `ShiftProductionEntryService::create()` extended to accept the new fields and write the `shift_material_consumptions` / `shift_scraps` child rows in the same transaction as the entry (same pattern `WorkOrderService` already uses for its own materials/scraps).
- New `startBatch()` / `completeBatch()` methods driving `batch_status`. Both need a **concurrency guard**, not just a status field: the write is a single atomic `UPDATE ... WHERE batch_status = 'expected_prior_value'` (or an `updated_at` check) inside the transaction, so a second simultaneous tap on the same machine (e.g. two people both hitting "Complete Batch" on the same entry) affects zero rows and the service returns a clear "already completed by X" instead of a silent double-write or a lost update. Real requirement, not a nice-to-have — confirmed multiple people work any of the 10 machines ad hoc in one shift (UX doc §2), so this isn't a rare edge case.
- New `submitForApproval()` / `approve()` / `reject()` methods — status transitions, `approve()` is the only path that will eventually trigger Phase 4's Tally enqueue.
- New `ShiftSummaryService` — CRUD for the once-per-shift record, plus a `report(date, shiftId)` method computing: Actual Production Kg / Rejection Kg (summed from that shift's entries), Efficiency % and Rejection % and Net Good Output (arithmetic), Machines Running/Down + Idle Time + Mold Changes (from Phase 2b's tables once they exist — `0`/empty until then), Unit/Kg (from `power_consumption_units` ÷ actual Kg). `closeShift()` gated behind a dedicated permission (e.g. `production.shift.close`) rather than open to any shift-floor login, per UX doc §2.
- New endpoints: start batch, complete batch, pending-approval list, approve, reject, shift summary CRUD, close shift, shift KPI report.

**Frontend:**
- Items page: add "Nominal Weight (g)" field to the create/edit form.
- "Log Production" page: add Batch Number, packing counts, a resin/masterbatch consumption line-entry section (mirrors how Work Orders' material lines already work), Lumps quantity. Kg figures can auto-compute from `quantity_produced × nominal_weight_grams`, with the operator able to override.
- New "Approve Production" page (the accountant's queue — per-entry approve/reject, matches the master plan's original §4a design).
- New "Shift Summary" page — supervisor enters Target Kg / Power Units / Remarks once per shift, and sees the computed KPI report (the fields from the earlier shift-KPI breakdown) for that date+shift.

**Exit criteria:** a shift's production, once approved, has a fully-computed record (resin wastage, Kg-based rejection, cost) ready to become a Tally voucher; the Shift Summary page shows every field from the paper form's KPI sheet, computed rather than hand-calculated.

#### Phase 2b — Idle Time / Downtime tracking (new domain, from the Idle Time Report) ✅ Built

Didn't exist in this plan before the paper forms were checked — zero prior coverage. Kept separate from 2a because it's a different data domain (machine downtime, not production output) and separable enough to sequence independently if needed.

**Built as designed, with three small refinements made during implementation:**
- `mold_change_logs.changed_from`/`changed_to` shipped as real `item_id` foreign keys, not the plain strings originally sketched — consistent with how every other item reference in the app works, and makes "which items get swapped together" a queryable report instead of free text.
- `mold_change_logs` also got the `open | closed` lifecycle (the schema note above already called this out, the original table sketch below just hadn't been updated yet) — mirrors downtime: both items are known up front, only the clock is running between "Log Mold Change" and "Finish Mold Change".
- All `from_time`/`to_time` columns are full `datetime`, not bare `time` — the Night shift (22:00–06:00) crosses midnight, and a time-only diff would go negative for anything spanning it. `total_minutes`/`idle_hours` are always computed from the two timestamps, never a manual input, per the same read-only-when-computable rule used throughout Phase 2a.

`ShiftSummaryService::report()` now pulls real numbers: **Machines Down** is a live count of currently-open breakdowns (mirrors how Machines Running counts live in-progress batches); **Idle Time** sums only *closed* downtime logs, so the total doesn't visibly jump backward once an open one finally closes; **Mold Changes** counts all logged changeovers for the shift.

**Schema:**

All four tables below also get a `created_by → users` and a `status: open | closed` (downtime/mold-change logs only — power interruption and stock counts are single-shot, no open/closed lifecycle). With no fixed machine assignment (floor staff work whoever's nearest, confirmed — see UX doc §2), individual attribution on every log is what keeps the data trustworthy when several people are touching the same 10 machines in one shift.

```
machine_downtime_logs
  id, work_center_id → work_centers, shift_id, production_date
  nature_of_problem   string
  remedy               text, nullable
  parts_changed        string, nullable
  from_time, to_time    time
  total_minutes         decimal
  status                open | closed   (default: open — opened at "Report Down", closed once remedy is logged)
  created_by            → users

mold_change_logs
  id, work_center_id, shift_id, production_date
  changed_from, changed_to   string
  from_time, to_time
  total_minutes
  created_by                  → users

power_interruption_logs
  id, shift_id, production_date   — plant-wide (the paper form has no M/C No column here — see open question)
  from_time, to_time, idle_hours
  created_by                       → users

shift_stock_counts
  id, shift_id, production_date
  location_label   string   — "Hoppers" / "Day Bin" / "Loose Bag" / "Store" for resin, or a masterbatch colour
  item_id           → items
  quantity_kg
  created_by         → users
```

Deliberately **not** touching the core `Warehouse`/bin model for the resin/masterbatch stock-by-location data — `shift_stock_counts` is a lightweight periodic physical-count log, informational, not reconciled against `StockBalance`. Building real bin-level inventory tracking would be a much bigger change to the Inventory module's core service and isn't justified by what the paper form is actually asking for (a shift-end visual count, not transactional bin tracking).

"Machine-wise rejection reason" from the paper form doesn't need its own table — it's a report grouping `shift_scraps.scrap_reason_id` by `work_center_id` for a date+shift, using data Phase 2a already captures.

**Backend:** `MachineDowntimeLogService`, `MoldChangeLogService`, `PowerInterruptionLogService`, `ShiftStockCountService` — straightforward CRUD, same module pattern as everything else, `created_by` stamped on every write. `MachineDowntimeLogService::open()`/`close()` need the same atomic `WHERE status = 'expected_prior_value'` concurrency guard as `ShiftProductionEntryService::completeBatch()` above — two people can just as easily both try to close the same breakdown log. `ShiftSummaryService::report()` (2a) gets extended to pull Machines Down/Idle Time/Mold Changes from these once they exist.

**Frontend:** new pages — Downtime Log, Mold Change Log, Power Interruptions, Stock Count — likely worth their own "Shift Floor" nav grouping alongside Log Production and Shift Summary, since none of them belong under the existing Production sub-pages conceptually.

**Exit criteria:** the Shift Summary's Machines Down/Idle Time/Mold Changes figures are real (not zero/placeholder), and a supervisor can log a breakdown or mold change in under a minute, same speed goal as Phase 1's production capture.

#### Open questions before writing code (in addition to §12)

1. **Lumps vs. rejection** — is "Lumps" specifically non-recoverable material (purge/burnt), with "Rejection" covering defective finished units that *might* be recoverable/regrindable? My `shift_scraps.type` enum above is a best guess — confirm during the Phase 1.5 interview, don't build against a guess.
2. **"CT" on the Production Report** — still unconfirmed whether this is cycle time or cavity count. Affects whether it's a per-entry field or a Routing/WorkCenter-level attribute.
3. **Power interruption** — plant-wide (one grid feed) or per-machine? Modeled as plant-wide above based on the form's layout (no M/C No column in that section) — confirm.
4. **Floor sign-offs** — revised per UX doc §1: confirm whether Plant Manager review should be a real second gate (Supervisor → Plant Manager → Accountant, each blocking the next) or stay non-blocking/audit-trail-only as originally assumed. Supervisor sign-off itself no longer needs confirming — it's implicit in who's logged in and submitting.
5. **Shift-close permission scope** — confirm whether "Close Shift" should be restricted to a specific role (e.g. only supervisors, not shift helpers) or is fine as "any authenticated shift-floor user" — see UX doc §2.

### Phase 3 — Build the local agent / tray app — ✅ Scaffolded, ⬜ not validated
Framework decision: **Electron**, given the team's existing TypeScript fluency (.NET/C# `NotifyIcon` is the leaner long-term alternative if C# capacity exists later; Go/`systray` for the smallest possible footprint if that becomes a priority). Scaffolded at `tally-sync-agent/` — see that project's README for setup, packaging, and the precise list of what's unvalidated. Implements:
- Poll `GET /tally-sync/pending`, ack/fail — reuses the existing endpoints/Sanctum-scoped-token pattern already built for Sales Invoice/Journal Entry sync. **Working.**
- XML voucher building **by example** (§3's gotcha) — start with the two existing voucher types (Sales Invoice, Journal Entry) to prove the agent works against a real Tally instance before adding anything new. **Templates written, not yet validated against real Tally — this is the remaining work in this phase.**
- Response-body parsing (never trust HTTP 200 alone). **Working**, but the exact field names to check need confirming against a real Tally response.
- Local rotating audit log (§5's requirement). **Working.**
- Tray menu: sync status, "Sync Now," "View Logs," "Pause," "Settings," "Quit." Auto-start on login. **Working.**

**Exit criteria:** the agent, running against a real Tally instance, correctly syncs a Sales Invoice and a Journal Entry end to end, survives a restart, and its audit log shows exactly what was sent and what Tally returned. **Blocked on access to a real Tally instance** — everything that doesn't require one is done.

### Phase 4 — Production → Tally voucher sync
Add the Manufacturing Journal voucher type to `TallySyncService` (cloud side) and the agent's XML translator (local side). Triggered only by an **approved** `ShiftProductionEntry` (§4a) — never automatically at shift close.

**Exit criteria:** approving a shift's production in the app results in a correct Manufacturing Journal in a real Tally instance, including resin consumption, cartons produced, and regrind/loss split.

### Phase 5 — Masters pull from Tally
Agent-side AlterID-based export of stock items/godowns; new cloud upsert endpoint (`POST /tally-sync/items` from the original plan, extended for godowns too). Tally-sourced fields marked read-only in the ERP UI (§3's split-ownership rule).

**Exit criteria:** a new item created in Tally appears in the ERP within one agent poll cycle, without manual re-entry.

### Phase 6 — Batch/QR generation + label printing
Batch-number generation at packing (§6), QR content per spec, ZPL template + printer integration (§8). Start with per-carton serials per the recommendation.

### Phase 7 — Dispatch scanning
Offline-capable scan flow → Delivery Note/Sales voucher in Tally carrying the batch reference.

### Phase 8 — Notifications
Batch complete, shift close, yield/variance threshold breach, sync failure — in-app first, then email/SMS/WhatsApp.

### Phase 9 — Pilot, then roll out
Run Phases 2–7 against **one machine, one shift** first — catches XML/mapping/logic mistakes once instead of ten times over. Once a full day's cycle (log → approve → sync → confirm in Tally) is clean, extend to the remaining machines.

### Phase 10 — Security hardening & audit review
Formal pass over §5 before go-live: confirm Tally is bound to localhost/LAN only, confirm the agent's outbound-only posture, confirm the audit log actually captures what it needs to for a real incident review, confirm the approval gate can't be bypassed.

---

## 11. Explicitly out of scope

- **IoT/PLC/SCADA machine integration** — this is manual/quick-entry by an operator, not automatic machine telemetry. A much larger, separate future idea (`ERP-FEATURES.md` already lists it separately) — don't conflate the two.
- **Full shift roster/scheduling** — only a lightweight shift *label* is needed here, not who's assigned to which shift when.
- **True field-level bidirectional conflict resolution** — deliberately avoided via the split-ownership design (§3).
- **Chained multi-stage Manufacturing Journals** (separating blow-molding wastage from packing wastage) — not needed given the confirmed single-cycle process; revisit only if that process assumption changes.

---

## 12. Open questions to confirm

Merged from both source documents, deduped:

1. **Capture method:** do operators enter shift output by hand into the app, or is there any auto-pull from machines/PLCs planned later? Changes the capture layer's requirements substantially.
2. **Packing layout:** centralized (1–2 points) or at each of the 10 machines? Decides 1–2 vs. 3–4 printers (§8).
3. **Volume:** cartons per shift? Under a few hundred → the ~₹15k desktop printer tier is fine; thousands → move up a duty-cycle tier.
4. **Label unit:** label per carton, or per shrink-wrapped bundle/pallet?
5. **Serialization:** confirm batch-level vs. per-carton serial (recommended: per-carton).
6. **FSSAI:** confirm current carton labelling requirements for this product category.
7. **Tally BOM/Manufacturing Journal availability:** confirmed reachable via `F11 → Inventory Features` — but confirm the actual customer installation has "Enable Manufacturing Journals" available (some editions/versions may not); if not, fall back to a plain Stock Journal (no BOM auto-pull, raw material lines entered manually per voucher).
8. **Where the agent runs:** same machine as Tally, or another LAN machine that can reach `127.0.0.1:9000` / the Tally machine's LAN address reliably.
9. **Existing Sales/Procurement modules in this app:** stay unused since Sales/PO live in Tally, or is there a longer-term plan to migrate off Tally entirely? Doesn't block this project, but affects investment elsewhere in the app.

See also §10 Phase 2's "Open questions before writing code" — four more, specific to the Production/Idle Time Report field mapping (Lumps vs. rejection, "CT", power interruption scope, floor sign-offs).
