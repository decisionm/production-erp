# UX audit — Shift Floor (`/production/shift-production`)

**Date:** 2026-08-18 · **Scope:** `frontend/src/features/production/pages/ShiftProductionEntryPage.tsx`
(8,664 lines) and the components/reads it renders.
**Audience for the screen:** a Production Supervisor standing on the floor who must
understand the whole factory in five seconds, on a phone or a tablet, often mid-shift.

This is a **presentation** audit. Nothing below proposes changing a permission check, a
state guard, a production calculation, an estimation/versioning behaviour or a Tally
interaction. Where a UX improvement would have required one, it is listed in
[§6 Refused](#6-refused--would-have-required-a-business-logic-change) instead of built.

---

## 1. What a supervisor cannot see in five seconds today

| # | Question the supervisor is actually asking | Today's answer |
|---|---|---|
| 1 | *How many machines are running right now?* | Nowhere. You count ten cards by eye, every time. |
| 2 | *Is anything down?* | Only by scanning every card for a red `Down — …` tag, which sits in the same visual slot as `Idle`, `Running` and `Mold Change`. |
| 3 | *Which production day am I looking at?* | **Never stated.** The page derives `today = productionDateFor(effectiveShift)` and uses it for every read (Completed Today, power interruptions, Start Batch) but prints it nowhere. At 02:00 on Night this is *yesterday* — the single most confusing fact on the screen, and it is invisible. |
| 4 | *How is the day going — output against target?* | Only by reading the Completed Today table row by row and adding up in your head. |
| 5 | *What needs me next?* | Competes with everything else; see §2. |

## 2. What competes for attention (mis-weighting)

**2.1 `Report Down` is the loudest element on a healthy factory.**
Every non-down, non-mold-change card renders it as `<Button block danger>` — a
**full-width red button on every running and every idle machine**. With ten machines
that is up to ten full-width red buttons on a floor where nothing is wrong. Red is the
screen's scarcest signal and it is spent on the least frequent action. Worse, on an
**Idle** card `Report Down` is the *only* button rendered, so the visually dominant
control on an idle machine is the one action a supervisor almost never wants — while
**Start Batch is not a button at all**: it is an unlabelled click on the card body,
discoverable only by `hoverable` and the page's intro paragraph.

**2.2 Four buttons, all the same weight.** `Report Down`, `Mold Change`,
`Hand Over Shift`, `Cancel Batch` stack full-width and equal-width. Two of them
(`Report Down`, `Cancel Batch`) are `danger`. The card gives no clue which one is the
normal next step.

**2.3 The primary action is invisible on every card.** Start Batch (idle), Complete
Batch (running), Close Breakdown (down) and Finish Mold Change (mold change) are **all**
the same thing: a click on the card background (`onClick={primaryClick}`). Four
different, consequential actions with zero affordance and no label.

**2.4 The shift selector is a `size="large"` solid `Radio.Group` capped at
`maxWidth: 480`,** wrapped in a stray `Form.Item` with no form around it. It is the
heaviest control above the fold and it competes with the machine grid.

**2.5 Section headings are undifferentiated.** `Completed Today` is a bare
`Typography.Title level={5}` with no summary; the floor-action buttons are a naked
`<Space>` with no heading at all, floating between the grid and the table.

**2.6 The corrections work is split across three places** with three different visual
treatments: an amber panel *above* the grid, per-row buttons *inside* Completed Today,
and a grey "Completed earlier and still correctable" list *below* it. Nothing names the
job; nothing counts it.

## 3. Data that is already available and unused

All of the following is already on the wire and rendered nowhere:

| Datum | Source | Use |
|---|---|---|
| Production date | `productionDateFor(effectiveShift)`, already computed | Print it in the header |
| Machine-state counts | `runningByMachine` / `openDowntimeByMachine` / `openMoldChangeByMachine`, already built as `useMemo` maps | Floor KPI strip |
| Product **name** on a running card | `running.item.name` (card shows `item.sku` only) | Running card |
| Batch start time | `entry.created_at` (ISO on the resource) | Running card — **with the caveat in §5** |
| Down reason & since | `down.nature_of_problem`, `down.from_time` | Down card |
| Mold change from → to | `moldChange.changed_from_item` / `changed_to_item` | Mold Change card |
| Per-batch `expected_pieces`, `quantity_produced`, `quantity_scrap`, `efficiency_pct`, `efficiency_band` | `metrics` / entry columns on each **completed** entry | Completed Today KPI summary |
| Live `efficiency_over` ceiling | `useProductionSettings()?.tolerances?.efficiency_over` | Colour the summary honestly, not against a hardcoded 100 |

## 4. What is missing from the backend (do **not** fabricate)

**4.1 A Running batch has no actual, no progress and no efficiency.**
`ShiftProductionEntryService::productionMetrics()` returns `null` unless
`batch_status === Completed`:

```php
if ($entry->batch_status !== BatchStatus::Completed) {
    return null;
}
```

and `quantity_produced` is null until the completion drawer is submitted. There is
therefore **no server-side actual, no percent-complete and no efficiency for a running
batch**, and none is invented here. The only honest production figures on a Running card
are the ones already present: the frozen standard (`standard_cycle_time`,
`active_cavities ?? standard_cavities`), the shift length, and the client-side
`expectedOutput()` projection keyed off the entry's own `calculation_version` — which
this page already computes as `liveExpected` and which stays exactly as it is.

An elapsed-time ÷ expected progress bar was considered and **rejected**: it would render
a fabricated production quantity in the same visual slot as real ones, on a live factory
screen. That is precisely the class of error PR #128 was withdrawn for.

**4.2 There is no `started_at` column.** `create_shift_production_entries_table` and its
migrations carry no start timestamp; `created_at` is the row-creation time. For a
back-dated batch (`startDateChosen ?? startProductionDateOverride ?? today`) the record
can be created this morning and filed under last night. Start time is therefore rendered
**only when `created_at`'s date matches the entry's `production_date`**, and omitted
otherwise — the Carryover tag already states the date and shift in that case.

**4.3 Completed Today is production-date scoped, not shift scoped.**
`listCompletedEntriesForDay(today)` filters on `production_date` + `batch_status` only,
so it spans all shifts of the factory day. The KPI summary is therefore placed **inside
the Completed Today section and labelled "today"**, computed over exactly the rows in the
table beneath it. Shift-filtering it client-side was rejected: tiles showing one shift
above a table showing three is a discrepancy a supervisor would reasonably read as a bug.

**4.4 Reject quantity for the shift-in-progress does not exist** for the same reason as
4.1 — rejects are entered at completion. The reject KPI is a *completed-today* figure and
is labelled as such.

**4.5 Aggregate efficiency is not a server figure.** The server rules per-entry
`efficiency_pct` and `efficiency_band`. The summary therefore shows **output vs expected
as a ratio of the two sums**, over only the rows that carry an expected figure, and
discloses how many rows were excluded. It is deliberately *not* painted with the
per-entry `EfficiencyBand` palette, and it is not called "efficiency".

## 5. What changed (the "after")

**Hierarchy, top to bottom:** Header (title · **production date** · shift) → **Floor
status KPIs** → **machine grid** → **Floor actions** → **Completed Today** (+ KPI
summary) → **Needs Attention**.

1. **Header** — a compact header row states the production date beside the shift
   `Segmented` control (replacing the oversized `Radio.Group` in its stray `Form.Item`).
   Same value, same `onChange` target, `block` on narrow screens.
2. **Floor status strip** — Running · Idle · Down · Mold change, plus "Not handed over"
   when non-zero. Computed by one pure function that the cards and the tiles share, so a
   tile can never disagree with the grid.
3. **Machine cards** rebuilt around state:
   - a coloured status rail (`borderInlineStart`) makes Idle / Running / Down / Mold
     Change / Carryover distinct at a glance without reading a word;
   - **one primary CTA per state, labelled**: `Start Batch` (idle, `type="primary"`),
     `Complete Batch` (running), `Close Breakdown` (down), `Finish Mold Change`
     (mold change). Each calls the existing `primaryClick` — the same handler the card
     background always called — with `stopPropagation()` so it fires exactly once;
   - **`Report Down` demoted** to a small secondary `danger` link in a footer row beside
     the other secondary actions. It is no longer `block`, no longer full-width, and no
     longer the loudest thing on a healthy machine. It still opens the same modal;
   - Running cards show SKU **and product name**, batch number, start time (gated per
     §4.2), and the existing expected-output projection with its basis line.
4. **Floor actions** — Load Material · Log Power Interruption · Log Stock Count grouped
   under a labelled toolbar instead of three loose buttons. `Load Material` keeps its
   `traceabilityEnabled` gate.
5. **Completed Today** — a KPI summary (batches · good · expected · output-vs-expected ·
   reject) above the existing table, and a real empty state instead of a bare sentence.
6. **Needs Attention** — the amber "sent back by quality" panel and the "completed
   earlier and still correctable" list are unified into one bottom section,
   **Needs Attention · Corrections Required**.

   *This reverses a documented prior decision* — the amber panel sat above the grid with
   the comment *"ten machine cards of scrolling on a phone is the same as hiding it."*
   That concern is real and is answered rather than ignored: a **count chip in the floor
   status strip** links straight to the section, so the work is announced above the fold
   and done below it. The panel is still rendered only when non-empty, for the reason the
   original comment gives.

## 6. Refused — would have required a business-logic change

| Wanted | Why it was not built |
|---|---|
| Progress / % complete on a Running card | No server actual for an in-progress batch (§4.1). Deriving one invents a factory quantity. |
| Efficiency on a Running card | Same — `metrics` is `null` until completion. |
| Reject quantity for the shift in progress | Rejects are entered at completion (§4.4). |
| A **date picker** in the header | The production date is *derived* from the shift clock (`productionDateFor`). Making it selectable would change which day every read and every Start Batch files under. It is displayed, not made editable. |
| KPI tiles that filter the machine grid on click | Would add a client-side filter over the state the backend's "one in-progress batch per machine" guard is mirrored from. Out of scope for a presentation pass. |
| Merging `Report Down` into a single overflow menu with `Cancel Batch` | `Cancel Batch` is gated on `running && !running.quality?.checked && running.status === 'pending'` — burying a permission-gated destructive action inside a menu that also holds an ungated one blurs a rule the screen currently states by showing/hiding the button. Both stay visible, both stay secondary. |

## 7. Preserved verbatim

Re-verified unchanged after the rework:

- `primaryClick`'s entire body — every form reset, ref clear, and the `runningForOtherShift`
  branch that answers **before** the running branch.
- `Cancel Batch` predicate `running && !running.quality?.checked && running.status === 'pending'`.
- `traceabilityEnabled` gate on `Load Material` and `Hand Over Shift`.
- `type={runningForOtherShift ? 'primary' : undefined}` on `Hand Over Shift` — handover is
  the only action left on a not-ours card, and that is a business rule, not styling.
- `hoverable={!runningForOtherShift}` and the `completeElsewhere` tooltip/toast wording.
- The `.filter((w) => w.is_active)` defence-in-depth on the work-centre list.
- Every modal, drawer, mutation, schema and query in the file.
- FC-01: no per-machine material button; resin still enters through the one
  `Load Material` door. DEC-20260817-001: no "Day Bin" wording introduced.
