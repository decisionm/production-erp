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
**only when the row was created INSIDE the shift window it is filed under**
(`shiftFloorSummary.createdWithinShiftWindow`), and omitted otherwise — the Carryover
tag already states the date and shift in that case.

> **Corrected at re-gate.** The first implementation compared
> `productionDateFor(shift, created_at)` against `production_date`, and this document
> described it as a plain date match — two descriptions, neither matching the
> behaviour. For an **overnight** shift it was very nearly no gate at all:
> `productionDateFor` maps *every* clock time before the shift's start back to the
> previous day (that is its documented job, so 02:00, the 06:10 grace window and
> 10:00 late paperwork all file under yesterday). A Night batch filed under the 18th
> but created at 10:00 on the 19th therefore passed, and the card printed
> `started 10:00` — a time outside the 22:00–06:00 window entirely, with no date
> beside it, which is exactly the back-dating the gate exists to suppress. The check
> now asks the direct question and is unit-tested against that case.

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
     Change / Carryover distinct at a glance without reading a word. **Carryover gets its
     own gold rail** even though `machineFloorState` rightly classifies it as `running`
     (it *is* running, it is counted as running, and it completes from here) — without
     that it would be railed the same green as a clean current run and said so only in a
     tag, which is the very failure §2.5 describes. It shares gold with "not handed over";
     the two stay distinct by their status tag and header line;
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
6. **Needs Attention** — the amber "sent back by quality" panel moves from above the
   machine grid to its own bottom section, **Needs Attention · Corrections Required**.

   > **Superseded, and left visible rather than rewritten.** This originally *unified*
   > that panel with the "completed earlier and still correctable" list into one
   > section. A later pass reversed the unification: the earlier-correctable batches
   > are ordinary history — nothing is wrong with them, and an exception list that is
   > never empty is one nobody reads on the day it matters. They now sit under the
   > day's work as **Earlier batches — still correctable**, with the same
   > `canAmendCompletion` gate and an `Open` door, and `needsAttentionCount` counts
   > only what the server proves via `correction.awaiting_correction`.

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

## 7. Verified in the browser

Against an isolated instance (`php artisan serve` on `:8011` from this worktree, over a
**copy** of the dev SQLite fixture — the shared `:8010` instance serves a different
worktree's build, so it could never have shown these changes).

| Check | Result |
|---|---|
| `Report Down` opens exactly one modal, and **not** Start Batch | 1 modal, `Report Down — Machine 1`. The card's own `onClick` did not also fire. |
| `Start Batch` button on an Idle card | 1 modal, `Start Batch — Machine 2`. |
| `Mold Change` secondary button | 1 modal, `Mold Change — Machine 3`. |
| Clicking the card **background** still works as it always did | Opened `Start Batch — Machine 4`. `primaryClick` unchanged. |
| Down state end to end | Reported a breakdown on MC-01 → red rail, `Down` tag, problem text, `Since 14:06`, `Close Breakdown` as the primary CTA; `Report Down` correctly absent. Closed it again → card returned to Idle. |
| Tiles agree with the grid | 10 Idle / 0 Down → 9 Idle / 1 Down → 10 Idle / 0 Down, tracking the grid exactly. |
| Responsive | 1440×1000 (4 cards/row) and 390×844 (full-width cards, tiles wrapped, `Segmented` in `block` mode) both read correctly. |
| Console | No new errors. One pre-existing service-worker scope warning from the PWA build. |

`npm run typecheck`, `npm run test` and `npm run build` are clean — 541 tests over
27 files after the second pass below.

### 7.1 Second pass (same day) — the UOM, exception-list and accessibility work

Same method, same isolation: `php artisan serve` on `:8012` from this worktree, over a
**copy** of the dev SQLite fixture. Screenshots are committed under
`docs/engineering/ux-shift-floor/`.

| Check | Result | Evidence |
|---|---|---|
| **Before**, from a build under my own control (main's page, built and served on the same port) | No production date, no counts, a `size="large"` radio bar, and a red-outlined `Report Down` as the FIRST button on all eleven cards — **no Start Batch button at all**. Every claim in §1–§2 confirmed against a render, not from reading. | `before-desktop.jpg` |
| After — desktop 1440, all Idle | Date + shift stated; tiles 0 / 11 / 0 / 0; one labelled `Start Batch` per card; `Mold Change` and `Report Down` secondary, on one line. | `after-desktop-all-idle.jpg` |
| After — desktop, mixed Idle + Down | Reported a breakdown on MC-02 → red rail, `⚠ Down` tag, problem text, `Since 17:00`, `Close Breakdown` as a red primary, `Report Down` correctly absent. Tiles moved 0/11/0/0 → 0/10/**1**/0, tracking the grid exactly. Breakdown closed again afterwards; the fixture ends where it started. | `after-desktop-mixed-state.jpg` |
| Tablet 834 (wide branch — the breakpoint is 767) | 3 cards per row, tiles in one row, the table kept. The secondary row wrapped onto two lines at this width, which is why the buttons' side padding was trimmed to 8. **Re-shot against the final build** — tablet is the only width that discriminates the padding change, since 1440 fitted one line either way. | `after-tablet-834.jpg` |
| Narrow branch | `Segmented` in `block` mode, tiles wrapped, one card per row, secondary row on one line. **At 500px, not 390** — this OS refuses to size a Chrome window below 500. 500 is well inside the `max-width: 767px` branch, so the branch is exercised; the exact 390 layout is not separately proven. | `after-mobile-narrow.jpg` |
| Keyboard — reach | Tabbing from the shift `Segmented` walks into the grid and reaches `Start Batch`, `Mold Change` and `Report Down` in DOM order. | `after-desktop-keyboard-focus.jpg` |
| Keyboard — visible focus | `:focus-visible` matches on the focused control and the ring is visible in the screenshot. | same |
| Keyboard — activation | `Enter` on a focused `Start Batch` opened **exactly one** modal (`Start Batch — Machine 1`) — the card's own `onClick` did not also fire. | asserted in-page |
| Modal focus restoration | `Esc` closed it and returned focus to MC-01's `Start Batch`, still `:focus-visible`. AntD's own behaviour, confirmed rather than assumed. | asserted in-page |
| Zero visible Day Bin | `document.body.innerText` scanned: **no line matches `/day\s*bin/i`** across 123 lines of rendered text. Pinned statically as well by `shiftFloorCopy.test.ts`. | asserted in-page |
| Console | No messages captured on a fresh load. | — |

**Not verified visually, and why** — unchanged from §7, and now stated as a list of
what a reviewer should NOT read as covered:

- **Running and Mold Change cards.** The fixture holds no in-progress batch and no
  molds. Producing a Running card means **creating a production batch**, which
  `AGENTS.md` forbids without exception — the rule is not qualified by "unless the
  database is a local copy", and this PR already carries that refusal in writing. Both
  are covered by wireframe (`UX-SHIFT-FLOOR-WIREFRAMES.md` §2.2) and by the shared pure
  functions, not by a screenshot.
- **Completed Today, populated**, and therefore the figure tiles, the mixed-UOM
  withholding branch, the "Open" control and the two correction sections. All require
  completed batches. Covered by wireframe (§2.3) and by unit tests over
  `completedTodaySummary` / `completedTodayUnits`, which is where the arithmetic and
  the unit decision actually live.
- **`prefers-reduced-motion`.** The CSS block and the `scrollIntoView` guard are in
  place and reviewable, but the OS-level setting was not toggled, so the guard is not
  proven by observation.
- **Permission variants.** Verified as one role (Fixture Supervisor). Nothing in this
  pass touches a permission check — every gate (`traceabilityEnabled`,
  `canAmendCompletion`, the `Cancel Batch` predicate) is preserved verbatim — but the
  other roles were not walked through.
- **The `Tooltip` now wrapping the ordinary `Open` control.** It renders inside a
  `Table` cell and inside the earlier-correctable cards, neither of which this fixture
  can produce. Low risk, and stated rather than assumed.

### 7.2 The one change that reaches outside Shift Production

Everything else in this PR is confined to `ShiftProductionEntryPage.tsx` and modules
only it imports. **`frontend/src/index.css` is app-global**, and gains two rules:

- `.floor-attention-chip:focus-visible` — scoped by class to this page's one bare
  anchor, so it can affect nothing else.
- `@media (prefers-reduced-motion: reduce)` — sets `scroll-behavior: auto` on `html`
  and removes the transition from `.ant-card-hoverable`. **This applies to every
  hoverable card in the application, not only the floor.** That is deliberate — a
  device asking for less motion is asking globally, and honouring it on one page only
  would be the odd behaviour — but it is the single edit here a reviewer should weigh
  outside the scope of Shift Production, so it is named rather than left to be found.

**Not verified visually, and why:** the **Running** and **Mold Change** cards. The fixture
database holds no in-progress batch and no molds. Producing a Running card would have
meant **creating a production batch**, which `AGENTS.md` forbids without exception — the
rule is not qualified by "unless the database is a local copy", so it was not bent for a
screenshot. Both card variants were verified by code path and by the shared pure
functions instead.

## 8. Preserved verbatim

Re-verified unchanged after the rework:

- `primaryClick`'s entire body — every form reset, ref clear, and the `runningForOtherShift`
  branch that answers **before** the running branch.
- `Cancel Batch` predicate `running && !running.quality?.checked && running.status === 'pending'`.
- `traceabilityEnabled` gate on `Load Material` and `Hand Over Shift`.
- The `runningForOtherShift` weighting on `Hand Over Shift` — handover is the only
  action left on a not-ours card, and that *is* a business rule, not styling. **The
  business-relevant half is preserved, the line is not verbatim:**
  `type={runningForOtherShift ? 'primary' : undefined}` became
  `type={runningForOtherShift ? 'primary' : 'text'}`. Primary if and only if the run
  has not been handed over is unchanged; the non-primary case moved from AntD's
  default button to a text button, because it now sits in the card's secondary row.
- `hoverable={!runningForOtherShift}` and the `completeElsewhere` tooltip/toast wording.
- The `.filter((w) => w.is_active)` defence-in-depth on the work-centre list.
- Every modal, drawer, mutation, schema and query in the file.
- FC-01: no per-machine material button; resin still enters through the one
  `Load Material` door. DEC-20260817-001: no "Day Bin" wording introduced.


---

## 9. Independent re-gate (2026-08-18, after the head moved)

Run because the head ref changed after the first review. **CI: all four checks green**,
including the MySQL 8 leg. **Zero backend files changed** by this branch.

### 9.1 What the re-gate found — and fixed

Two P1s, no P0. Both were confirmed by reproduction before being fixed, not taken on
trust.

**P1-1 — the start-time gate was very nearly no gate at all on an overnight shift.**
`startedAtLabel` compared `productionDateFor(shift, created_at)` against
`production_date`. `productionDateFor` maps *every* clock time before an overnight
shift's start back to the previous day — that is its documented job, so a Night batch
recorded at 02:00, at 06:10 in the grace window, or at 10:00 as late paperwork all file
under yesterday. The consequence: a Night batch filed under the 18th but **created at
10:00 on the 19th passed the check**, and the card rendered `started 10:00` — a time
outside the 22:00–06:00 window entirely, with no date beside it. Exactly the back-dating
§4.2 says the gate exists to suppress.

Fixed by asking the direct question — was the row created *inside* the shift window it
is filed under — in a new pure `createdWithinShiftWindow`, unit-tested against the
failing case and against the 02:00 case it must **not** suppress. `startedAtLabel` was
the one piece of new display logic that had not been extracted and so had no test; that
is why it shipped and why the extraction now covers it.

**P1-2 — the Day Bin copy guard passed on the exact revert it existed to catch.**
Restoring two of the real sentences this PR fixed left `shiftFloorCopy.test.ts` green.
Reproduced directly: with `The day bin has no {material…} recorded` and `No factory day
bin chosen yet…` put back, the suite passed. Neither is a quoted literal (JSX prose is
not a string), and neither survives a `>([^<>{}\n]+)<` extractor — Prettier wraps prose
onto its own lines and nearly every sentence here carries a `{expr}` or a `{' '}`. The
guard reached link labels and missed paragraphs.

Fixed by inverting it: scan the whole comment-stripped source for the phrase and
**allow-list the machine identifiers** that legitimately keep the name (the route, the
query keys, the API functions, the form field). The failure mode goes from silent-miss
to noisy-and-fixable.

**And the inverted guard immediately earned itself.** It found a user-visible leftover
that the old guard, the DOM scan and both review passes had all missed:

> `Day bin: {fmtNum(held ?? 0, 4)}` — the material-balance hint beside a consumption row
> in the completion drawer.

It never appeared in any DOM scan because that drawer cannot open without a batch. So
the "no Day Bin wording" constraint was **not** actually met when it was first reported
as met. It now reads `Common input:`.

### 9.2 Also fixed at re-gate

| | Fix |
|---|---|
| The KPI numerals failed WCAG AA | `#52c41a` on white is ~2.3:1 and `#faad14` ~1.9:1 — the loudest glyph on the strip was the one failing. The numeral now takes a separate darker `readable` tone (`#389e0d` / `#ad6800` / `#cf1322`) while the **rail keeps the accent**, so the colour still means one thing. Verified live: the Down numeral computes `rgb(207,19,34)` against a `rgb(255,77,79)` rail. |
| The shift control lost its accessible name | The `Radio.Group` sat in a `<Form.Item label="Shift">`; the `Segmented` had none. `aria-label="Shift"` added — the tree now reports `radiogroup "Shift"`. |
| The mixed-unit note pointed at a table that stated no unit | `CompletedTodayTable` rendered bare integers for Expected/Actual/Good/Reject. Each row now states its own unit once (`quantities in Nos.`), from `uomOf(item)` — so the withholding branch redirects somewhere that is actually denominated. |
| `qcRejectedPieces` bypassed `num()` | The one sum that did. Safe today (the type says `number \| null`), a silent string-concatenation the day that resource follows its siblings into decimal strings. |

### 9.3 States exercised in a browser this pass

| State | Verified | How |
|---|---|---|
| **Idle** | yes | 11 cards, `Start Batch` primary, `Mold Change` + `Report Down` secondary on one line |
| **Down** | yes | red rail, `⚠ Down` tag, problem text, `Since HH:MM`, `Close Breakdown` red primary, `Report Down` correctly absent |
| **Mold Change** | **yes — new this pass** | a mold-change log needs an item and a mold, not a batch. Fixture mold created, change opened on MC-03: gold rail, `⇄ Mold change` tag, `→ ITEM` transition, `Since 17:20`, `Finish Mold Change` primary, no secondary row |
| **Mixed** | yes | 9 Idle + 1 Down + 1 Mold change; tiles tracked the grid exactly through every transition, both directions |
| **Running** | **NO** | needs an in-progress batch |
| **Carryover** | **NO** | needs an in-progress batch filed under an earlier shift |

**Running and Carryover remain unverified in a browser, and no attempt was made to
change that.** Producing either means **creating a production batch**, which `AGENTS.md`
forbids without exception. The fixture ends this pass with **0 production batches**, 0
open downtime logs and 0 open mold changes — every state created for evidence was closed
again. Both cards remain covered by wireframe and by the shared pure functions only.

### 9.4 Also still unverified

- **Completed Today populated** — and with it the figure tiles, the mixed-UOM withholding
  branch, the `Open` control, the new per-row unit line and both correction sections.
  Needs completed batches. Covered by unit tests over the pure functions.
- **`prefers-reduced-motion` at OS level.** The rule ships in the built bundle
  (confirmed in `backend/public/build/assets/*.css`, alongside a pre-existing
  reduced-motion rule for the dashboard lamps, so the pattern is the app's own), and the
  `scrollIntoView` guard is source-visible — but the OS setting was not toggled, and the
  chip that starts the only animation cannot render without a returned batch.
- **Roles beyond two.** Supervisor and Plant Manager both render an identical floor.
  Nothing in this diff contains a permission check — the only matches for
  `can(`/`permission`/`authorize`/`policy` across the whole diff are **comments**, and
  `types.ts` (which owns `canAmendCompletion` and `isAwaitingCorrection`) has **zero**
  changed lines.
- **A mass-denominated finished good.** `uomOf` is applied to the expected-output
  projection, which is a shot count (`cycles × cavities`) rather than a stock quantity.
  The server already treats the two as commensurable (`efficiency_pct = actual_pieces ÷
  expected_pieces`, where `actual_pieces` *is* `quantity_produced`), so this inherits an
  existing backend assumption rather than introducing one. Whether any batch-startable
  item is non-piece is a **live-data** question and per `AGENTS.md` must be counted on
  the live instance, not inferred from a dev fixture. **Open until counted.**

### 9.5 One incidental finding, not about this PR

The shared dev SQLite fixture is **behind the migrations**: creating a mold failed with
`table molds has no column named created_by`, added by the configuration-lifecycle work.
A local `php artisan migrate` on the disposable copy fixed it. Anyone else working from
that fixture will hit the same wall.

### 9.6 The review chain could not be completed

`AGENTS.md` requires Builder → **Cursor review** → **Codex verification** → **owner**.

- **Codex verification: could not run.** The CLI is installed and was invoked directly
  (`codex-companion adversarial-review`); it returned *"You've hit your usage limit …
  try again at Aug 20th, 2026 9:14 AM."* No Codex findings exist for this head.
- **Cursor review: not available to me.**
- **Owner sign-off: outstanding by definition.**

The two review passes recorded above are an independent code review and this author's
own verification. They are **not** the Cursor and Codex links, and must not be counted
as them.
