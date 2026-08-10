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
Q23 is claimed by open PR #141 (whose Q24 already appears below, narrowed
by DEC-20260807-006 — the narrowed wording wins at merge); Q21/Q22 are
resolved below; new questions continue from Q27. The same merge-time rule
governs DECISION ids: `record_decision.py` assigns the next free id from
the store it runs against, so a branch carrying unmerged records must
re-mint them (same statements and sources) after rebasing onto a main that
already holds those ids — validation refuses duplicates. The defect class
is one and the same: any serially-numbered id minted on parallel branches
collides, so assignment happens against the MERGED view — check every open
branch for the highest id before minting, or re-mint at merge time (this
happened again with DEC-20260807-001.., three branches deep, 07-Aug).
DEC-20260807-014 is the granularity-flip execution record on main;
PR #148's colliding -014 was re-minted as -015 at merge, per this rule.
Q29-Q31 are claimed by open PR #155 (finance-pull discovery); Q32 by the
report-down backdate PR (#159). New questions continue from Q33.
DEC-20260810-001 landed with PR #158 (carton trace, minted first); PR
#160's colliding record re-minted as -002 at merge, per this rule.

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

## Q5 · The FINAL CARTON — which Tally item, and confirm one per batch — RESOLVED

The standing line exists on every boxed batch (PR #136) and posts nothing
until named. Also confirm the count: built as ONE per batch from the owner's
"one final box for all the batches completion" — editable on the row.
New evidence 07-Aug: Tally has per-product "`<product>` Master Box" items —
the photographed consumption screen books `200 Ml Round Master Box` in Nos
(`docs/factory/sources/paper-reports/tally-consumption-screen.jpg`) — so
the answer may be per-product master-box items rather than one generic
carton item. **Blocks:** the final-carton line posting.

**Resolved 2026-08-07 by supersession — DEC-20260807-015 (superseding
DEC-20260806-006): there is no per-batch final carton; the standing line is
removed, so no Tally item needs naming.** Was open since 2026-08-05.

## Q6 · The POLYMER COVER — which Tally item, and what does one weigh? — RESOLVED

Same standing line. Needs the item name AND grams per cover (the counted
sheet gives 11/kg → 90.9 g, 25/kg → 40 g, 20/kg → 50 g, 15/kg → 66.7 g — say
which, or weigh it). **Blocks:** the polymer-cover line posting.

**Resolved 2026-08-07 by supersession — DEC-20260807-015 (superseding
DEC-20260806-006): there is no per-batch polymer cover; the standing line is
removed, so neither the item name nor a per-cover weight is needed.**
Was open since 2026-08-06.

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
pinned to one? New evidence 07-Aug (owner, chat with his reviewer),
supporting DEC-20260805-002: at goods receipt the store books Reliance
deliveries as "Relpet" and everything else as normal PET resin ("PET
Polyster Chips") — a booking convention at inward, not a per-product
default, so this question stays open. **Blocks:** nothing hard —
suggestion quality only. *Open since 2026-08-05.*

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
trust. *Open since 2026-08-05.* **Partially resolved 2026-08-07** — the
COUNT half: DEC-20260807-010 chose per-shift (~3/day) with the accountant's
one-per-day practice explicitly considered. Still open: the accountant
hearing it from the factory before the flip day.

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

## Q15 · Consolidated shift voucher — which release rule? — RESOLVED

**Resolved 2026-08-07 by DEC-20260807-010 (per-shift granularity) and
DEC-20260807-011 (release when shift end has passed AND ≥N idle minutes
since the voucher's last merge, N default 15; manual accountant override
kept; tray's "Sync Now" unchanged).** The options considered:
`docs/SHIFT-VOUCHER-RELEASE-OPTIONS.md`.

## Q16 · Granularity flip — at which boundary, and after verifying what? — RESOLVED

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
planned. **Blocks:** scheduling the flip.

**Resolved 2026-08-07 by DEC-20260807-014 — by execution: the owner
directed the flip mid-day for the consolidated-voucher demo, overriding
DEC-20260807-010's date-boundary step for this execution. The prior value
WAS read first (dry run: variable absent, effective 'batch') and the
post-write config verified as 'shift', both via the flip-voucher-granularity
workflow.** Was open since 2026-08-07.

## Q17 · Does the accountant preview the consolidated voucher before it posts? — RESOLVED

Ties to Q11 (the accountant's practice is one consolidated journal per
DAY; per-shift consolidation still means 3/day) and to option C in
`docs/SHIFT-VOUCHER-RELEASE-OPTIONS.md`: a server-side release button
rendering the voucher through the existing `VoucherPreviewService` before
it goes. If the accountant wants eyes-on-before-post, the release rule must
include the manual mechanism; if not, a timed rule can run unattended.
Ask the accountant, via the factory (per Q11 — they should hear about the
change before they see it). **Blocks:** choosing between Q15's options.
*Open since 2026-08-07.*

**Resolved 2026-08-07 by DEC-20260807-011 and DEC-20260807-012:** the timed
rule runs unattended, the accountant keeps a manual "Release now" override
(with the existing voucher preview) rather than a mandatory approval step —
the posting gate stays entry-level approval, which remains final.

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

## Q21 · Is the per-machine Bin Bay page dead, or does the floor still use it? — RESOLVED

**Resolved 2026-08-07 by DEC-20260807-006: dead. The floor's only resin
flow is the centralized day bin — one crane-fed loading point, piped to
all 10 machines; the supervisor scans the inward-generated bag barcode at
load, and consumption is derived per batch at completion. The owner's
flow description re-confirms the 2-Aug common-input correction verbatim.
The Bin Bay page and the machine-stamped load path it drove are removed
with this resolution; historical machine-stamped rows stay untouched as
audit history.** Was open since 2026-08-07.

## Q22 · Will the common resin input ever be physically counted — even monthly? — RESOLVED

**Resolved 2026-08-07 by DEC-20260807-007: never (owner: "இல்ல, எடை போட
மாட்டோம்"). The day-bin balance stays Σ loads − Σ calculated consumption
with no re-anchor, ever; every screen showing it must present it as an
ESTIMATE that drifts over time, never a counted fact — stock truth comes
from the Tally reconcile (DEC-20260806-009), not this figure. No
count/re-anchor flow will be built. The paper form's own PET RESIN (DAY
BIN) row — blank on all three photographed shifts — already showed the
floor takes no such count.** Was open since 2026-08-07.

## Q23 · What does an empty carton weigh? (tare, for the label's gross weight)

The owner asked for net AND gross weight on the carton label
(DEC-20260807-009). Net is computable — pieces × the run's resolved unit
weight ("one bottle, one weight", DEC-20260805-005). Gross needs the empty
carton's own weight (tare), and no tare figure exists anywhere in the data:
not on the item master, not in the production standards, not in the
workbook columns that were imported. A tare must be WEIGHED and stated, per
carton spec — never estimated. Until then the label prints net weight only
and no gross line at all. **Blocks:** the gross-weight line on the carton
label. *Open since 2026-08-07.*

## Q24 · Resin on the carton label — should the consumed GRADE print? — NARROWED

DEC-20260807-006 settles the larger half of this question as it stands on
PR #141: physical lot segregation is OFF the table — the resin flow is one
crane-fed input piped to all 10 machines, so a bag/lot/batch number on the
carton label has no physical referent, and the 2-Aug common-input model
stands re-confirmed. The ONLY remaining sub-question: should the label
print the resin GRADE the batch actually consumed (Relpet G5801M / PET
Polyster Chips — already on the batch's consumption lines)? Yes or no,
owner's call. (PR #141 carries the pre-decision wording of this question;
this narrowed wording wins at merge.) **Blocks:** any resin line on the
carton label. *Open since 2026-08-07.*

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

## Q27 · Dispatch of not-yet-approved batches — QC-pass required, accountant approval required, or ship-anytime as today?

Dispatch scan now refuses cartons of a quality-REJECTED batch
(DEC-20260807-013), and the scan/lookup shows a pending batch's state
clearly. But a batch that has not yet been through QC or the approval
chain can still be dispatched, exactly as before — the gate was
deliberately NOT tightened without the owner's word. Three options: (a)
require accountant approval before a carton may leave, (b) require only
the QC pass, or (c) keep ship-anytime as today. **Blocks:** nothing —
today's behaviour continues until answered. *Open since 2026-08-07.*

## Q28 · Does the factory want an accounts-payable (vendor payments) build?

The dashboard's payments figure is receivables only — what customers owe
the factory — and says so honestly. The other side, what the factory owes
its VENDORS and when, has no module behind it: nothing tracks vendor
bills, due dates or payments made, so no figure was faked onto the
dashboard (PR #151 review, 09-Aug). If the owner wants vendor payments
visible, that is a real Finance-module build to scope — bills against
POs/GRNs, due dates, payment recording — not a dashboard cell. **Blocks:**
nothing — the dashboard stays receivables-only until answered. *Open
since 2026-08-09.*

## Q32 · ASB-8's mould capability contradicts the 07-Aug paper — which is wrong?

The Start Batch panel (from the machine-capability records) says moulds of
6 or more cavities run only on Machine 10 — not on Machine 8. The 07-Aug
paper report runs 450ML RIB A on ASB-8 at 7 cavities, machine and cavity
count both stated on the sheet. Either ASB-8's capability record is too
narrow or the mould/cavity data behind the 450ML RIB A standard is wrong —
the two cannot both be true. For the standards-corrections pass. The
surfaced note is advisory (the batch records the mismatch, it does not
block), so nothing is stuck — but every ASB-8 450ML run until this is
answered carries a warning that may be crying wolf. **Blocks:** trusting
the capability note on ASB-8 450ML runs; the machine-capability row's
correction. *Open since 2026-08-10.*
