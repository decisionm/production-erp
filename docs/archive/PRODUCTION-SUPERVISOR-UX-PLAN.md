# Production Supervisor Mobile UX — Shift Floor Data Entry

Companion to `TALLY-SYNC-MASTER-PLAN.md` §10 Phase 2 (the schema/backend plan). That document defines *what* gets stored; this one defines *how a supervisor actually captures it on a phone standing next to a machine*, screen by screen. Read §4 of the master plan first for field names and the paper-form gap analysis this builds on.

---

## 1. The real production flow — this isn't one form, it's a lifecycle

The earlier schema plan treated `shift_production_entries` as a flat "log what happened" record. The actual floor process is a **lifecycle**, and the UI should follow it, not fight it:

```
Machine idle
   │
   ▼
START BATCH  ──  pick Machine, pick Item (batch begins running)
   │
   ▼
[machine runs — nothing to enter while it's running]
   │
   ▼
COMPLETE BATCH  ──  enter the finished numbers (production, rejection,
   │                consumption, packing) — this is where Form 1's row
   │                actually gets filled in, once, at the end
   ▼
MOULD CHANGE  ──  logged as its own quick event (Form 2 §2) if the next
   │              item is different — the machine is idle during this
   ▼
START BATCH (next item) ── repeats, same machine, same shift
```

One machine can carry **several completed batches in a single shift** (different items, separated by mould changes). This is why "Complete Batch" needs to stay a fast, focused action — a supervisor might do it 3-4 times per machine per shift, ×10 machines.

**Schema refinement this implies** (small addition to Phase 2a's `shift_production_entries`): add `status: in_progress | completed`, created at **Start Batch** with just `shift_id` + `work_center_id` + `item_id` (everything else null), then filled in and flipped to `completed` at **Complete Batch**. This is what lets the Shift Console (§4) show "Machine 4: currently running BTL-HDPE-200" as a live fact, not something reconstructed after the fact.

**On the two floor signatures** (§10 Phase 2a flagged this as "audit-trail only, unconfirmed") — now that it's clear the **Supervisor is the one doing all this entry**, that signature is implicit: whoever is logged in and submits *is* the supervisor sign-off, captured automatically. The **Plant Manager signature is a different person, reviewing after the fact** — which means it plausibly deserves to be a real second gate after all (Supervisor submits → Plant Manager reviews/approves → Accountant approves → syncs to Tally), not just an audit note. Flagging this as a revised open question rather than deciding it here — confirm whether Plant Manager review is expected to block anything before it reaches the accountant.

---

## 2. Multiple supervisors in the field — ad hoc, no fixed machine split

Confirmed: on this floor it's not one supervisor per shift, and not a fixed zone split either — **several people (supervisor + shift helpers) can act on any of the 10 machines, whoever's nearest.** That changes three things from a single-user design:

**No "My Machines" filter.** Since nobody owns a fixed subset, everyone sees the same full 10-machine console (§4). Don't build machine-assignment — it would just be friction for a model that's genuinely ad hoc.

**The console has to show who else is already on it.** With several people roaming the same 10 machines, a card that just says "Running" isn't enough — add a small "last touched by [name], [time]" line to every machine card and inside each action sheet, so a second person glancing at ASB-3 can tell someone already logged it rather than duplicating the work. Since real-time push (WebSockets) isn't a given on this stack (see `TECHNICAL-DOCS.md` §8 — hosting is still an open decision), the console should refresh on screen focus and pull-to-refresh rather than assume a live socket; a light 15–30s poll while the console is open is a reasonable fallback if that turns out to matter in practice.

**Every state-changing action needs a concurrency guard, not just a UI affordance.** Two people can genuinely tap the same machine at the same instant:
- Two "Start Batch" taps on the same Idle machine → the second must fail with *"Already started by [name]"*, not create a second `in_progress` entry for that machine.
- Two "Complete Batch" submits on the same running entry → the second must fail with *"Already completed by [name] at [time]"*, not silently overwrite or double-count production.
- Same pattern for closing a downtime log or finishing a mould change.

  Mechanically: each of these is a `status` transition (§1's `in_progress → completed`, or a downtime log's `open → closed`). The backend write should be a single atomic `UPDATE ... WHERE status = 'expected_prior_status'` (or an `updated_at`/version check) inside the transaction, so a stale second submit affects zero rows and the service can tell the app "someone beat you to it" instead of applying a conflicting write. This is a small but real addition to Phase 2a/2b's service methods (`ShiftProductionEntryService::completeBatch()`, the downtime/mould-change services), not just frontend polish.

**`created_by` on every log, not just the main entry.** With multiple simultaneous actors, individual attribution is what makes the data trustworthy — `shift_production_entries` already has `created_by`; the Phase 2b tables (`machine_downtime_logs`, `mold_change_logs`, `power_interruption_logs`, `shift_stock_counts`) need it too, even though the earlier schema sketch didn't call it out explicitly.

**Shift Summary needs one accountable closer, even without a fixed lead.** The paper form has a single "Supervisor" line, but nobody's designated as *the* supervisor here. Recommend: whoever taps **Close Shift** (§5.9) is recorded as the accountable name for that shift's KPI roll-up (`shift_summaries.created_by`), independent of who logged which individual machine — but gate that action behind a real permission (e.g. a "close-shift" ability) rather than leaving it open to any logged-in helper, so a shift doesn't get closed early by whoever happens to tap it first. Worth confirming with the plant whether that gate should map to a specific role (only supervisors, not all shift-floor staff) or is fine as "any authenticated shift-floor user."

**What doesn't need to change:** "remember last machine+item" (§7) already works per-device, not per-user, so it stays useful whether one phone is shared by the whole shift or everyone carries their own — no rework needed there.

---

## 3. Design principles

1. **One task per screen.** Never the whole paper form at once — that's exactly the "fill this out at the end of a long shift" pattern this project exists to replace.
2. **Pick, don't type.** Machine and Item are always pickers (large buttons or searchable select), never free text. Numbers use a numeric keypad (`InputNumber`), never a generic text field.
3. **Read-only the moment a value is computable.** If the system can derive it, the supervisor never types it and never sees an editable box for it — see §6's field map. This is as much a data-integrity rule as a UX one: it removes an entire class of transcription errors the paper form is prone to.
4. **Default timestamps to "now."** Starting a downtime log stamps `from_time` automatically; closing it stamps `to_time`. Editable only if the supervisor is logging something after the fact.
5. **Remember context.** Last machine/item picked on this device carries forward as the default for the next entry — most repeat actions become one tap.
6. **Scan instead of pick, where a physical tag exists.** The barcode scanning already built (item/asset barcodes, `BarcodeScanInput`) applies directly here — a machine ID tag or item label scan can fill Machine/Item faster than any picker.
7. **Thumb-reachable primary actions.** Bottom-fixed action buttons, not top-of-screen — this is used standing up, one-handed, often with a glove on.
8. **Tolerate a bad connection.** Shop floors have dead zones. An entry started shouldn't be lost because the network blipped — see §7's offline-queue note.

---

## 4. Information architecture — the Shift Console

A **separate, minimal mobile layout** — not the existing desktop `AppLayout` (fixed sidebar + dense header). Different audience, different device, different job: this should be its own route group (e.g. `/shift-floor/*`) with no sidebar, a thin header, and a bottom action bar, reusing the same backend APIs as everything else.

```
┌─────────────────────────────────┐
│  Morning Shift · 2026-07-22       │  ← header: shift + date, tap to end shift
│  Supervisor: R. Kumar             │
├─────────────────────────────────┤
│  MACHINES                         │
│  ┌───────────┐ ┌───────────┐      │
│  │ ASB-1      │ │ ASB-2      │     │  ← one card per machine, color-coded:
│  │ ● Running  │ │ ○ Idle     │     │    green=running, grey=idle,
│  │ BTL-HDPE   │ │            │     │    red=down, amber=mould change
│  │ by Priya·2m│ │            │     │    small "by [name]·[time ago]" line
│  └───────────┘ └───────────┘      │    per §2 — who last touched it
│  ┌───────────┐ ┌───────────┐      │
│  │ ASB-3      │ │ ASB-4      │     │
│  │ ▲ Down     │ │ ◐ Mould    │     │
│  └───────────┘ └───────────┘      │
│  ... (10 machines, scroll,        │
│       pull-to-refresh)            │
├─────────────────────────────────┤
│  SHIFT-WIDE                       │
│  [Power Interruption] [Stock Count]│
│  [Shift Summary / Close Shift]     │
└─────────────────────────────────┘
```

Tapping a machine card opens a context-sensitive sheet based on its current state:
- **Idle** → "Start Batch"
- **Running** → "Complete Batch" (or "Log Breakdown" if it goes down mid-run)
- **Down** → "Close Breakdown" (return to Idle)
- **Mould Change in progress** → "Finish Mould Change" (return to Idle, ready for next Start Batch)

This single hub screen answers "what's happening right now, everywhere" at a glance — something the paper form can't do at all (it's only ever a historical record, filled in after the fact).

---

## 5. Screen-by-screen

### 5.1 Shift Start (once, at login)
- Pick **Shift** (3 large buttons — Morning/Afternoon/Night, same pattern as the existing "Log Production" page)
- Supervisor identity — from the logged-in user, not re-entered
- One button: **Start Shift** → Shift Console

### 5.2 Shift Console (home) — see §4.

### 5.3 Start Batch (bottom sheet, ~10 seconds)
- Machine — pre-filled (came from tapping the card); scan icon available if starting from a machine's own screen/kiosk instead
- Item — searchable picker, or scan the item's barcode
- One button: **Start** → machine card flips to "Running," sheet closes

### 5.4 Complete Batch (the core screen — grouped into clear sections, not one long scroll)

| Section | Fields | Notes |
|---|---|---|
| Identity | Machine, Item, Shift, Date, Operator | All pre-filled/read-only — nothing to type |
| Batch | Batch No. | Manual for now (auto-generation is Phase 6 — see master plan) |
| Production | Qty Produced (Nos) | Only real input in this section |
| | Qty Produced (Kg) | **Read-only** — `Nos × Item.nominal_weight_grams ÷ 1000`, per §6 |
| Packing | Nos/Tray, No. of Trays, Nos/Box, No. of Boxes | Steppers, not free-typed numbers where possible |
| Rejection | Qty Rejected (Nos), Rejection Reason (picker) | |
| | Qty Rejected (Kg) | **Read-only**, same formula |
| | Lumps (Kg) | Separate field per the paper form — pending §10's open question on exact definition |
| Consumption | Resin (Kg), Masterbatch (Kg) | Same line-entry pattern already used for Work Order materials — searchable item + qty rows |
| | | Confirm during the Phase 1.5 interview whether these should ever be auto-suggested from a standard BOM ratio × Nos Produced, with the supervisor only correcting variance — would turn most of this section read-only too, but don't build that until the real ratios are confirmed |

One bottom button: **Complete Batch** → machine card returns to "Idle," entry status flips to `completed`.

### 5.5 Mould Change (quick log, ~15 seconds)
- Machine — pre-filled
- Changed From / Changed To — item pickers (From defaults to the batch that just completed)
- Time — From defaults to "now," stop the clock with one tap for To
- **Log Mould Change** → machine card shows "Mould Change," returns to Idle when closed

### 5.6 Downtime / Breakdown (quick log)
- Machine — pre-filled
- Nature of Problem — short picker of common reasons + free text for "Other"
- Remedy, Parts Changed — optional, filled in when closing rather than opening (often unknown until fixed)
- Time — From auto-stamped at open, To auto-stamped at close
- Two-step: **Report Down** (just machine + problem, fast, gets the clock running) → later, **Close** (adds remedy/parts, stops the clock)

### 5.7 Power Interruption (shift-wide, not per-machine — per §10's current assumption)
- From/To time, same auto-stamp pattern as downtime
- **Log Interruption**

### 5.8 Stock Count (shift-wide)
- A short repeating list: Location (Hoppers / Day Bin / Loose Bag / Store, or a masterbatch colour) + Qty (Kg)
- Likely filled in once near shift end, not continuously — matches how the paper form's stock sections read

### 5.9 Shift Summary / Close Shift
- Target Production (Kg) — the one true manual input for the whole KPI sheet
- Power Consumption (Units) — the other one
- Remarks — free text
- Below that, a **read-only computed KPI panel** — the entire "Form 3" from earlier in the conversation, calculated live: Actual Production, Rejection Kg/%, Efficiency %, Net Good Output, Machines Running/Down, Idle Time, Mould Changes, Unit/Kg
- **Close Shift** — locks further entries against this shift/date, hands off to the approval flow (master plan §4a/§10)

---

## 6. Read-only vs. editable — the full field map

| Field | Type | Source |
|---|---|---|
| Qty Produced (Nos) | Editable | Manual |
| Qty Produced (Kg) | **Read-only** | `Nos × nominal_weight_grams ÷ 1000` |
| Qty Rejected (Nos) | Editable | Manual |
| Qty Rejected (Kg) | **Read-only** | Same formula |
| Lumps (Kg) | Editable | Manual |
| Resin/MB Consumption (Kg) | Editable | Manual (see §4.4's note on a possible future BOM-ratio default) |
| Batch No. | Editable | Manual (until Phase 6 auto-generation) |
| Packing counts | Editable | Manual |
| Target Production (Kg) | Editable | Manual (planning input, no other source) |
| Power Consumption (Units) | Editable | Manual (meter reading, no other source) |
| Actual Production (Kg) | **Read-only** | Sum of the shift's completed batches' Kg |
| Rejection (Kg) / % | **Read-only** | Sum + arithmetic |
| Shift Efficiency % | **Read-only** | `Actual ÷ Target × 100` |
| Net Good Output | **Read-only** | `Actual − Rejection` |
| Machines Running/Down | **Read-only** | Live from machine card states (§4) |
| Idle Time | **Read-only** | Sum of closed downtime logs |
| No. of Mould Changes | **Read-only** | Count of the shift's mould-change logs |
| Unit/Kg | **Read-only** | `Power Consumption ÷ Actual Kg` |

Rule of thumb used throughout: **if it's in the bottom half of this table, the supervisor never sees an input box for it — only a number.**

---

## 7. Efficiency ideas beyond replicating the paper form

- **Scan-to-select** — a machine ID tag or item barcode fills Machine/Item in one scan instead of a picker, for both Start Batch and any screen that needs an item. Zero new infrastructure — reuses `BarcodeScanInput` already built this session.
- **Remember last machine+item per device** — most shop-floor tablets sit near one or two machines; defaulting to what was last used there removes a pick entirely for the common case.
- **Auto "now" timestamps** everywhere a from/to time is needed, editable only on override.
- **Offline draft queue** — the app is already installable (PWA support, built this session), but true offline *capture* (not just fast loading) needs its own small piece: entries save to local storage first, sync in the background when connectivity returns, with a visible "3 entries pending sync" indicator. This is a real, separate technical task from the base PWA work — don't assume it's already covered.
- **End-of-shift completeness nudge** — before "Close Shift" is allowed, flag anything left dangling: a machine still shown "Running" with no Complete Batch, a downtime log opened but never closed. Catches the exact kind of gap that makes the next morning's paperwork painful today.
- **Voice input for Remarks** — native mobile browser speech-to-text, zero extra dependency, worth a try given how much faster it is than typing on a factory floor. Nice-to-have, not blocking.

---

## 8. How this maps to Phase 2's technical plan

| Screen | Entities touched (see master plan §10) |
|---|---|
| Start Batch / Complete Batch | `shift_production_entries` (+ `status` field, new per §1), `shift_material_consumptions`, `shift_scraps` |
| Mould Change | `mold_change_logs` (Phase 2b) |
| Downtime | `machine_downtime_logs` (Phase 2b) |
| Power Interruption | `power_interruption_logs` (Phase 2b) |
| Stock Count | `shift_stock_counts` (Phase 2b) |
| Shift Summary | `shift_summaries` (Phase 2a) + `ShiftSummaryService::report()` for the computed panel |

No new backend entities beyond what's already planned — this document is purely the *frontend* shape (routes, screens, component choices) for delivering Phase 2's data model in a way that's actually fast to use standing at a machine, not a retrofit of the existing desktop "Log Production" page.
