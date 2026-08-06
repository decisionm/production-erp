# Pending owner questions

The questions only the factory can answer. Each entry stays here until the
owner answers it — then it becomes a decision record (see the
`record-factory-decision` skill) and the entry is marked resolved with the
decision id. **Nothing in this file is a fact yet.** An agent that needs one
of these answers must not guess, interpolate, or promote a proposal to an
answer.

Format: `## Q<n>` heading · the question · what is known so far · what it
blocks · open-since date.

Numbering: question numbers are assigned at MERGE time, not at branch time.
A branch adding questions rebases on main first and takes the next free
number — several branches cut from the same main will otherwise all claim
the same numbers (this happened at Q15, four branches deep, 07-Aug).
Q21/Q22 are claimed by open PR #139 and Q23/Q24 by open PR #141; new
questions continue from Q25. The same merge-time rule governs DECISION ids:
`record_decision.py` assigns the next free id from the store it runs
against, so a branch carrying unmerged records must re-mint them (same
statements and sources) after rebasing onto a main that already holds those
ids — validation refuses duplicates.

---

## Q1 · How many metres of tape are in one Tally "No"? — RESOLVED

**Resolved 2026-08-07 by DEC-20260807-005: one No = one roll = 65 metres
(owner-confirmed; the photographed Tally consumption screen books tape in
whole Nos at a per-roll rate).** Tape stays display-only until the posting
work is separately scoped — the decision defines the unit, it does not
flip the switch. Was open since 2026-07-31 — the oldest question here.

## Q2 · Pouch `710 x 610` — is the size real, and how many to the kilogram?

In live standards, absent from the counted dose sheet — and SMALLER than
every size the factory counted (750/780/835 × 610), so no bracket exists to
tempt an interpolation: a real 710 pouch would run MORE than 71 to the
kilogram, outside the counted range entirely. (An earlier wording here
claimed 710 "sits between 750 and 780" — arithmetically false, caught in
review 07-Aug; the file whose contract is "never interpolate" must not set
up a wrong guess itself.) Two answers needed: is `710 x 610` a real pouch or
a transcription slip, and if real, the counted nos-per-kg.
**Blocks:** pouch kg on products carrying that spec. *Open since 2026-08-06.*

## Q3 · `HM 30 x 49` bag — how many to the kilogram?

Never counted. Do not derive from the LD cover of the same size: HM is 200
gauge, LD is 120, and the sheet's own figures (HM 30.5×49 = 90.9 g vs
LD 30×49 = 50 g) prove dimensions don't decide weight. A derived figure went
live for ~30 min on 06 Aug and was withdrawn (PR #128). **Blocks:** dose for
two products (750ML KIDNEY, 500ML KIDNEY LONG NECK). *Open since 2026-08-06.*

## Q4 · `LD 30.5 x 39` was counted (15/kg) but no such item exists in Tally

The catalogue holds LDPE covers in 28.5×38, 29×40, 29×48, 30×49, 20×33 —
no 30.5×39. Either the item exists under another name, or the sheet wrote the
30×49 row twice. **Blocks:** mapping that counted weight to an item.
*Open since 2026-08-06.*

## Q5 · The FINAL CARTON — which Tally item, and confirm one per batch

The standing line exists on every boxed batch (PR #136) and posts nothing
until named. Also confirm the count: built as ONE per batch from the owner's
"one final box for all the batches completion" — editable on the row.
New evidence 07-Aug: Tally has per-product "`<product>` Master Box" items —
the photographed consumption screen books `200 Ml Round Master Box` in Nos
(`docs/factory/sources/paper-reports/tally-consumption-screen.jpg`) — so
the answer may be per-product master-box items rather than one generic
carton item. **Blocks:** the final-carton line posting.
*Open since 2026-08-05.*

## Q6 · The POLYMER COVER — which Tally item, and what does one weigh?

Same standing line. Needs the item name AND grams per cover (the counted
sheet gives 11/kg → 90.9 g, 25/kg → 40 g, 20/kg → 50 g, 15/kg → 66.7 g — say
which, or weigh it). **Blocks:** the polymer-cover line posting.
*Open since 2026-08-06.*

## Q7 · Which masterbatch for White, Black, Green, Yellow?

Amber is answered (DEC-20260806-004). Still ambiguous in the masters:
White (4 candidates — **the journals consume `Master Batch - Pet White`**, a
proposed answer awaiting the owner's word), Black (2), Green (2), Yellow (2).
One `production:map-masterbatch-colour` run each. **Blocks:** pre-selection on
those colour runs (the floor can still pick by hand). *Open since 2026-08-06.*

## Q8 · Which `500ML` tray, and what is `500 Ml PAD`?

Two catalogue items match the 500ML tray spec, and `500 Ml PAD` appears in a
Stock Journal without a definition. **Blocks:** tray mapping for 500 ml
products. *Open since 2026-08-01.*

## Q9 · Relpet vs PET Polyster Chips — which resin is the default per product?

Both are real (journals: Relpet in 24/38, Polyster Chips in 7/38, same rate).
Today the screen suggests by consumption history. Should any product be
pinned to one? **Blocks:** nothing hard — suggestion quality only.
*Open since 2026-08-05.*

## Q10 · Batch #74's resin figure — 357.9 kg does not reconcile

The masterbatch (1.519 kg), pouch (0.3944 kg) and box (4) lines all imply
~12,150 bottles ≈ 61 kg of resin + scrap; the typed resin total was 357.91 kg
— roughly 6× the other lines. Either the resin kg or the produced count is
wrong. **Blocks:** approving #74 with trustworthy costs. *Open since
2026-08-06.*

## Q11 · Tell the accountant: the Day Book is about to get busier

Their practice is ONE consolidated journal per day (owner: "consolidated
one"); the ERP posts one voucher per batch — same stock effect, ~10× the
voucher count. The accountant should hear this from the factory before they
see it, or it reads as a malfunction. **Blocks:** nothing technical —
trust. *Open since 2026-08-05.*

## Q12 · Five floor-process questions asked on 22 Jul, never answered

From `Clarifications-needed.md` (now archived). Some may be settled by how
the app was built — confirm rather than assume:
1. Can there be multiple supervisors in a single shift?
2. Can any supervisor start AND end a batch, or must it be the same person?
3. At shift change, does production continue (handover) or stop?
4. Power readings — per machine or for the whole factory?
5. Can a mould change happen more than once in the same shift?
*Open since 2026-07-22.*

## Q13 · The dose-sheet photographs are not in the repository

The two 06-Aug photos (pouch and cover counts) are cited as evidence by
several decision records but live only in a Downloads folder — the same way
`Transactions.xml` was lost. May they be committed under
`docs/factory/sources/`? **Blocks:** durable evidence for DEC-20260806-001.
*Open since 2026-08-06.*

## Q14 · Should FILE-PATH references be validated the way DEC-/FC- ids now are?

The validator resolves every DEC-/FC- id across records and prose, but a
file-path reference is unchecked free text — and the gap is not
hypothetical: immutable record DEC-20260806-001 cites
`sources/manifest.yaml`, a file the 06-Aug migration renamed to
manifest.json, and validation says sound. (Found by the owner's own
verification, 06-Aug; originally reserved for a third-party review that was
then cancelled, re-homed here 07-Aug so the gap has an owner.)
Three shapes the answer could take: validate path-like references in
EDITABLE prose only, leaving immutable records as dated history; validate
records too and supersede -001 with a corrected reference; or accept stale
paths in records as the price of immutability and say so in
SOURCE-PRIORITY. **Blocks:** nothing operational — a truthfulness gap:
records can point at files that no longer exist. *Open since 2026-08-07.*

## Q15 · Consolidated shift voucher — which release rule?

The owner asked (07-Aug) for ONE Stock Journal per shift and asked
manual-vs-timed release. Shift aggregation already exists in code
(`TALLY_VOUCHER_GRANULARITY=shift`), but without a release rule the agent's
90-second poll freezes each voucher almost immediately and a real shift
still fragments into `-2/-3` follow-ups — the flip alone does not deliver
the ask. Options with costs and edge cases:
`docs/SHIFT-VOUCHER-RELEASE-OPTIONS.md` — A shift-end, B fixed clock,
C manual accountant release, D idle-hold, or A+D with a manual override
(a prior reviewer's recommendation, not a decision). **Blocks:** the whole
consolidation ask — nothing is scoped or built until this is picked.
*Open since 2026-08-07.*

## Q16 · Granularity flip — at which boundary, and after verifying what?

Two parts. (1) The flip should land at a date boundary (before Shift A's
first approval) so no Day-Book date is half batch-shaped, half
shift-shaped — the code guard makes a mid-stream flip safe against
double-posting, so this is a books-legibility choice, not a correctness
one. Confirm the boundary. (2) The LIVE box's current
`TALLY_VOUCHER_GRANULARITY` is unverified: deploy rsync-excludes `.env`,
and the read-only status workflow was unusable during the 07-Aug GitHub
Actions outage. Every artifact points to `batch` (the default; the archived
delivery plan deferred the flip; no record of flipping exists) — but that
is inference, and the value must be READ (SSH grep of live `.env`, or the
`tally-sync-status` workflow once Actions returns) before any flip is
planned. **Blocks:** scheduling the flip. *Open since 2026-08-07.*

## Q17 · Does the accountant preview the consolidated voucher before it posts?

Ties to Q11 (the accountant's practice is one consolidated journal per
DAY; per-shift consolidation still means 3/day) and to option C in
`docs/SHIFT-VOUCHER-RELEASE-OPTIONS.md`: a server-side release button
rendering the voucher through the existing `VoucherPreviewService` before
it goes. If the accountant wants eyes-on-before-post, the release rule must
include the manual mechanism; if not, a timed rule can run unattended.
Ask the accountant, via the factory (per Q11 — they should hear about the
change before they see it). **Blocks:** choosing between Q15's options.
*Open since 2026-08-07.*

## Q18 · Products where the paper form's standards disagree with the workbook master — PARTLY RESOLVED

**Update 2026-08-07, against the photographed 04/05-Aug paper reports and
the owner's confirmations:** the 500 K/Rib row is resolved by
DEC-20260807-001 (a DISTINCT 23 g product, `L. 500 ml Kidney RIB clear
Pet`, missing from the master — not a wrong norm); the 100 RC row by
DEC-20260807-004 (a distinct 12.0 g variant, same reading); the
cycle-time rows (450 Rib C, 60 RA, and every other CT delta) are reframed
by DEC-20260807-003 — the paper CT is the run's dialed-in setting, so CT
differences are observations, not norm errors. The 175 TCC row moved to
Q26 and the 100 Ema row to Q25 as their own questions. **Still open here:**
the pack-count rows — 200 Rectangle/Sanjar (paper 114/570 vs master
92/368) and 180 Hyb (paper 289/box vs master 256).

Comparing the transcribed paper "Ideal" columns (01–04 Aug logbooks,
`docs/factory-paper-entry/Swaashpet_Paper_Reports_Ideal_and_Actual.xlsx`)
against the workbook product master (`product-master-rows.json`, the source
of the ERP's Production Standards) surfaces disagreements that are either a
stale standard or a wrong norm — both poison every consumption-variance and
efficiency judgement for that product:

| Paper product (Ideal) | Paper says | Master says |
|---|---|---|
| 500 K/Rib (ASB-10, all 4 days) | WT **23 g** | 500ML KIDNEY **28–30 g** (no 23 g row) |
| 100 RC | WT **12 g**, 168/tray, 840/box | 100ML ROUND only exists at **12.9 g** |
| 450 Rib C | CT **19.3–21.65 s** | 450ML RIBBED CLEAR CT **16.5 s** |
| 200 Rectangle/Sanjar | **114/tray, 570/box** | 200ML RECTANGLE SANGAM **92/tray, 368/box** |
| 175 TCC (↔ 200CC 43MM NECK?) | WT **20 g**, CT 14.4–15.6 | WT **19.5 g**, CT **missing** |
| 100 Ema Sangam / ENA (WDE) | **144/tray, 720/box** | closest is 100ML BRUTE **144/576** |
| 180 Hyb | **289**/box (pouch) | 180ML HYBRID OLD pouch **256** |
| 60 RA | CT **11.2–12 s** | 60ML ROUND CT **10.8 s** (10 g variant) |

The paper's 23 g kidney weight is self-consistent across every row (the
sheet's own consumption check balances at 23 g), so it is not a one-off
slip — at ~12,500 pcs/shift, 23 g vs 28 g is a ~60 kg/shift difference in
the resin norm on the fleet's highest-volume machine. DEC-20260805-005
makes the paper report the arbiter when weight figures disagree, but these
figures are handwriting-OCR and the workbook's own README says to verify
before live use — so each row needs the factory's confirmation before any
standard is edited. Which column is current, per product?
**Blocks:** honest consumption norms and efficiency figures for these
products; entry of the corresponding 01–04 Aug paper rows. *Open since
2026-08-07.*

## Q19 · Does machine ASB-11 exist? — RESOLVED

**Resolved 2026-08-07 by DEC-20260807-002: no. An extra or unnumbered
"ASB-" row is a SECOND RUN on an existing machine around a mold change —
the photographed 05-Aug A sheet lists ASB-4 and ASB-7 twice with its
mold-change log explaining the ASB-7 pair, and 04-Aug C's unnumbered row
is ASB-3's short EMA Sangam run. The roster stays ASB-1..ASB-10
(DEC-20260806-008).**

## Q20 · Is the paper form's "Ideal CT" the fixed standard, or the cycle time the machine was actually set to that run? — RESOLVED

**Resolved 2026-08-07 by DEC-20260807-003: it is the dialed-in cycle time
of that run — an observation, not a standard. Paper-CT vs master-CT
differences are not norm errors, and efficiency comparisons must treat
the paper CT as the run's observed setting; the ERP's snapshotted
standard CT remains the yardstick.** Was open since 2026-08-07.

## Q21 · Is the per-machine Bin Bay page dead, or does the floor still use it? — GATING

The owner's 2-Aug correction made the resin input COMMON: one input point
for the whole factory, a bag never assigned to a machine or batch (FC-01),
and the Day Bin page and Shift Floor scan write loads with no machine. But
the legacy Bin Bay page still answers at `/production/bin-bay` (unlinked
from the menu, reachable by bookmark or an old tablet), and its write —
`POST /production/bin-bay/load` — REQUIRES a `work_center_id`: every load
through it is machine-stamped, the exact shape the 2-Aug model retired. If
the floor has fully moved to the Day Bin page, Bin Bay should be removed —
its writes contradict the model. If anyone still loads through it, that
contradiction needs an owner ruling, not a developer's guess.
**Blocks (gating):** removal or retention of the Bin Bay page and its write
path, and any cleanup of machine-stamped loads. *Open since 2026-08-07.*

## Q22 · Will the common resin input ever be physically counted — even monthly? — GATING

The owner ruled 31-Jul that the factory takes no bin weight, and the
reconciliation read that compared the estimate against a physical weight
was removed with that ruling. Since then the common-input figure is
Σ loads − Σ calculated consumption from the first load onward, with nothing
to ever re-anchor it: every unscanned bag, spill and calculation drift
accumulates in the figure permanently and silently. Two futures, and only
the factory can pick one: (a) somebody will occasionally weigh or count the
input — even monthly — and a count/re-anchor flow returns, making the
balance honest again after each count; (b) nobody ever will — then every
screen must present the figure as an estimate that drifts over time, never
as a fact. **Blocks (gating):** building any count/re-anchor flow, and the
final labelling of the estimate on the Day Bin screen.
*Open since 2026-08-07.*

## Q25 · The EMA family — which master product(s), or two new rows?

The photographed paper reports run TWO 100 ml EMA variants daily on ASB-3,
both at 12.9 g: `100 EMA (WOR)` at 162/tray, 810/box and `100 EMA Sangam`
at 144/tray, 720/box (04-Aug C shows both around a logged mold change;
04-Aug B runs Sangam; 05-Aug A runs WOR). The product master has NO EMA
rows at all — the closest by pack count is 100ML BRUTE (144/576, which
matches neither). Which existing master product(s) do the two EMA runs map
to — or are both to be added as new rows? **Blocks:** entering the ASB-3
paper rows; EMA consumption norms. *Open since 2026-08-07.*

## Q26 · Is the paper's "175 TC C" the master's "200CC (43MM NECK)"?

Pack counts match exactly — the paper runs 175 TC C at 115/tray, 575/box
every photographed shift, and the master's `200CC  (43MM NECK)` row holds
115/575 (in its pouch columns). But the weights differ (paper 20 g vs
master 19.5 g) and the master row has NO cycle time, so nothing else can
corroborate. One product under two names — with one weight figure to
correct — or two different products? **Blocks:** entering the ASB-9 paper
rows; that row's weight norm. *Open since 2026-08-07.*
