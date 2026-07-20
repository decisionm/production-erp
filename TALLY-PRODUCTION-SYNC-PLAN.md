# Tally Production Sync — Implementation Plan

Companion to `TECHNICAL-DOCS.md` §6 (Tally Integration architecture), `ERP-FEATURES.md`'s "Tally Integration (detail)" section, and `DEVELOPMENT-PLAN.md` (phase sequencing). This document plans one concrete next phase: closing the gap between when production actually happens on the shop floor and when it shows up as stock in Tally.

---

## 1. The problem, stated precisely

- Sales, Purchase Orders, and general accounting are done directly in **Tally** — this app is not replacing that, at least not yet.
- Production runs across **10 machines**, in **shifts**, making a variety of items.
- Today, shift output is written down on the floor and **entered the next morning** — into whatever system currently records it. Until that next-morning entry happens, **Tally's stock figures don't reflect what was actually produced**, so anyone checking finished-goods or raw-material stock in Tally during the day is looking at numbers that are up to a day stale.
- Goal: capture production **as it happens** (per shift, per machine, per item) in this ERP, and push it into Tally **automatically and quickly**, so Tally's inventory stays current without someone re-typing yesterday's shift log the next morning.

This directly answers the two things you asked about:

- **"Can we get items from Tally via API?"** — Yes, but not as a direct API call from our cloud app. See §2 for why, and §5 for how.
- **"Can we update inventory via API?"** — Yes, but "inventory" isn't a number we push — Tally is voucher-based, so we push a **production voucher** (a Manufacturing/Stock Journal) and Tally computes the resulting stock itself. See §5.

---

## 2. The hard constraint that shapes everything here

**Tally has no cloud API.** It exposes a local XML-over-HTTP interface on **port 9000**, on whatever machine Tally itself is running on — reachable only from that machine or its local network. This app is (eventually) cloud-hosted. Cloud and Tally **cannot talk to each other directly**, in either direction. This isn't a limitation of our app — it's just what Tally is.

That's why the existing `TallySync` module (already built — see §3) is a **queue**, not a live connection, and why every "sync" in this plan — pulling items in, pushing production out — has to go through a **local agent**: a small process that runs on-site, on the customer's network, that *can* reach `localhost:9000`, and that talks to our cloud API over the internet like any other client.

```
┌─────────────────────┐         internet         ┌──────────────────────┐        LAN        ┌───────────┐
│   Cloud (this app)   │ ◄──────────────────────► │   Local Sync Agent    │ ◄────────────────► │   Tally   │
│  MySQL, queue, API   │   poll / push (HTTPS)     │  (on customer's site) │   XML-HTTP :9000   │  Prime    │
└─────────────────────┘                            └──────────────────────┘                    └───────────┘
```

Everything in this plan is either "what the cloud side does" or "what the local agent does" — keep that split in mind throughout.

---

## 3. What already exists (built) vs. what's net-new

### Already built — `App\Modules\TallySync`

- A `tally_sync_entries` queue table. When a Sales Invoice is issued or a Journal Entry is posted, it's automatically enqueued (model-event listener — Sales/Finance have zero awareness `TallySync` exists).
- `GET /api/v1/tally-sync/pending`, `POST .../ack`, `POST .../fail` — the endpoints a local agent polls, authenticated by a **Sanctum token scoped to specific abilities** (`tally-sync:poll`, `tally-sync:report`), not a general-access token.
- A retry dashboard (`/tally-sync` page in the app) for entries that failed.
- The payload format is a clean intermediate JSON shape (ledger names, amounts, narration...) — **not** Tally's XML. Translating to XML is deliberately the agent's job, so the cloud side never needs to know which Tally version/dialect a customer runs.
- Direction today: **one-way, ERP → Tally, two voucher types only** (Sales Invoice, Journal Entry).

### Not built yet — this is the real gap

1. **The local agent itself doesn't exist.** It's fully spec'd in `TECHNICAL-DOCS.md` §6 but nobody has written it — there's been no real Tally instance to build/test against until now. This is the single biggest piece of net-new work in this plan, bigger than any of the ERP-side changes.
2. **No shift/machine production capture.** `WorkOrder` today has no concept of *which shift* or *which machine* produced something — see §4.
3. **No inbound direction.** Nothing pulls anything from Tally into this app today. Pulling the item master is new work on both the agent and the cloud side.
4. **No production voucher type.** `TallySyncService` only knows how to build Sales Invoice and Journal Entry payloads. A production/manufacturing voucher payload is new.

---

## 4. New: capturing shift + machine + item output

### The data model gap

`WorkOrder` (Production module) exists today but wasn't built for this. It has `item_id`, `warehouse_id`, `quantity_planned`/`quantity_completed`, and a `draft → released → completed` lifecycle — but no shift, no machine, and its lifecycle (release, consume BOM materials, complete, receive finished goods) is built for planned production runs, not for "operator taps in what came off machine 4 in the night shift."

`WorkCenter` already exists (`code`, `name`, `capacity_hours_per_day`) and maps cleanly onto your 10 machines — no new "Machine" entity needed, just use `WorkCenter` as-is (possibly rename its label in the UI to "Machine" if that reads more naturally on the shop floor).

**Shift** doesn't exist anywhere in the app yet. `ERP-FEATURES.md` already flagged "shift management & roster planning" as a future HRMS idea — this plan only needs the lightweight piece (a shift *label* and time window), not a full roster/scheduling system.

### Decision point: extend `WorkOrder`, or add a new lightweight entity?

This is the one architectural choice in this plan I'd want to confirm with you before building, because it changes the shape of everything downstream.

| | **Option A — new `ShiftProductionEntry`** (recommended) | **Option B — extend `WorkOrder`** |
|---|---|---|
| What it is | A new, deliberately small entity: date, shift, machine, item, quantity good, quantity scrap (+ reason), operator, notes. | Add `shift_id` + `work_center_id` to `WorkOrder`, and let operators complete work orders incrementally through a shift. |
| Speed of entry | Fast — 5-6 fields, designed for a tablet at the machine. No BOM/routing selection needed at entry time. | Slower — a `WorkOrder` today expects a BOM, a release step, then a complete step. Retrofitting "fast tap-in" onto that lifecycle fights its existing shape. |
| Costing accuracy | Looser — material consumption can be inferred from the BOM after the fact (batch-reconciled), not tracked per shift entry unless you also want operators picking exact raw material lots in real time. | Tighter — `WorkOrder::release()` already does proper BOM-scaled material issue, so cost is accurate line-by-line. |
| Risk to existing Production module | None — additive, existing `WorkOrder`/BOM/Routing/Capacity Planning flows are untouched. | Real — the existing release/complete lifecycle and its cost calculations are core to Production and used by MRP/Capacity Planning; changing its shape risks regressing those. |

**Recommendation: Option A.** The stated goal is closing a *speed* gap ("next day" → "same shift"), not a *costing precision* gap. A new `ShiftProductionEntry` gets fast capture without touching the existing, working `WorkOrder` costing logic. If tighter per-shift material costing turns out to matter later, `ShiftProductionEntry` can optionally *create* a `WorkOrder` behind the scenes (or link to one) as a second phase — that's a much smaller change once the fast-capture path already works and is proven on the floor.

### Proposed schema (Option A)

```
shifts                              — lookup: e.g. "Morning (6am–2pm)", "Afternoon", "Night"
  id, name, start_time, end_time, is_active

shift_production_entries
  id
  shift_id          → shifts
  work_center_id     → work_centers            (the machine)
  item_id            → items                    (what was produced)
  warehouse_id        → warehouses               (which FG store it lands in)
  production_date     date
  quantity_produced   decimal                    (good output)
  quantity_scrap      decimal, default 0
  scrap_reason_id     → scrap_reasons, nullable   (reuses the existing Production scrap-reason lookup)
  operator_id         → employees, nullable       (reuses existing HRMS Employee)
  notes                text, nullable
  created_by           → users
  timestamps
```

On save, this does exactly what `WorkOrder::complete()` already does for the finished-goods side: calls `StockMovementService::recordReceipt()` to add `quantity_produced` of `item_id` into `warehouse_id` — reusing the same Inventory service everything else in the app already goes through (see `CLAUDE.md` — cross-module writes always go through the owning module's service). No changes needed to `StockMovementService` itself.

### Frontend: shop-floor quick-entry

A single-purpose page, separate from the existing dense Work Orders table — designed to be usable on a phone or tablet standing at a machine:

- Big, tappable Machine picker (10 machines — fits on one screen, no search needed)
- Shift picker (3 options)
- Item picker (searchable, same `Select` pattern used everywhere else in the app)
- Quantity Produced / Quantity Scrap number inputs (numeric keypad on mobile)
- One "Log Production" button, form resets after submit so the next entry is immediate
- A running "logged this shift" list underneath for the operator's own confirmation, no need to leave the page

---

## 5. Sync direction 1: Items from Tally → ERP

### What "get items from Tally via API" actually looks like

The **local agent** (§6) periodically asks Tally's local XML API for the Stock Item list, and POSTs that list to a new cloud endpoint. The cloud side never talks to Tally directly — it only ever talks to the agent.

```
Local agent:  GET http://localhost:9000  (Tally XML export request for Stock Items)
              → gets back Tally's Stock Item list as XML
              → translates to clean JSON
              → POST https://<your-instance>/api/v1/tally-sync/items   (new endpoint, agent-authenticated)

Cloud side:   upserts into the `items` table
```

### The matching problem

Our `items` table is keyed by `sku`. Tally's Stock Items don't have a "SKU" field — they have a Name (and internally a GUID). Two options:

- **Use Tally's Stock Item Name as our SKU directly.** Simple, but only works if Tally's naming is already SKU-like (short, unique, stable). Worth checking against your actual Tally item list before committing to this.
- **Add a `tally_stock_item_guid` column to `items`**, matched on that instead, with SKU staying independently editable in the ERP. More robust (survives Tally-side renames), slightly more schema work.

**Recommendation:** add the GUID column — it's a small migration and avoids a class of bugs where renaming an item in Tally silently orphans it from the ERP side.

### Conflict resolution

`ERP-FEATURES.md` already flagged this as an open question, and it's real here: if an item is edited in both systems, who wins? Given Sales/PO happen in Tally, **Tally should be the source of truth for item existence and naming** — the ERP-side Items page should treat Tally-sourced items as read-only for `sku`/`name` (still fully editable for ERP-only fields like `tracking_type`, `reorder_level`, which Tally has no concept of), and new items should generally be created in Tally first, then pulled in. This avoids building real conflict-resolution logic for now, consistent with how `TallySync`'s outbound side already avoids it (see `TECHNICAL-DOCS.md` §6: "one-way... to avoid conflict resolution entirely").

---

## 6. Sync direction 2: Production output from ERP → Tally

### The voucher, not a raw number

Tally doesn't have a "set stock quantity to X" API — everything is a voucher, and stock changes are a side effect of posting one. The right voucher type here is Tally's **Stock Journal**, specifically its **Manufacturing Journal** flavor (a Stock Journal with a BOM attached) — it lets Tally itself record "consumed these raw materials, produced this finished item" as one transaction, which then flows into Tally's own stock and costing reports correctly.

**Important dependency to confirm early:** Manufacturing Journal requires Tally's own BOM feature to be turned on (`F11 → Features → Enable BOM`) and a matching BOM defined *inside Tally* for each finished item. If that's not already set up in the customer's Tally, either:
- get it set up there (mirrors, but doesn't need to exactly match, the BOMs already in this app's Production module), or
- fall back to a plain **Stock Journal** (no BOM) that just records the finished-goods increase, without Tally tracking the raw-material consumption side. Simpler, ships faster, gives you the finished-goods real-time visibility that's the actual stated goal, and raw-material relief in Tally continues however it happens today.

This is the single most important thing to verify with whoever manages Tally on-site before writing the XML translation — it decides which of the two payload shapes to build.

### Cloud-side change

Extend `TallySyncService` with `enqueueShiftProductionEntry()`, following the exact pattern `enqueueSalesInvoice()`/`enqueueJournalEntry()` already use — a new listener in `TallySyncEventServiceProvider` (so `Production` stays unaware `TallySync` exists, same rule the existing two follow), building a clean JSON payload (item, quantity, warehouse/godown, date, and BOM lines if going the Manufacturing Journal route).

### Agent-side change

A new payload-type branch in the agent's XML translation step, alongside the (also-new, see §7) Sales Voucher / Journal Voucher translators.

---

## 7. Building the local agent (the actual biggest piece of work)

This doesn't exist yet at all — everything above assumes it does. Per `TECHNICAL-DOCS.md` §6, it's a small standalone process (Node/PHP/Python — no strong preference) that:

1. Polls `GET /tally-sync/pending` on an interval.
2. For each entry, translates the JSON payload into Tally's XML voucher-import format and POSTs to `http://localhost:9000`.
3. Calls `.../ack` on success, `.../fail` with the error detail on failure — visible in the existing retry dashboard.
4. **New for this plan:** also runs an items-pull cycle (its own, probably slower interval — items don't change hourly) and posts to the new `/tally-sync/items` endpoint.
5. Authenticates with a Sanctum token scoped to abilities — extend the ability set with `tally-sync:items` alongside the existing `tally-sync:poll` / `tally-sync:report`.

**This needs a real Tally installation to build and test against** — it can't be developed or verified inside this codebase/session. It needs to happen on-site, or against a Tally instance the developer has real access to. Budget real calendar time for this: XML voucher formats in Tally are notoriously fussy (exact tag names/nesting per Tally version), and this will involve trial-and-error against the actual install rather than clean-room development.

**Polling interval and what "real-time" actually means:** the spec suggests 60–120 seconds today. That's already a huge improvement over "next morning," but it isn't instant. If shift-end urgency matters (e.g., a supervisor wants to see Tally reflect output within a minute of logging it), tighten the interval for production entries specifically — Tally's local API is lightweight enough to poll more frequently without issue. Set expectations with whoever's asking for "real-time" that this means *minutes*, not *milliseconds*.

---

## 8. Phased rollout

### Phase 0 — Decisions to lock in before writing code
- [ ] Confirm Option A vs. B from §4 (recommend A)
- [ ] Confirm shift definitions (how many, what hours) — likely just a 3-row seed, but needs the real answer
- [ ] Check whether the customer's Tally has BOM/Manufacturing Journal enabled — decides the §6 payload shape
- [ ] Decide the item-matching key (Tally Name as SKU vs. new GUID column — recommend GUID)
- [ ] Confirm where the agent will physically run (the same machine as Tally, or another machine on the same LAN that can reach `localhost:9000`/the Tally machine's LAN IP)

### Phase 1 — Shift/machine capture (pure ERP-side, no Tally involved yet)
- Migrations: `shifts`, `shift_production_entries`
- `ShiftService`/`ShiftProductionEntryService` (module pattern, per `CLAUDE.md`) — creation calls `StockMovementService::recordReceipt()`
- API: `GET/POST /v1/production/shifts`, `GET/POST /v1/production/shift-production-entries`
- Shop-floor quick-entry page (§4)
- **Exit criteria:** an operator can log a shift's output on a tablet in under 15 seconds, and it shows up as stock in this app immediately — no Tally involved yet.

### Phase 2 — Extend the outbound queue for production
- `TallySyncService::enqueueShiftProductionEntry()` + listener
- **Exit criteria:** every saved `ShiftProductionEntry` appears in the existing `/tally-sync` pending queue with a correct payload — verified by inspecting the queued JSON, since there's no agent to actually send it yet.

### Phase 3 — Build the local agent (the big one, needs real Tally access)
- Implement polling, ack/fail, and XML translation for the two *existing* voucher types first (Sales Invoice, Journal Entry) — proves the agent works at all against a real Tally before adding new voucher types
- Add the production-entry XML translator (Manufacturing Journal or plain Stock Journal, per the Phase 0 decision)
- **Exit criteria:** logging a shift's output in the app results in correct stock in a real Tally instance within one poll interval.

### Phase 4 — Items pull from Tally
- New `tally_stock_item_guid` column + migration
- `POST /tally-sync/items` endpoint + upsert logic + new Sanctum ability
- Agent-side XML export request + translation
- Items page: mark Tally-sourced items' `sku`/`name` read-only in the UI
- **Exit criteria:** a new item created in Tally appears in this app's Items list within one agent items-pull cycle, without manual re-entry.

### Phase 5 — Pilot, then roll out to all 10 machines
- Run Phases 1–4 against **one machine, one shift** first — cheapest way to catch XML/mapping mistakes before they're multiplied across 10 machines and 3 shifts
- Once a full day's cycle (log → sync → confirm in Tally) is clean, roll out the remaining machines
- Add a simple daily reconciliation view (ERP-logged production vs. Tally-confirmed-synced) so a mismatch is visible same-day, not discovered a week later

---

## 9. Explicitly out of scope for this plan

- **IoT/PLC/SCADA machine integration** — this plan is manual/quick-entry by an operator, not automatic machine telemetry. `ERP-FEATURES.md` already lists that separately as a much larger future idea; don't conflate the two.
- **Full shift roster/scheduling** — only a lightweight shift *label* is needed here, not who's assigned to which shift when.
- **Two-way conflict resolution for items** — handled by policy (Tally wins), not by building real merge logic.
- **Syncing Sales/PO/Invoices from this ERP** — those already flow ERP → Tally (Sales Invoice, Journal Entry) and aren't part of this production-sync gap; this plan doesn't touch that.

---

## 10. Open questions for you to confirm

1. Option A vs. B (§4) — do you agree fast capture matters more than per-shift costing precision for this phase?
2. Does the customer's Tally already have BOM/Manufacturing Journal enabled, or would that need setting up first?
3. What machine will the local agent run on, and does it have reliable always-on access to the Tally machine's `localhost:9000` (or LAN address)?
4. Are the existing Sales/Procurement modules in this app expected to stay unused (since Sales/PO live in Tally), or is there a longer-term plan to migrate off Tally entirely? This doesn't block this phase, but affects how much investment makes sense elsewhere in the app.
5. Exact shift definitions (names + hours) for the `shifts` seed data.
