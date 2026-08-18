# Shift Floor — wireframes and the audit table

**Companion to** `UX-SHIFT-FLOOR-2026-08-18.md`, which carries the reasoning.
This file carries the two artefacts the brief asks for before implementation:
the element-by-element audit table, and wireframes for the three states the
screen actually stands in.

Screen: `/production/shift-production` · Direction: **Balanced Dashboard**.

---

## 1. The audit table

One row per element on the screen. **Data dependency** names what the change
needs on the wire; **business-risk check** is what was verified before it was
allowed. A row whose data dependency could not be met is in §4, not here.

| Current element | Operator need | Problem | Proposed change | Data dependency | Business-risk check |
|---|---|---|---|---|---|
| Page header (title only) | "Which production day am I filing under?" | The production date is derived and used by every read on the page — Completed Today, power interruptions, Start Batch — and printed **nowhere**. On Night at 02:00 it is *yesterday*. | Compact header states **production day + shift + shift window**. | `productionDateFor(effectiveShift)` — already computed on this page. | **Displayed, never picked.** A date picker would change which day every read and every Start Batch files under. No mutation, no new read. |
| Shift selector — `size="large"` solid `Radio.Group` in a stray `Form.Item` | Switch A/B/C | Heaviest control above the fold; competes with the machine grid; wrapped in a form that does not exist. | Same options, same `onChange` target, rendered as `Segmented`; `block` on narrow. | None — same `shiftOptions`, same `setSelectedShiftId`. | **Same business interaction.** Identical value in, identical setter out. Not a new eligibility surface. |
| *(absent)* Floor status | "How many are running? Is anything down?" | Answered only by counting ten cards by eye, every time. | KPI strip: Running · Idle · Down · Mold change, plus **Not handed over** when non-zero and a **Needs attention** chip when non-zero. | `runningByMachine` / `openDowntimeByMachine` / `openMoldChangeByMachine` — `useMemo` maps the cards already build. | **Counts only, no new figure.** Derived through the same pure `machineFloorState` the cards read, so a tile can never disagree with the grid; pinned by an exhaustive bucket-sum test. Deliberately **not clickable** — a tile that filtered the grid would be a second way to answer "what is on this machine". |
| Machine card — status in a `Tag` in one slot for all five states | Tell five states apart at a glance | Idle, Running, Down, Mold Change and Carryover all sat in the same visual slot; only the word differed. | Coloured **status rail** + **icon + word** in the tag, from one shared `STATE_STYLE`. | None — states already known. | **Colour is never the only channel** (a11y). Carryover keeps its own gold rail while `machineFloorState` still counts it as running — presentation split, state machine untouched. |
| Machine card — primary action is an unlabelled click on the card body | "What do I press next?" | Start Batch, Complete Batch, Close Breakdown and Finish Mold Change were **all** `onClick={primaryClick}` on the background, with zero affordance. | **One labelled primary CTA per state**, calling the very same `primaryClick`, `size="large"`. Card background still works. | None. | **Same handler, same guards.** `stopPropagation()` so a tap fires once. `runningForOtherShift` answers *before* the running branch, as it always did — so no form reset fires on a not-ours card. |
| `Report Down` — `<Button block danger>` on every card | Report a breakdown, rarely | Up to **ten full-width red buttons on a healthy factory**; on an Idle card it was the *only* button while Start Batch was not a button at all. | Demoted to a secondary `type="text" danger` action in the card's footer row. Still on every non-down card. | None. | **Availability unchanged** — a breakdown is a fact about the machine, not about whose batch is on it. Only weight changed. |
| Four equal-weight full-width buttons | Know which is the normal next step | `Report Down`, `Mold Change`, `Hand Over Shift`, `Cancel Batch` stacked identically; two of them `danger`. | One secondary row, one weight for all four, under the primary CTA. | None. | `Cancel Batch` keeps its predicate verbatim (`running && !running.quality?.checked && running.status === 'pending'`), and stays **visible** rather than in a menu — showing/hiding it is how the screen states that rule. `Hand Over Shift` keeps its `traceabilityEnabled` gate and its `primary` weight on a not-ours card (a business rule, not styling). |
| Running card — SKU inside the status tag | Identify the run | No product name, no batch line, no start time. | SKU + product name + batch number + started time. | `running.item.name`, `running.batch_number`, `entry.created_at`. | **Start time is gated**: there is no `started_at` column, so it renders only when `created_at`'s date matches the entry's `production_date`. A back-dated batch would otherwise name the wrong evening. |
| Running card — expected output | "Am I on target?" | Expected shown; unit was the literal `pcs`. | Same projection, unchanged formula; **unit read from the item master**. | `running.item.uom`. | **The formula is not re-derived** — `expectedOutput()` keyed off the entry's own `calculation_version`, exactly as before. Only the unit label changed, from an assumption to a read. |
| Three loose floor buttons in a bare `<Space>` | Act on the floor, not a machine | Floated between grid and table with nothing to say they belonged together. | One labelled **Floor actions** group, `size="large"`. | None. | `Load Material` keeps its `traceabilityEnabled` gate. **FC-01 intact**: no per-machine material button — resin enters one common input, so it has one door. |
| `Completed Today` — bare `Title level={5}` over a table | "How did the day go?" | No summary; totals added up in the head, row by row. | Figure tiles above the existing table: Batches · Good · Expected · Output vs expected · Reject. | `quantity_produced`, `metrics.expected_pieces`, `quantity_scrap`, `quality.rejected_nos` — all already on the wire. | **Sums of the server's own figures only.** The ratio is labelled *output vs expected*, never *efficiency* — the server rules efficiency per entry against the deployment's tolerance, and an average of ratios is not a ratio of sums. Rows without an expected figure leave **both** sides and are counted out loud. **Units are read, and totals are withheld entirely when the day's batches disagree about their unit.** |
| Correction work in three places | "What still needs me?" | An amber panel above the grid, per-row buttons in the table, and a grey list below it — three treatments, nothing naming or counting the job. | **Needs Attention · Corrections Required** for quality's returns only; ordinary correctable history becomes **Earlier batches — still correctable**; a count chip announces the first above the fold. | `correction.awaiting_correction` (server field) vs `canAmendCompletion` (ordinary eligibility). | **Exception list holds only server-proven exceptions.** Ordinary editable batches are not "attention" — nothing is wrong with them. Eligibility gate unchanged; only the label ("Open") and the section changed. |
| Day Bin wording in links and sentences | — | DEC-20260817-001: **there is no Day Bin.** Eight user-visible strings still named one, while the page they linked to was already "Common resin input". | Copy replaced with the approved name. | None. | **Copy only.** Route, query keys and service names keep the old identifier — renaming those is a migration, not a UX pass. Pinned by `shiftFloorCopy.test.ts`, which distinguishes prose from identifiers. |

---

## 2. Wireframes

Desktop unless marked. `▎` is the status rail (colour + icon + word).

### 2.1 State A — all Idle (start of shift, nothing running)

```
┌────────────────────────────────────────────────────────────────────────────┐
│  Shift Floor                                        ┌───────────────────┐  │
│  Production day 2026-08-18 · A 06:00–14:00          │  A  │  B  │  C    │  │
│                                                     └───────────────────┘  │
├────────────────────────────────────────────────────────────────────────────┤
│  ▎0        ▎10        ▎0         ▎0                                        │
│   ▶ Running  ⊖ Idle    ⚠ Down     ⇄ Mold change                            │
│   (grey)     (grey)    (grey)     (grey)      ← zero is a fact, not alarm   │
├────────────────────────────────────────────────────────────────────────────┤
│ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐        │
│ │▎ASB-1  ⊖Idle │ │▎ASB-2  ⊖Idle │ │▎ASB-3  ⊖Idle │ │▎ASB-4  ⊖Idle │        │
│ │ No batch     │ │ No batch     │ │ No batch     │ │ No batch     │        │
│ │ running.     │ │ running.     │ │ running.     │ │ running.     │        │
│ │              │ │              │ │              │ │              │        │
│ │[ Start Batch]│ │[ Start Batch]│ │[ Start Batch]│ │[ Start Batch]│ ← primary│
│ │──────────────│ │──────────────│ │──────────────│ │──────────────│        │
│ │ Mold  Report │ │ Mold  Report │ │ Mold  Report │ │ Mold  Report │        │
│ │ Change  Down │ │ Change  Down │ │ Change  Down │ │ Change  Down │ ← tertiary│
│ └──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘        │
│                        … 6 more, 4 per row at ≥1200px …                    │
├────────────────────────────────────────────────────────────────────────────┤
│ FLOOR ACTIONS  [Load Material] [Log Power Interruption] [Log Stock Count]   │
├────────────────────────────────────────────────────────────────────────────┤
│ Completed Today                      Production day 2026-08-18 · all shifts │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │  No batch has been completed on this production day yet. Completing    │ │
│ │  one from a machine card above adds it here.                           │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
│  (no figure tiles — there is nothing to total)                              │
│  (no Needs Attention section — an empty one every day is one nobody reads)  │
└────────────────────────────────────────────────────────────────────────────┘
```

**Before**, for the same floor: no date, no counts, a `size="large"` radio bar,
and ten cards each showing a full-width **red** `Report Down` as their only
button — with Start Batch discoverable only by clicking the card background.

### 2.2 State B — mixed Running / Idle / Down (mid-shift)

```
┌────────────────────────────────────────────────────────────────────────────┐
│  Shift Floor                                        ┌───────────────────┐  │
│  Production day 2026-08-18 · B 14:00–22:00          │  A  │  B  │  C    │  │
├────────────────────────────────────────────────────────────────────────────┤
│  ▎6        ▎2        ▎1         ▎1          ▎1          ▎2                 │
│   ▶Running  ⊖Idle     ⚠Down      ⇄Mold ch.   ⟳Not        Needs             │
│   (green)   (grey)    (red)      (amber)     handed over  attention →      │
├────────────────────────────────────────────────────────────────────────────┤
│ ┌───────────────────┐ ┌───────────────────┐ ┌───────────────────┐          │
│ │▎ASB-1   ▶ Running │ │▎ASB-2      ⚠ Down │ │▎ASB-3  ⇄ Mold ch. │          │
│ │ 1L-OVAL-PET       │ │ Hydraulic hose    │ │ 500ML-RND →       │          │
│ │ 1 Litre Pet …Ovel │ │ burst on clamp    │ │ 1L-OVAL-PET       │          │
│ │ Batch B-2481 ·    │ │                   │ │                   │          │
│ │ started 14:12     │ │ Since 16:04       │ │ Since 15:47       │          │
│ │ ┌───────────────┐ │ │                   │ │                   │          │
│ │ │Expected shift │ │ │                   │ │                   │          │
│ │ │≈ 13,333 Nos.  │ │ │                   │ │                   │          │
│ │ │2.16s × 4 cav  │ │ │                   │ │                   │          │
│ │ │× 8 h          │ │ │                   │ │                   │          │
│ │ └───────────────┘ │ │                   │ │                   │          │
│ │[Complete Batch]   │ │[Close Breakdown]  │ │[Finish Mold Chg.] │          │
│ │───────────────────│ │  (red primary)    │ │                   │          │
│ │Hand  Cancel  Rep. │ │                   │ │                   │          │
│ │Over  Batch   Down │ │ (no Report Down — │ │ (no secondary row │          │
│ └───────────────────┘ │  already down)    │ │  while changing)  │          │
│                       └───────────────────┘ └───────────────────┘          │
│ ┌───────────────────┐                                                      │
│ │▎ASB-4 ⟳Not handed │  ← gold rail, tooltip AND tap both say:              │
│ │ over              │     "ASB-4 is running the A shift's batch. Complete   │
│ │ Running for A     │      it from the A tab, or hand it over to B first."  │
│ │ shift — not       │                                                      │
│ │ handed over       │     No primary CTA. Hand Over Shift takes primary     │
│ │[Hand Over Shift]  │     weight — it is the one action left worth taking.  │
│ └───────────────────┘                                                      │
└────────────────────────────────────────────────────────────────────────────┘
```

**Note — expected, but no actual and no progress bar.** The server returns
`null` metrics for an in-progress batch, so a Running card carries the frozen
standard's projection and nothing else. See `UX-SHIFT-FLOOR-2026-08-18.md` §4.1
and §6.

### 2.3 State C — Completed Today, populated, with correctable records

```
├────────────────────────────────────────────────────────────────────────────┤
│ Completed Today                      Production day 2026-08-18 · all shifts │
│ ┌────────┐┌──────────┐┌──────────┐┌───────────────┐┌──────────┐            │
│ │Batches ││Good      ││Expected  ││Output vs exp. ││Reject    │            │
│ │   7    ││ 71,240   ││ 74,500   ││    95.6 %     ││   980    │            │
│ │        ││   Nos.   ││   Nos.   ││ (2 left out)ⓘ ││   Nos.   │            │
│ └────────┘└──────────┘└──────────┘└───────────────┘└──────────┘            │
│   ▲ unit READ from the item master, not the word "pcs"                      │
│   ▲ if today's batches disagree on unit, these four are replaced by:        │
│     ┌──────────────────────────────────────────────────────────┐           │
│     │ Day totals — Not totalled: today's batches are measured   │           │
│     │ in Nos. and kg. Per-batch figures are in the table below. │           │
│     └──────────────────────────────────────────────────────────┘           │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ Machine│Shift│SKU        │Expected│Good  │Reject│Eff. │Status │        │ │
│ │ ASB-1  │ A   │1L-OVAL-PET│ 13,333 │12,980│  180 │97.4%│Pending│[Carton │ │
│ │        │     │           │        │      │      │     │       │ labels]│ │
│ │        │     │           │        │      │      │     │       │[Open]  │ │
│ │ ASB-5  │ A   │500ML-RND  │ 21,600 │20,900│  340 │96.8%│Approved        │ │
│ │        │     │           │        │      │      │     │       │[Carton]│ │
│ │ …                                                                      │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
├────────────────────────────────────────────────────────────────────────────┤
│ Earlier batches — still correctable   2 batches · quality has not checked   │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ B-2477   ASB-7 · 1L-OVAL-PET · 2026-08-17 C · 12,410 Nos.              │ │
│ │                                            [Carton labels]  [Open]     │ │
│ ├────────────────────────────────────────────────────────────────────────┤ │
│ │ B-2478   ASB-9 · 500ML-RND · 2026-08-17 C · 19,880 Nos.                │ │
│ │                                            [Carton labels]  [Open]     │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
│   ▲ NEUTRAL. Nothing is wrong with these. Ordinary history with a door.     │
├────────────────────────────────────────────────────────────────────────────┤
│ Needs Attention · Corrections Required        1 batch sent back by quality  │
│  Sent back by quality — correct and re-submit (1)                          │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ (amber) B-2469  ASB-3 · 1L-OVAL-PET · 2026-08-17 B                     │ │
│ │ "Carton count does not match the packing slip — recount cartons 4–9."   │ │
│ │ Recorded: 11,200 Nos. · 268.8 kg                                       │ │
│ │ [Correct this batch]                                                   │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
│   ▲ EXCEPTION. Present only because the SERVER says awaiting_correction.    │
└────────────────────────────────────────────────────────────────────────────┘
```

### 2.4 Tablet (768–1199px) and mobile (<768px)

```
TABLET  ~820px                          MOBILE  ~390px
┌───────────────────────────┐           ┌──────────────────┐
│ Shift Floor    [A][B][C]  │           │ Shift Floor      │
│ Production day 2026-08-18 │           │ Production day   │
├───────────────────────────┤           │ 2026-08-18 · A   │
│ ▎6  ▎2  ▎1  ▎1            │           │ ┌──────────────┐ │
├───────────────────────────┤           │ │ A │ B │ C    │ │ ← block
│ ┌─────────┐ ┌─────────┐   │           │ └──────────────┘ │
│ │ ASB-1   │ │ ASB-2   │   │           ├──────────────────┤
│ │ Running │ │ Down    │   │           │ ▎6      ▎2       │
│ │[Complete│ │[Close   │   │           │ Running  Idle    │
│ │  Batch] │ │  Break] │   │           │ ▎1      ▎1       │
│ └─────────┘ └─────────┘   │           │ Down     Mold ch │
│   3 per row (md=8)        │           ├──────────────────┤
│                           │           │ ┌──────────────┐ │
│ Completed Today: table    │           │ │ ASB-1 Running│ │
│ scrolls inside its own    │           │ │[Complete Bat]│ │
│ container, page does not  │           │ └──────────────┘ │
└───────────────────────────┘           │  1 per row (xs=24)│
                                        │ Completed Today: │
Breakpoint: NARROW_QUERY =              │ card list, not a │
'(max-width: 767px)'. A tablet is       │ wide table       │
NOT narrow — it keeps the table.        └──────────────────┘
```

---

## 3. Keyboard and accessibility map

| Element | Keyboard | Notes |
|---|---|---|
| Shift `Segmented` | `Tab` to it, `←`/`→` between A/B/C | AntD roving tabindex; same `onChange`. |
| Needs attention chip | `Tab`, `Enter` | Bare anchor → explicit `:focus-visible` ring in `index.css`. Scroll honours `prefers-reduced-motion`. |
| Machine card primary CTA | `Tab`, `Enter`/`Space` | A real `<button>`. The card's own `onClick` is a *pointer* convenience; the button is the keyboard path to the identical `primaryClick`. |
| Card secondary actions | `Tab` in DOM order: Hand Over → Mold Change → Cancel Batch → Report Down | All `size="large"` (40px). |
| Floor actions | `Tab`, `Enter` | 40px. |
| Table row controls | `Tab`, `Enter` | `Carton labels`, then `Open`. |
| Modals / drawers | `Esc` closes; focus trapped; returns to trigger on close | AntD `Modal`/`Drawer` default behaviour, unchanged. |
| Status | Never colour alone | Icon + word + colour, from one shared `STATE_STYLE`. |

---

## 4. Refused at wireframe stage — would have needed a rule change

| Wanted | Why not |
|---|---|
| Live **Actual** and a **progress bar** on a Running card | `productionMetrics()` returns `null` until `batch_status === Completed`; `quantity_produced` is null until the completion drawer is submitted. Deriving one from elapsed time would put an invented factory quantity in the same visual slot as real ones. |
| Efficiency on a Running card | Same — no server metrics before completion. |
| Reject figure for the shift in progress | Rejects are entered at completion. |
| A date picker in the header | The production date is derived from the shift clock; making it selectable changes which day every read and every Start Batch files under. |
| KPI tiles that filter the grid | A second, competing way to answer "what is on this machine". Out of scope for a presentation pass. |
| Treating `Nos.` and `pcs` as one unit so mixed days can still total | That is a factory-data decision for the owner to record, not a display function's to assume. Recorded as a question instead. |
| A single "output vs expected" ratio on a day with a mass-denominated product | `metrics.expected_pieces` is a piece count while `quantity_produced` carries the item's own unit. No such product exists in this catalogue today; classifying a unit as mass-or-count is a backend rule (`BatchEstimationService::isMassUom`), not something to mirror in a tile. The ratio is therefore shown only on a single-unit day. |
