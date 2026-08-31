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
Q29-Q31 landed with PR #155 (finance-pull discovery, merged 16-Aug); Q32 by the
report-down backdate PR (#159). Q33 is claimed by the packaging-Tally-identity
branch (DEC-20260821-001, which supersedes DEC-20260810-003). Q34-Q37 are claimed by the 12-Aug overnight
run (DEC-20260812-001 HRMS/payroll adoption, DEC-20260812-002 PO raised in
the ERP). Q38-Q41 are claimed by the 12-Aug Tally
evidence set and the purchase/tax configuration design. Q42 is claimed by the SKU scheme
design. Q43 is claimed by the Phase 3 sync fix loop (duplicate master names).
Q46 is claimed by the Phase 5.5 fix loop (paper-page ingest and the
estimation version). Q53 is the Phase 7.6 configuration-lifecycle branch's
(four selection-rule deferrals); Q54 is the Phase 7.5 material-flow branch's
(five material-flow questions). Q61-Q62 are claimed by the sales-order
fulfilment branch (may the ERP emit a Tally Sales Order voucher; the
contested-stock hold rule). Q63-Q66 are claimed by the product-identity
branch (Tally GRN usage; purchases without POs; sync cadence; the Tally Sync
sidebar position). Q69-Q70 were claimed by the PR→PO quantity-tracking
branch and are RESOLVED below (DEC-20260831-003 / -004) — the unmerged
sales-order fulfilment branch claims Q69-Q72 for its own four, so whichever
merges second re-mints, and a resolved entry re-mints exactly as an open one
does. New questions continue from Q71.
DEC-20260810-001 landed with PR #158 (carton trace, minted first); PR
#160's colliding record re-minted as -002 at merge, per this rule.
DEC-20260809-002/-003 landed with PR #155 (the finance-pull discovery
answers, merged 16-Aug) and are on main, so 09-Aug ids continue from -004.

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
POs/GRNs, due dates, payment recording — not a dashboard cell.

**Narrowed 2026-08-29 — the BILLS half landed by lead instruction.** The
28-Aug procurement brief from the lead directed an ERP-side supplier-bill
screen, and it shipped (PR #49): bills against POs/GRNs, recorded by
Accounts, finance-gated (FC-06), no Tally posting (that half is the
question below Q67). What THIS question still asks is the rest of
accounts payable — due dates, payment recording, and any dashboard
figure for what the factory owes. Nothing tracks a payment yet, and no
figure was added to the dashboard. **Blocks:** nothing — the dashboard
stays receivables-only until answered. *Open since 2026-08-09.*

## Q29 · Are the regional ledger groups all customers under Sundry Debtors? — RESOLVED

The pulled chart of accounts holds 230 ledgers under "Sundry Debtors" plus
~400 more under region/city groups (Tamil Nadu Region 105, Puducherry
Region 91, Chennai 76, Villupuram 27, Kerala 17, ... — names read as pharma
companies and agencies). The pulled list is flat, so whether those groups
NEST under Sundry Debtors — making them all customers for the Finance
pull's debtor screens and the CRM outstanding figure — is unconfirmed.
**Blocks:** the scope of Phase 1's debtor pull
(docs/TALLY-FINANCE-PULL-DESIGN.md).

**Resolved 2026-08-09 by DEC-20260809-002 — with its nuance intact: the
owner confirmed the regional groups are ALL customers (the business
meaning), so Phase 1 treats them as debtors; whether they technically nest
under Sundry Debtors in Tally's group tree stays unverified until the
first pull reads group parents.** Was open since 2026-08-09.

## Q30 · Sales directly in Tally, and is bill-wise detail on? — PARTLY RESOLVED

Two halves, both for the accountant. (a) The evidence says invoicing lives
in Tally (18 category-split Sales ledgers, regional debtors) while the
ERP's Sales module holds demo-scale data — confirm all real sales are
booked directly in Tally, and whether e-invoicing is in play. (b) When a
customer pays, is the receipt knocked off against specific invoices
(Tally's bill-wise details) or only the running balance? Bill-wise decides
whether per-customer outstanding can show aging/per-invoice detail
(Phase 2) or only a closing balance (Phase 1). **Blocks:** Phase 2 of the
Finance pull design. *Open since 2026-08-09.* **Partially resolved
2026-08-09 by DEC-20260809-003** — half (a): all real sales ARE invoiced
directly in Tally, and e-invoicing (IRN/QR) is not in use today. Half (b),
bill-wise, stays open for the accountant.

## Q31 · Re-share the Transactions export (the 30-Jul file is lost)

`Transactions.xml` (30-Jul, the 38 Stock Journals ground truth) was read
on 05-Aug and then deleted from Downloads — the manifest carries it as
MISSING with re-share already owed. A fresh FULL-period export (all
voucher types, not only stock journals) answers the finance-discovery
questions — which vouchers the accountant actually enters, what a receipt
looks like in their books, bill references — with ZERO live Tally reads,
which matters double under the no-pull rule. Export is an accountant
action inside Tally (Day Book → Export), not an agent read. On arrival it
is committed under docs/factory/sources/ so it cannot be lost twice.
**Blocks:** voucher-type usage counts; Phase 2 receipt design. *Open
since 2026-08-09.* Status 09-Aug: the owner is mailing the accountant for
the full Day Book XML export and for Tally's quietest half-hour of the
working day.

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

## Q33 · 200ML RA — which live standard is it, and which Tally item is the 490 box? — PARTLY RESOLVED

The 07-Aug paper states 98/tray × 5 trays = 490/box for 200ML RA. The Tally
catalogue's candidate item is named “B.200 Ml Round Pet Bottle Amber 18gms -
520 Nos” — a box count the paper contradicts. Either the item name is stale
(Tally renames when the factory changes the pack), or a separate 490 item
exists/should exist, or the paper's 490 is itself wrong.

**The design half is resolved 2026-08-21 by DEC-20260821-001.** Where Tally
carries separate stock items for a finished product's pouch and tray packings,
the ERP represents them as TWO separate finished-product item masters, each
mapped one-to-one to its own Tally stock item — NOT as two packaging identities
under one ERP product, which is what the now-superseded DEC-20260810-003
allowed. For this 200ML Amber case the shape the owner stated is a 520 Nos
pouch product and a separate 490 Nos tray product. That settles the SHAPE only.

**What stays OPEN in this entry — the identities, which nothing in this repo
evidences:**
1. the exact live Tally stock item for the 490 tray — its name and GUID as they
   actually read in Tally, attached here as an artifact. DEC-20260821-001
   records only that the owner says a separate 490 Tally item is available; no
   agent may guess that name or create a Tally master;
2. which existing ERP standard/product the 490 tray actually belongs to, and
   how the already-configured 98 × 5 = 490 packing rows listed below relate to
   the separate tray product master the rule now calls for. Whether any master
   must be created, renamed, or left exactly as it is is NOT decided by
   DEC-20260821-001 and is not inferable from this repo.

**Corrected 2026-08-11.** An earlier version of this entry said a 490/box
variant “now exists” because DEC-20260810-003's data migration created one.
It did not. On live that migration matched nothing and did nothing:

- there is **no standard named `200ML RA`** on live — the 200ML family is
  BRUTE / DOME / KOREAN / ROUND (read twice on live 11-Aug ~22:52 IST:
  `/production/standards`, 79 rows; `/standards/coverage`, 80 rows);
- the migration therefore applied as a no-op (6.20ms, deploy run
  31517927025 attempt 3).

**But the 490 packing already exists, under ROUND, and predates all of this.**
Five `200ML ROUND` standards carry `tray 98/tray × 5 = 490/box`, every one
with its Tally identity **NULL** (unset, not guessed):

| standard | packaging | created | default? |
|---|---|---|---|
| 48 | 64 | 2026-07-31 | no |
| 62 | 82 | 2026-07-31 | no |
| 63 | 84 | 2026-07-31 | no |
| 100 | 124 | 2026-08-03 | **yes** |
| 101 | 125 | 2026-08-03 | **yes** |

So the paper's packing is already configured — just under a different
product name than the paper uses.

**The question for the owner:** *is the paper's “200ML RA” the `200ML ROUND`
standard — and if so, which live Tally stock item is the 490 box?* The answer
names the tray product's one-to-one Tally identity under DEC-20260821-001, and
the identity is then attached from the named Tally item by a person, citing the
evidence. Nothing about the 490's Tally name follows from the already-configured
490 packing rows below.

Evidence supporting the ROUND reading — **verified, in-repo or read on live:**
- The candidate Tally item is “B.200 Ml **Round** Pet Bottle **Amber** 18gms
  - 520 Nos”. Round + Amber is the natural expansion of “RA”.
- Live's 200ML family contains no other Amber-round candidate.
- The exact 98 × 5 = 490 spec already sits on 200ML ROUND (table above).

Also in the repo, and cutting the other way on machine attribution:
- DEC-20260807-002 records a mold-change log entry `200 rectangle A 20g →
  200 RA 20g` on **ASB-7** (05-Aug A sheet) — the only “200 RA” attestation
  in the repo, and it is on ASB-7.

**Relayed via the owner 2026-08-11, artifact NOT yet in this repo** — recorded
as relay, not promoted to evidence, and not to be acted on until sourced
(AGENTS.md: a factory claim needs an artifact):
- that the 07-Aug paper rows are ASB-6 “200 ML RA” and ASB-7 “200 ML BA”
  (`ASB-6` and `200 ML BA` return zero hits repo-wide, and note the tension
  with DEC-20260807-002 above, which puts 200 RA on ASB-7 — different sheet
  and date, so not a contradiction, but not corroboration either);
- that a 10-Aug comparison matched ASB-7's “BA” to “B.200 Ml Brute
  Amber-18gms”, making BA = Brute Amber and RA not-Brute (`Brute Amber`:
  zero hits);
- that the owner answered on 10-Aug that ASB-6 runs the CT-16.50 product
  “Amber 18gms-520 Nos” in the machine-setting answers — DEC-20260810-002 is
  that record and covers only 60ML Liquor Clear on ASB-2 and B.100 Ml Round
  Pet Bottle Clear-12.9gms on ASB-1; no ASB-6, and `CT-16.50` returns zero
  hits.

If the paper or workbook backs those three, the source belongs in
`docs/factory/sources/` and this entry should cite it.

**Do NOT re-key the migration to a guessed product name.** Once the owner names
the standard and the Tally item, a person sets the identity on the record — not
a migration matching a string. Re-keying it to `200ML ROUND` would also create a
SECOND 490 spec beside the five that already exist. DEC-20260821-001 makes this
warning stronger, not weaker: a two-product split executed on a guessed name
mints a WRONG master, where the old design at worst left a field unset.

**Blocks:** the 490 tray posting under its own Tally name, and therefore any
build of the separate tray product master DEC-20260821-001 calls for. Both
identity questions above stay OPEN — the exact live Tally stock item for the
490 tray (its real name and GUID, still unevidenced in this repo and never to
be guessed), and which existing ERP standard/product the 490 tray actually
belongs to.

**Corrected 2026-08-21 — what this means for production.** An earlier wording
here ended this entry with “nothing else — batches record normally against the
product's identity”. That is unsafe under DEC-20260821-001: with no separate
tray product master built, a NEW 490 tray batch falls back to the 520 pouch
product identity and reproduces the exact wrong Tally mapping the decision
exists to prevent. The correct reading:

- **Once the application is aligned to DEC-20260821-001, no NEW 490 tray batch
  may be selected, completed or queued under the 520 pouch product identity.**
  Until the tray identity is evidenced and its own master exists, there is
  nothing correct for such a batch to record against.
- **Existing posted vouchers remain historical and untouched.**
  DEC-20260821-001 rewrites nothing already posted.
- **Correctly identified 520 pouch production may continue** under its own
  product identity, unaffected by any of this.
- **Corrected 2026-08-23: a forward guard exists on the product-split branch
  (PRs #10–#13, unmerged), and it does NOT reach this case.** This entry has
  been burned by a "now exists" once already — see the 2026-08-11 correction
  above — so the guard is named against the branch that carries it, not
  against a date. On `main`, and on live, there is no such guard at all.
  Where the branch does run, it refuses, at Start Batch, a
  packing whose OWN Tally identity names a different stock item from the
  product being run, and lists the same pairs in the configuration review.
  Two limits keep the 490 tray outside it. Neither is a defect — both are
  deliberate — but neither may be read as the 490 being protected:
  - **The five 490 packing rows above carry Tally identity NULL.** The
    predicate is a DISTINCT, non-null identity
    (`ProductVariantService::identityConflictsWithProduct`), so a NULL row
    raises nothing and still resolves to the product's own item: a new 490
    tray batch continues to fall back to the 520 pouch identity — the exact
    outcome this entry says must not happen. The configuration review does not
    surface these rows on the identity gap either: `packaging_no_identity`
    keys on the RESOLVED identity, which for a NULL row is the product's own
    item — so wherever that item carries a Tally GUID, nothing reads as
    missing. (Whether it does is a live fact, not counted here.)
  - **The guard is forward-only, at Start Batch.** It deliberately does not
    fire at completion or amendment, so an entry recorded before the rule
    stays completable. Of "selected, completed or queued" above, only
    selection is guarded, and only for the distinct-identity shape.
- **So the block this entry describes is not in force.** Nothing shipped
  refuses a new 490 tray batch under the 520 pouch identity, and the five
  packing rows remain exactly as they are, Tally identity NULL. Closing that
  needs the two identity answers above first: a guard cannot be keyed to a
  Tally stock item nobody has named, and no agent may name one.

*Open since 2026-08-10; evidence corrected and extended 2026-08-11;
design half resolved by DEC-20260821-001 on 2026-08-21, the two identity
questions above still open; the posting block clarified 2026-08-21; the
shipped guard's actual reach corrected 2026-08-23.*

## Q34 · HRMS/payroll policy — the seeded defaults are conventions, not the factory's rules

DEC-20260812-001 seeds leave types, salary components, a simple monthly
structure and current-year leave balances using ordinary Indian factory
standards (CL 12, SL 12, EL/PL 15; Basic + HRA + allowances, PF and ESI as
deductions), explicitly as a STARTING POINT, every row marked as a default
nobody has confirmed. None of the following is answered by those defaults:
which leave types the factory actually gives; days per year for each;
whether unused leave carries forward and with what cap; whether staff are
paid monthly, daily-wage or piece-rate (or a mix by role); the overtime
rate; whether PF and ESI apply and to whom; the payroll period and payday;
and whether payroll should ever post to Tally at all. **Blocks:** nothing
today — the screens work on the defaults and every figure is labelled
unconfirmed. What it blocks is TRUSTING any payroll number.
*Open since 2026-08-12.*

## Q35 · Purchase orders move to the ERP — five things only the owner and accountant can settle

DEC-20260812-002 moves PO raising from Tally into the ERP. Nothing changes
until these are answered, and each one breaks the "one book" promise if
guessed: (a) the CUTOVER DATE — from which day do POs stop being raised in
Tally; (b) OPEN POs ALREADY IN TALLY — do they finish there or move to the
ERP, the half-received ones being the awkward case; (c) whose PO NUMBER is
authoritative, the ERP's or Tally's — if both number independently the two
books disagree on day one; (d) does the accountant want an ORDER voucher in
Tally at all, or only the receipt and the bill — many accountants never use
order vouchers, and if so the build is unnecessary and must not be written;
(e) does a Tally Purchase Order voucher need the Purchase ledger mapped
(currently unmapped) or only the vendor ledger. **Blocks:** the
enqueuePurchaseOrder build — (d) blocks whether it is written at all.
*Open since 2026-08-12.*

## Q36 · Which half-hour of the working day is Tally quietest?

For the accountant. The resumed finance pull (its design reached main with
PR #155 on 16-Aug; the DEC is still pending) executes ONE deliberate,
human-triggered read inside a quiet window,
because the 8-Aug-2026 corruption came from a read. The window cannot be
chosen by an agent or inferred from code — it is a fact about how the office
works. **Blocks:** scheduling the first real customer-outstanding pull; the
build and review can proceed without it. *Open since 2026-08-12.*

## Q37 · Will the factory record enquiries and quotations at all?

CRM stays hidden (DEC-20260812-001) until the factory records its first real
enquiry, because opening Leads, Opportunities and Quotations empty is exactly
the 05-Aug complaint the adoption rule exists to prevent. The module is fully
built and its enums already match the standard sales pipeline — nothing needs
seeding. What is unrecorded, in either direction, is whether this factory
will use it. Related and equally unanswered: what the factory's real enquiry
SOURCES are (the proposed suggestion list — referral, existing customer,
phone enquiry, trade enquiry, exhibition, website — is a guess at a small
manufacturer's channels, not a SWAASHPET fact), and whether "which orders
came from quotations" needs to be answerable, which would require a real
quotation_id column rather than today's free-text note. **Blocks:** adopting
CRM; nothing else. *Open since 2026-08-12.*

## Q38 · May the raw Tally exports be committed to the repo?

The 12-Aug Tally export set (Day Book 107 POs, PO pending register, 145
Receipts, Statistics screen) is the evidence several questions have waited for.
It is registered in `sources/manifest.json` with sha256 pins and copied durably
outside the repo — but NOT committed, because the files carry purchase RATES
and private Tally contents, and AGENTS.md forbids putting those in
documentation (FC-06). That leaves them in exactly the status the dose-sheet
photos have held since 06-Aug (Q13): pinned, described, and still one disk
failure from the silent loss that destroyed the 30-Jul `Transactions.xml`.
The repo is private, which may make committing them fine — but that is the
owner's call and an agent must not make it. **Blocks:** nothing technical; the
findings are recorded and citable either way. What it blocks is the evidence
being as safe as the repo is. *Open since 2026-08-12.*

## Q39 · How is the purchase ledger chosen, per line?

HALF OF THIS IS NOW MEASURED AND NEEDS NO ANSWER. Parsing the 92 Day Book
vouchers: 90 of 92 conform to `Local ledger <=> party state is Puducherry <=>
CGST+SGST` and `Interstate <=> any other state <=> IGST`. The company's state is
Puducherry and local-versus-interstate follows from the party's state. (The two
exceptions: voucher 57 — one Tamil Nadu party, named in the source export, not
here (FC-06) — carries a LOCAL ledger with IGST — a mis-keyed ledger, and an ERP enforcing the rule would refuse
to reproduce it; voucher 72 is the cancelled one.)

WHAT REMAINS UNANSWERED IS THE RATE, and the obvious rule is measurably WRONG:
9 of 43 items appear under BOTH 5% and 18%, and 3 of 20 vendors use both, so the
rate is a property of neither. What the data does show is that 5% appears only
in April, May and June 2026 — July and August are 18% only. That is consistent
with a GST rate change effective around July, but it is an inference, and it
could equally be a reclassification or earlier mis-keying since corrected.

**Tally already holds the codes that answer this.** The Day Book carries HSN
per stock item (`GSTHSNNAME`): paper and paperboard — 4819.10.10, 48191010,
4808 — against plastics — 39232100, 39233090, 39076190, 39012000. The items
that appear at BOTH rates are exactly the paper ones (200 Ml Brute Tray, 500ML
IFF Tray, the 170/100/30/15ml Master Boxes). That is the published rate change
visible in the factory's own data. It does not close this question — the
accountant still confirms, and the July cut-off still needs explaining — but the
answer can now be given from codes rather than memory.

So, for the accountant: **why did 5% stop after June?** And is the rate to be
taken from the invoice date, the item, the vendor, or somewhere else entirely?
Also still needed: is the Rounding Off ledger always that name, and is it
produced by Tally or entered by hand? **Blocks:** the per-rate purchase ledger
mapping, and therefore any ERP-raised purchase voucher. Whatever the answer, the
ERP must NOT hold one GST rate per item — the rate that applied in April is not
the one that applies now. *Open since 2026-08-12.*

## Q40 · On a dual-unit line, which unit is authoritative?

28 of 382 purchase-order lines carry two units — trays and covers are bought by
weight and counted in pieces. The ERP's line holds ONE quantity and shows no
unit at all. Before dual units can be held honestly the factory must say which
side governs: what the supplier is paid on, what the receipt is matched against,
and what reaches stock. A conversion factor per item would also have to come
from the factory and never be derived. **Blocks:** dual-unit support; does NOT
block making the single unit visible, which is proceeding. *Open since
2026-08-12.*

## Q41 · Is GST filed from Tally or from the ERP?

This scopes a real defect rather than deciding whether to fix it. The ERP's
GSTR-1 report (`GstReportService::gstr1()`) recomputes EVERY issued invoice's
tax from the current `gst_rates` row on every call — its own docblock says it
is not period-filtered and "covers all issued invoices to date". Since
`gst-rates` exposes an update route, editing one rate silently restates the
computed tax of every invoice ever issued. The mechanism is established from
the code and is not in doubt.

What is in doubt is the impact, and it turns entirely on this question. Live
holds **one** invoice (issued, 22-Jul-2026) against Tally's 553 receipts, and
the owner-confirmed record DEC-20260809-003, on main since PR #155 merged,
states that ALL real sales are invoiced directly in Tally and the ERP Sales
module is demo-scale. On
that reading the ERP's GSTR-1 is a report over demo data that nobody submits,
and this is a latent defect. If anything is ever filed from the ERP, the same
mechanism is a wrong statutory return.

**Blocks:** nothing today — the fix (effective-dated rates, resolved as at the
document's date) proceeds regardless and is already agreed. What the answer
changes is urgency, and whether this must be closed BEFORE the ERP could ever
become the invoicing system — which is itself an open owner decision.
*Open since 2026-08-13.*

## Q42 · What is the SKU FOR? — RESOLVED

644 of 655 items carry a SKU that is a copy of the item NAME — the Tally masters
pull filled it that way. The team wants real SKUs, and the format follows the
purpose rather than the other way round, so the purpose has to be stated first.

The catalogue already carries FOUR identities: `sku`, `tally_stock_item_guid`,
`hsn_sac_code`, and the carton/bag barcodes (`LOT{id}-B{seq}`,
`{batch}-C{nn}`). A fifth without a stated job is one more thing to keep in
sync. Which is it:

- an INTERNAL reference — short, human-typable, stable, collisions matter more
  than readability;
- a BARCODE — must be scannable and unique, and should agree with the existing
  carton/bag scheme rather than compete with it;
- CUSTOMER-FACING — it would appear on invoices and delivery notes, making it a
  commercial decision rather than a technical one;
- TALLY MATCHING — already solved by the GUID, which 644 of 655 items carry, so
  a SKU built for this would duplicate an identity that already works.

**RESOLVED 2026-08-13 by the owner: INTERNAL MAPPING AND EASIER LOOKUP.** Not
customer-facing, not a barcode, not a Tally key (the GUID already solves that).
The driver is a real complaint from 11-Aug — a product could not be found in a
picker because "B.450ML" carries dots and spaces that break search — so the
format is chosen for prefix search. Proposed shape recorded in
`docs/SKU-SCHEME-DESIGN.md` for the owner to confirm against the file:
TYPE-SIZE-SHAPE-COLOUR-WEIGHT, uppercase, hyphens only, three-digit zero-padded
size, weight without a decimal point. Hard rule: no generated SKU may begin with
LOCAL-, asserted in a test rather than a comment. Was open since 2026-08-13.

## Q43 · Duplicate master names — should the ERP BLOCK approval, or only warn?

`items.name` and `warehouses.name` carry no unique index, so two ERP items (or
two warehouses) can share one name. A Tally voucher carries NAMES, not ids, so
Tally would match ONE of them by name — and the ERP cannot say which. Today
(Phase 3) the pre-approval voucher preview BLOCKS such a line — fail-closed —
with the problem "N items in this ERP share the name "X" — Tally would match one
and this ERP cannot say which; give them distinct names before posting" (and,
when no candidate carries a Tally GUID, the no-identity problem too); the same
for a duplicated warehouse name. This is the behaviour the preview had before
Phase 3 by accident of ordering (it read the FIRST row by name and blocked when
that row had no GUID); Phase 3's shared resolver made it order-independent and,
for one review round, POSTABLE — that gap is closed and pinned
(`VoucherPreviewAmbiguityTest`). The question for the owner: is a duplicate
master name a hard stop on approval (as now), or a warning the accountant may
approve past? Known so far: whether any LIVE master name is currently
duplicated has not been counted (count on the live instance, not here); the sync
page's mapping surface reports such names as `ambiguous` with the count, so a
duplicate would be visible there. **Blocks:** nothing new; keeps the old preview
behaviour (block) until answered. *Open since 2026-08-17.*

## Q44 · ERP sales-document lifecycle rules — draft SO invoicing, drafts in "invoiced", cancellation record

Only matters if the ERP's Sales module is ever used for real: real sales are
invoiced in Tally (DEC-20260809-003), so today these are demo-scale rules. Phase
3.5 (Sales visibility) made the ERP's sales orders / deliveries / invoices
searchable and traceable and wired `cancel` on a sales order; in doing so four
lifecycle rules had to be stated, and each is an ENGINEERING default written in
the code and said on screen — not a factory decision:
(a) a DRAFT sales order may be invoiced today (only a CANCELLED one is refused);
(b) the "invoiced" figure on an order counts every invoice raised in the ERP,
DRAFTS included, and the screen says so beside the number ("a draft is queued
for Tally only once issued");
(c) a sales order can be cancelled only while it is draft or confirmed with
nothing delivered and no invoice (draft included) — a cancelled order then
refuses confirm, delivery and invoice;
(d) a cancellation records neither who cancelled nor why (neither does confirm).
Also noted: a `sales.view` login can now read the carton numbers and batch
numbers of dispatched boxes on a delivery's trace (no lot, no GRN, no rate, no
supplier — DEC-20260810-001 and FC-06 intact); the Production carton endpoints
would not show that login the same. The questions: should a draft SO be
invoicable at all; should drafts count as invoiced; is a cancellation reason
(and actor) wanted; and is the carton/batch read for Sales users acceptable? Any
of these can be flipped without touching data. **Blocks:** nothing — defaults in
force are stated in the UI. *Open since 2026-08-17.*

## Q45 · Must a product standard always keep ONE default packaging?

Phase 5 (Product / SKU configuration) lets one product standard carry two
packings of the same mode with different counts (the case DEC-20260810-003 was
raised for — a 490/box tray beside a 520/box tray). The ERP now enforces AT MOST
one default packaging per standard: setting a new default clears the old one;
clearing the last default is allowed, and then Shift Floor asks the supervisor
"How is it packed?" only when the standard genuinely offers more than one
packing (the rule that already stands). The engineering default in force is
"at most one"; nothing in the ERP picks a default for the factory. The
question: should a standard be REQUIRED to keep exactly one default (so the
floor is never asked), or is asking the supervisor when there is a real choice
the intended behaviour? **Blocks:** nothing — the floor is asked only when there
is a real choice. *Open since 2026-08-17.*

## Q46 · A paper shift page from before Phase 5.5 — which expected-output arithmetic should the ERP show for it?

When a paper shift page from before Phase 5.5 is ingested, should the ERP's
expected-output figure match the paper's WB2 arithmetic (legacy) or the
current engine (v3)? Today the ingest path stamps the current engine.

Phase 5.5 (P5.5-03) made one estimation formula, versioned: a batch is
stamped `calculation_version` at Start and its expected output is computed
under that stamp for ever — `production_v3_unified` floors the cycle count
before the cavities multiply (the engine's rule); every earlier stamp keeps the
inline WB2 workbook arithmetic, unfloored (13,584.91 against 13,580 for
CT 10.6 × 5 cavities × 8 h). The paper-page ingest
(`ShiftPageEntryService::recordRow`) composes the ordinary Start and Complete,
so a page recording a shift that ALREADY RAN — possibly before Phase 5.5 —
is stamped with the current engine, and the ERP's expected figure for that
shift is the floored one, not the figure the paper's own arithmetic would
give. Nothing owner-decided moves (cycle times, cavities, weights, pack
counts are the workbook's); what differs is a few pieces per shift in the
expected column and the efficiency it feeds. The engineering default in force
is "the current engine stamps every new row, whatever date it records";
`EstimationUnifiedTest::test_every_creation_path_in_app_stamps_a_calculation_version`
enumerates the doors. **Blocks:** nothing — pages ingest today; the choice
only decides which arithmetic a back-dated page's expected figure follows.
*Open since 2026-08-17.*

## Q47 · Which rejection figure does the CEC sheet carry — production-side, QC-weighed, or confirmed?

A completed batch carries three rejection readings in the ERP: the
production-side `quantity_rejection_kg` the operator records at completion
(what the Shift Summary sums as `rejection_kg` today), QC's weighed kg from
the quality return, and the `confirmed_rejection_kg` the approval path uses
(QC wins where it exists — it is the one that reaches the Tally scrap line).
Phase 5.7's CEC data endpoint (`GET production/cec`) exposes the production-
side figure as `rejection_kg` (so the CEC reconciles with the Shift Summary
field for field) and QC's beside it as `rejection_kg_qc`; it invents nothing
and adds no arithmetic. When the owner's CEC sample arrives (the format is
still BLOCKED — SOURCE DOCUMENT REQUIRED), the reading guide will name which
column the sheet's "reject" is, and that is the owner's call, not the
composer's. **Blocks:** nothing today — the CEC has no format to fill; it
decides which of the two exposed figures the golden guide maps.
*Open since 2026-08-17.*

## Q48 · After a purchase order reaches Tally, what should reach Tally when the ERP changes it?

Phase 6 stages an ERP-raised PO as a Tally Purchase Order voucher behind a
flag that is OFF until Q35(d) is answered (`tally-sync.purchase_orders_enabled`
— the first live post is attended, never unattended). The ERP lifecycle now
has three changes a buyer can make AFTER a PO was sent: amend (today refused
once sent — Draft only), short-close with a reason, cancel with a reason (only
while nothing was received). Each is an ERP-side record; none of them reaches
Tally, and each would be a NEW category of Tally write if it did:
(a) AMEND a sent order — an Alter of the posted voucher, or cancel-and-re-raise,
or nothing (the vendor holds the amended order on paper only)?
(b) SHORT-CLOSE — a note only, or should the Tally order be closed/altered so
its pending register agrees with the ERP?
(c) CANCEL — cancel the Tally voucher, or nothing?
The ERP already dismisses its own staged (not yet collected) queue entry when
an order is cancelled or closed before the agent collected it — that is the
ERP's queue, not Tally's book. What is asked here is only the Tally side, once
a voucher exists there. **Blocks:** nothing today — the flag is off; it decides
what Phase 6's lifecycle must post the day the flag turns on. *Open since
2026-08-17.*

## Q49 · Is the "type a whole paper page in one go" screen still wanted?

`POST production/shift-production-entries/page` exists and works: a date, a
shift and ten to twelve machine rows in one submit, each row in its own
transaction so eleven good rows are never lost to a twelfth bad one
(`ShiftPageEntryService`). It has tests. It has **no screen** — nothing in the
bundled SPA calls it, so today it can only be reached by an API client.

It was built for a priority quoted in the code's own docblocks — the daily
production entry, each page entered in the app rather than two dialogs per
machine row. That sentence is quoted from a 05-Aug discussion; it is NOT in
`docs/factory/decisions/`, so by this repo's own rule (a discussion is not a
decision) the ERP does not actually know whether the factory wants it. The
question is therefore neither "finish it" nor "delete it" — it is whether the
paper page is still how the floor works now that Shift Floor asks nothing the
configuration already knows:

(a) Does the supervisor still fill a paper page per shift and type it later,
    or is the batch entered on the floor as it happens?
(b) If the page is still real, should the ERP grow the page screen (the
    endpoint is ready), or is the endpoint dead weight to retire?
(c) If a page of a shift that ALREADY RAN is typed in, Q46 already asks which
    expected-output arithmetic it should follow.

**Blocks:** nothing — the endpoint is inert without a caller. It decides whether
Phase 8 builds the screen or the endpoint is retired. *Open since 2026-08-17.*

## Q50 · RESOLVED (17-Aug-2026) — the required provenance chain does NOT conflict with FC-01

The lead asked to see the exact conflict before any owner decision was required. It was
checked line by line against the constitution, and **there is none for the chain as
specified**. This entry is kept as the record of that check; nothing is asked of the owner
and FC-01 is not weakened.

**FC-01 verbatim** forbids exactly three things:
1. "A resin bag must never be represented as physically assigned to a machine or a batch"
2. "scanning or loading a bag is a **pour record, not Tally consumption**"
3. "the system must not claim physical bag-to-machine or bag-to-batch provenance"

**The required chain, link by link:**

| Link | Against FC-01 |
|---|---|
| bag/barcode -> lot | Not a machine, not a batch. **No conflict** — bags and lots already exist. |
| lot -> quantity/weight | **No conflict.** |
| -> material request | **No conflict**, provided a RESIN request names no machine (guardrail 1). |
| -> issue | Store-to-production CUSTODY, not an assignment to a machine or a batch. **No conflict.** |
| -> received by | A person. **No conflict.** |
| -> production consumption / return | **No conflict** — the chain says *production* consumption, the aggregate, not "batch X consumed bag Y". |

Point 2 of FC-01 actively AGREES with the new workflow: it already says a bag scan is an
operational pour record and not consumption, which is exactly the rule that a Store issue
is not a consumption.

**Two guardrails keep it that way, and neither is in the required chain:**
1. A **resin** material request carries no machine or area — all machines draw from one
   common piped loading point (DEC-20260807-006), so the field could not be answered
   truthfully. Requests for film, cartons, tape and other non-common-input consumables DO
   carry a machine or area: "where applicable" is doing real work.
2. The trace from a batch stops at the ISSUE. The ERP says "these bags were issued to
   production, by whom, when, against which request"; it never says "this batch used this
   bag". Batch consumption stays calculated, exactly as FC-01 requires.

**One correction for the record.** The note asked to separate this traceability from
"cost/rate/vendor-sensitive fields protected by FC-01". Those fields are protected by
**FC-06** (purchase rates and supplier identity are Owner/Accounts only), not FC-01 —
FC-01 is solely about bag-to-machine/batch provenance and says nothing about money. The
separation asked for is real and already enforced: the material-flow chain carries bag,
lot, weight, request, issue, people and time, and carries no rate, amount or vendor
identity to a reader without finance standing.

*Opened 2026-08-17; resolved the same day by inspection, no owner ruling required.*

## Q51 · ANSWERED (DEC-20260830-002) · How many stores does the factory actually have, and which rows are they?

The ERP holds five warehouses. Two pairs are functionally duplicated and the
evidence says accidentally so (full audit:
`docs/engineering/AUDIT-WAREHOUSES-2026-08-17.md`):

    RM-STORE "Raw Material Store"   vs   RM "RM Store"
    FG-STORE "Finished Goods Store" vs   FG "FG Store"
    WIP      "Work In Progress"

All five predate the current engineering programme. The `RM-STORE`/`WIP`/`FG-STORE`
three came from a demo-data seeder (19-Jul); `RM` and `FG` came ten days later from
an acceptance-fixture seeder whose own comment says *"Godowns that exist in Tally."*
An archived go-live plan already flagged the overlap and deferred it: *"Consolidate
after go-live; vouchers must reference godown names that exist in Tally."*

Two things make this the owner's call rather than an engineering tidy-up:

1. **The history sits on the wrong side.** In the rehearsal database the stock
   movements, receipts and deliveries hang off the DEMO rows, while the
   Tally-linked identity hangs off the fixture rows. Consolidating therefore means
   REWRITING `warehouse_id` on historical movements, receipts and deliveries —
   altering records of things that already happened, and (once vouchers exist) the
   godown a past line claims to have posted under. This repo does not rewrite
   history on an agent's judgement.
2. **It is already costing something.** Two rows carry a Tally godown id, and the
   resolver that falls back to *the sole* Tally-linked warehouse therefore finds
   two and gives up. That fallback is dead while the pair exists.

What is needed from the owner and the accountant:

(a) How many stores does the factory actually keep, and what are they called in
    Tally? (One godown, or a raw-material and a finished-goods godown, or more?)
(b) For each ERP row above: is it a real place, or residue to retire?
(c) If two rows are the same place, may their historical rows be moved onto the
    surviving row — and on which side? This is the destructive half and needs an
    explicit yes, after a dry run showing exactly what would move.

**Blocks:** consolidating the warehouses, and the Tally sole-godown fallback.
Nothing else — the ERP runs fine with the duplicates, it merely cannot tidy them
safely without this. **Nothing has been merged, deleted or deactivated.**
*Open since 2026-08-17.*

## Q52 · ANSWERED (DEC-20260817-002) · Configuration lifecycle — five things the contract cannot decide for the factory

The lead has formalised a product-wide Configuration Lifecycle Contract (17-Aug-2026):
every master supports Create → View → Edit → Activate/Deactivate → Safe Delete → Audit,
with delete refused by the backend once anything references the record. The audit is
`docs/engineering/AUDIT-CONFIGURATION-LIFECYCLE-2026-08-17.md`. Five points in it are
the factory's call, not engineering's:

(a) **May a configuration record ever be HARD-deleted at all, or is Archive always the
    answer?** The contract says hard delete when genuinely unused, and that is what is
    being built. But a code freed by a hard delete becomes reusable, and a factory that
    reads its own history by code could find one code meaning two things across time.

(b) **When a master is archived, should it keep occupying its business code?** Today it
    does: item SKUs, warehouse codes and vendor codes are unique INCLUDING soft-deleted
    rows, so a retired `ASB-8` blocks a new `ASB-8` for ever. Intended, or should a
    retired code be reusable? (Overlaps Q43 — not decided here.)

(c) **Who may DELETE configuration, as opposed to edit it?** Today one permission per
    module covers every write, so anyone who can edit a machine could delete one. Should
    delete be a narrower grant, the way carton-trace was carved out?

(d) **Does archiving in the ERP mean anything on the Tally side?** An ERP item carrying a
    Tally stock-item id that the factory retires here still exists in Tally. The build
    assumes the ERP flag is purely local and refuses to hard-delete any Tally-linked row
    — an assumption, stated, not a decision.

(e) **A maintenance schedule has no link to the work orders it generated**, so "has this
    schedule ever been used?" cannot be answered from the data. Add the link (a schema
    change on a live table), or make the schedule simply never deletable? Until then the
    dependency report says *cannot prove unused* and refuses — it never guesses.

**Blocks:** nothing immediately — the mechanism is built to refuse rather than guess, and
Archive is always available. (a) and (b) decide how much of the contract's Delete half is
ever switched on. *Open since 2026-08-17.*

## Q53 · Four selection rules WS-B narrowed without a ruling — is each narrowing what the factory wants?

Phase 7.6's WS-B closed eleven `is_active`/`status` flags that were set on
masters but filtered nowhere, so a retired mould and a withdrawn scrap reason
were selectable on the floor. Four of those rules sit on a line only the
factory can draw; the code took the narrowest reading it could defend and
left the wider question here. DEC-20260817-002 settled the DELETE half of the
configuration lifecycle (Q52) — none of these four is covered by it, because
they are about what may be SELECTED, not about what may be destroyed.
**Each is a question. Nothing below is an answer, and the code does not
behave as if one had been given.**

(a) **May an AMENDMENT to a COMPLETED batch keep the scrap reason that was
    live when the batch ran, after that reason has since been withdrawn — or
    must the floor re-pick a live reason to save the amendment?** Today it
    must re-pick: `AmendBatchRequest extends CompleteBatchRequest`, so the
    new active-only rule on `scrap_reason_id` bites the amendment path
    exactly as it bites a first completion. Correcting a typo in a six-week-old
    batch therefore also forces a change of the reason recorded against that
    run.

(b) **May a mould whose status is `under_repair` be scheduled at Start
    Batch, or only an `active` one?** `MoldStatus` has three cases —
    `active`, `under_repair`, `retired`. `StartBatchRequest` refuses only
    `retired`, so an `under_repair` mould can still be picked for a new
    batch. Nobody has said whether a mould in repair is unavailable for
    scheduling or merely flagged.

(c) **Should a retired vendor also block a Tally-MIRROR purchase order
    (`source: tally`), or is mirroring an order Tally already holds always
    permitted?** A new ERP-entered order is refused for a retired vendor; a
    mirror is not, on the reasoning that the ERP reflects Tally's book and
    should not refuse to record what that book already contains. Two facts
    the answer needs: `source` is a plain field in the request body of
    `StorePurchaseOrderRequest` — nothing checks that a matching order
    exists in Tally — so today anyone who may raise a purchase order at all
    can opt out of the retired-vendor rule by sending `source: tally` (the
    route's only gate is the ordinary procurement write permission); and that
    behaviour is pinned by
    `backend/tests/Feature/Procurement/TallyMirrorRetiredVendorBypassTest.php`
    so answering this changes it deliberately rather than by drift.

(d) **The companion to (b): editing a production configuration that still
    names a now-retired mould is currently refused until the mould is
    re-pointed — is that wanted?**
    `StoreProductionConfigurationRequest` serves both `store()` and
    `update()` on `ProductionConfigurationController`, so a PUT that
    re-sends the configuration's existing (now retired) `mold_id` is
    refused; changing the cycle time on such a configuration cannot be saved
    without first choosing a different mould. The alternative — let an edit
    keep a retired mould it already names, and refuse the retired mould only
    on a NEW configuration — is a different rule, not a bug fix, so it is
    asked rather than applied.

**Blocks:** nothing on the floor — every rule above is live and working in
its narrow reading, and history still displays every retired master it
already names. What it blocks is knowing whether the narrow reading is the
factory's. (c) additionally decides whether the `source: tally` route needs a
trust check at all. *Open since 2026-08-17.*

## Q54 · Five things the Store -> Production material flow cannot decide for itself

Phase 7.5 builds the workflow the lead confirmed on 17-Aug (Q50, DEC-20260830-002):
Store Stock -> Material Request -> Store Issue -> Scan/Handover -> Issued to
Production (the WIP location) -> Consumption -> Return unused. Building it raised
five points that are the factory's call, not engineering's. **None of them is
answered here.** Where a question is open the build takes the option that refuses or
reports rather than the one that guesses, and which option that is, is recorded in
the branch's own report — a safe reading of an open question, never an answer to it.

(a) **Is every kg material a "common input" that must refuse a machine, or may
    masterbatch name one?** FC-01 and DEC-20260807-006 fix the resin case: ONE
    crane-fed loading point piped to all ten machines, so a machine on a resin
    request would be a field the floor cannot answer truthfully. Masterbatch is
    dosed per machine, which would make a machine meaningful on ITS request. But the
    only classification the data actually carries is the unit of measure — the
    raw-material picker is literally "active kg-uom items" — so the build treats
    every kg material alike and lets none of them name a machine. That is a
    consequence of the data, not a ruling. If masterbatch, or any other kg material,
    should be allowed to name a machine, the factory has to say which materials
    those are and the ERP needs a way to tell them apart. (Narrower than Q50, which
    asks whether the RESIN flow itself has changed; this asks how the OTHER kg
    materials are classified.)

(b) **When an issue is cancelled, or unused material returned, after a batch has
    already consumed against it — refuse, or cap?** The invariant is not in doubt: a
    reversal may never take back more than is still standing unconsumed for that
    issue, or it drains material that belongs to another issue. Two honest ways to
    meet it, and they differ only in what the storekeeper sees. REFUSE: the return is
    rejected with the reason, and the storekeeper raises the smaller figure himself.
    CAP: the ERP silently returns only what is still standing and tells him it did.
    Refusing never puts a number nobody typed into the ledger; capping never leaves a
    storekeeper stuck at a screen. Which does the factory want at the counter?

(c) **The Start Batch material-availability panel is per-MACHINE — should it stay,
    change, or go?** It reads the bin bay for the machine about to run. With the
    common input (FC-01, DEC-20260807-006) nothing is stamped to a machine any more,
    and material issued to production now stands in one pooled Production/WIP
    location — so a per-machine reading for resin has nothing to report and shows
    zero. The material is real; it simply belongs to no machine. Three options: make
    the panel a factory-wide Production/WIP figure (true, but it stops answering "can
    THIS machine run"), remove it (the supervisor loses a pre-start check he has
    today), or leave it per-machine and accept that it reads zero for pooled
    materials. The gate behind it already fails OPEN and never stops a machine the
    floor can run, so nothing is blocked either way.

(d) **What should the carton trace say about a lot that reached the machine through
    a store issue?** DEC-20260810-001 fixes the internal trace's wording as "the
    common day bin held loads from these lots" — deliberately, so that no
    bag-to-batch claim is ever made (FC-01). Material now also reaches production
    through a store issue, which did not pass through the day bin, so those lots sit
    outside the sentence the owner fixed. An owner-fixed sentence is not rewritten by
    an agent, so the build either keeps store-issue lots out of that attribution or
    gives them their own separately-worded line. The wording of that second line is
    the owner's: "issued from the store on this issue" is EXACT, unlike the day-bin
    attribution which is calculated over a shift window — and a trace that mixes an
    exact statement and a calculated one under one sentence would overstate the
    calculated half.

(e) **May a batch consume more from Production/WIP than was ever issued to it?**
    Consumption is calculated, not counted (DEC-20260807-007: the bin is never
    weighed, so the figure drifts permanently with nothing to re-anchor it), so a
    batch CAN compute a consumption larger than everything the store issued. Two
    readings. REFUSE the completion until the store issues the difference: the books
    never carry material that was never issued, but the floor's paperwork stops on a
    figure nobody counted, at the end of a shift. ACCEPT it and record the shortfall
    as its own visible, named figure the store must make good: the batch closes, and
    the gap is a number somebody has to answer for rather than a silent negative.
    Either way the WIP balance must not quietly go negative and the ledger invariant
    must still sign — that part is engineering's and is not a question. Which of the
    two the factory wants IS.

**Blocks:** nothing immediately — every one of the five has a safe reading already in
force, and the flow (request, queue, partial issue, bag scan, the three stock states,
return) proceeds either way. (a) decides whether a machine field ever appears on a
masterbatch request; (d) decides one sentence on one internal screen; (b), (c) and
(e) decide what the floor and the store see, not whether the material is tracked.
*Open since 2026-08-17.*

## Q55 · Retiring the Day Bin — two things the code cannot decide, and one that must be built first

DEC-20260830-002 settled the inventory locations: **RM Store → Production/WIP → FG Store,
and there is no Day Bin.** A full audit of every remaining Day Bin reference (18-Aug-2026)
found that the *target workflow* is indeed free of it, but the *running system* is not: the
Day Bin is still load-bearing in two places, and one of them prices every batch.

What was removed straight away, because nothing computed depends on it: the left-nav
**"Day Bin"** entry (this is what was still visible on `/production/configuration`, because
the nav renders on every route), the Factory Rules help text that said the raw-material store
is "where material issues from *when the day bin cannot supply it*" — which inverted this very
decision — and **"Day Bin" as a selectable location on a new stock count**. Historical rows,
columns and ledgers were left untouched.

The rest cannot be removed without a ruling.

(a) **Is `production_day_bin_warehouse_id` SET on the live instance?**
    `FactoryWarehouseResolver::consumptionSource()` takes a day-bin branch that decides which
    warehouse a completed batch decrements. When the setting is UNSET the branch is a
    behavioural no-op (both sides fall through to the same sole-Tally-linked warehouse) and
    the branch can simply be deleted behind a test. When it names a DISTINCT warehouse,
    deleting it **moves which warehouse new completions decrement, and therefore the godown
    lineage of new vouchers.** Nobody has read the live value; it is written by exactly one
    endpoint and by no seeder or migration, so it is probably unset — but "probably" is not
    the standard for a change that moves stock. One read settles it:
    `SELECT value FROM app_settings WHERE key = 'production_day_bin_warehouse_id'`.

(b) **The resin cost pool has ONE inflow, and it is the Day Bin scan.**
    `FactoryDayBinService::loadBag()` is the only caller of `ResinPoolService::fold()` in the
    entire codebase, and `completeBatch()` prices every batch's resin out of that pool.
    **The Phase 7.5 store-issue flow prices nothing** — `StoreIssueService` and
    `MaterialRequestService` contain no pool call at all. So retiring the Day Bin scan today
    would not crash anything, which is exactly what makes it dangerous: every batch would
    silently drop from pool-priced to average-fallback or unpriced, and the first sign would
    be costing that quietly stopped meaning what it used to. **A store-issue-side inflow must
    be built BEFORE the scan is retired.** That is engineering work, not a question — but
    whether it is done now or the Day Bin scan stays until it is, is the owner's call.

(c) **Which of the Day Bin's refusals should survive its retirement?** The old refusal set is
    the contract, and a refactor that "adds no new gate" is no defence if the old code already
    refused something. Still live today: the 422 when no bin is configured; the balance
    acknowledgement gate above `machine_balance_ack_kg` (25 kg); the return/count balance
    guards; the block on cancelling a batch that has day-bin movements; and the deliberate
    `null`-not-zero consumption when no closing count exists. Each must either survive in the
    store-issue flow or be consciously dropped.

**Blocks:** the *sighting* is fixed and the target workflow is what the screens now describe.
What is blocked is calling the Day Bin GONE. Until (a) is read and (b) is built, the honest
statement is that Day Bin is retired from the workflow and the UI, and still runs underneath
as the resin costing inflow. No column was dropped and no historical row was touched, so
nothing here is urgent — but batch costing depends on it, so it should not drift unowned.
*Open since 2026-08-18.*

## Q56 · Which items are production materials — the residue the evidence could not answer

The Material Request picker now offers only items configured as production inputs
(`items.is_production_input`), enforced in the API and not merely in the dropdown. The
backfill that set that column derived it from EVIDENCE, never from names: the BOM component
register, the packing-material register, the colourant register, a kg-family unit as a seed,
and — the half that catches consumables no register covers — what the factory has actually
requested, issued and consumed in the past.

Two things follow that only the owner can settle.

(a) **The residue.** Anything in none of those sources is left INELIGIBLE, because guessing
    is the one thing this rule must not do. On the development master that residue included
    **CARTON-24**, which is a perfectly ordinary production input — it simply has no BOM line,
    no packing-register row, and has never been requested or issued in that database. The live
    residue has not been measured and will differ.

    **This is the failure mode that matters**, because it is the one that stops work: a
    material the store needs to hand over that the floor cannot ask for. It is a switch on the
    item, not a code change — but somebody has to look at the list and flip the ones that
    belong. **Please run through the item master once and confirm which items are production
    materials.** Until that happens, the honest statement is that the picker offers what the
    factory has demonstrably used before, and nothing else.

(b) **`BTL-PET-1000 — 1 Litre PET Bottle` is DEMO RESIDUE, and it is still on live.** It comes
    from `BottleManufacturingDemoSeeder` (`uom: 'pcs'`, no Tally stock-item GUID) — a
    never-cleaned-up demo row, which is why it was the example that caught the owner's eye. The
    eligibility rule now hides it from the picker, but **the row is still there** and will keep
    appearing in other item lists. There is precedent for the cleanup and a discipline to copy:
    migration `2026_08_01_120001` exists solely to retire the demo WAREHOUSES, by id and code,
    never by name pattern, and never by deleting anything with history.

    Whether the demo ITEMS should be retired the same way is the owner's call. They must not be
    deleted if anything references them.

**Blocks:** nothing today — the reported defect is fixed and the floor can request everything
the factory has previously used. (a) decides whether any material still needs switching on
before someone finds it missing at the store window; (b) decides whether demo rows stop
appearing in every other item list too.
*Open since 2026-08-18.*

## Q57 · A bag's LOCATION is not maintained — should it be, and what moves it back?

`material_bags.current_warehouse_id` is written once, when the bag is created at goods
receipt, and never again. Nothing in the application updates it when the bag is issued to
production, poured, or returned. So a bag's own row says "in the Raw Material Store" for the
whole of its life, and the question "which bags are standing on the production floor?" has no
answer from the bag itself.

**This was tried and reverted on 18-Aug**, and the reason is the useful part of the record.
Setting the column to Production/WIP on a store-issue bag scan looks obviously right and broke
two things at once:

1. **A part-emptied bag became permanently unissuable.** The scan reads the bag's current
   warehouse as the SOURCE of the transfer. Once the column said Production/WIP, the next
   partial scan of that same bag was refused — "already the Production/WIP location, material
   cannot be issued from production to itself". A storekeeper weighing 20 kg off a 50 kg bag
   could never scan the rest of it. Reproduced empirically.
2. **Nothing can move it back.** A return names an issue LINE and a quantity, never a barcode.
   So a bag would claim to stand on the floor for ever after its kilograms had gone home.

Before the attempt the column was uniformly stale and *known* to be. With the write it became
*confidently wrong*, which is worse for a custody claim.

**What the system does hold, exactly, is the scan record**: `store_issue_bag_scans` says this
bag, this issue, this quantity, this moment. That is genuine custody provenance and is what
the acceptance chain now asserts — four bags received, three scanned onto a handover, and the
fourth provably never handed over.

Three things only the factory can settle:

(a) **Does the floor need to ask "which bags are here?" of the BAG, or is the handover record
    enough?** The scan record answers "which bags were handed over and when". A location
    column would answer "which bags are here right now", which is a different and stronger
    claim — and one the system can only keep true if (b) and (c) are answered.

(b) **What moves a bag back?** A return would have to name the bag, not just the quantity. Is
    the store willing to scan bags on the way back as well as the way out? Without that, any
    location column drifts wrong within a shift.

(c) **What is a part-emptied bag's location?** Twenty kilograms of a fifty-kilogram bag have
    gone to the floor and thirty are still in the store — in the same physical bag. Custody
    and kilograms genuinely disagree here, and the answer is a factory convention, not an
    engineering one.

**Blocks:** nothing. The chain is fully traceable through the scan records today, and the
floor's availability panel reads Production/WIP stock balances, which are correct. What is
blocked is any screen that wants to say "these specific bags are on the floor right now".

**Related:** a fully-poured bag is marked with the status `consumed` by the shared pour path,
on a store issue as well as on the day-bin scan. On a handover that word contradicts
DEC-20260830-002 — the material has been handed over, not consumed. The enum value is shared
with the day-bin path and written into historical rows, so renaming it is a data decision
rather than a code one. Worth settling alongside the above.
*Open since 2026-08-18.*

## Q58 · Units of measure — four things the Tally evidence shows but cannot settle

The 12-Aug export set has been audited for units (`docs/engineering/AUDIT-UOM-2026-08-18.md`).
It settled the big question — the factory measures **26 of its 43 stock items by count, not by
weight**, so nothing may treat "material" as "kilogram" — and a canonical classifier plus
fraction rules are now enforced. Four things remain the factory's to answer.

(a) **The four conversion ratios seen on dual-unit purchase-order lines.** 28 of 382 lines
    display an alternate unit inline: LDPE Cover (30x49x120G) as 1 Kg → 10 Nos, Poly Olefin
    Pouch as 1 Kg → 12 Nos, 500ML IFF Tray as 1 Kg → 50 Nos, and Packing Tape Yellow as
    1 Nos → 1 Pcs. **These are recorded as what the line displayed and have NOT been adopted
    as conversion factors.** Q40 already rules that a conversion factor "would also have to
    come from the factory and never be derived", and this is the same discipline as the bag
    weight withdrawn in PR #128. Are these the factory's real ratios? The 1:1 tape case looks
    much more like a redundant Tally alias than a conversion.

(b) **Two near-identical masters carrying DIFFERENT units.** `500ML IFF Tray` is `Kgs.` and
    `500ML Tray IFF` is `Nos.`. Either they are two genuinely different things, or one is a
    master-data error — and only the factory can say which. Until then any code that resolves
    a tray by name is resolving between two different measurement types.

    **Resolved 2026-08-19 by DEC-20260819-001: a master-data error.** `500ML IFF Tray`
    (id 207, `Kgs.`) is redundant and is ARCHIVED through the configuration lifecycle —
    reversible, deletes nothing, no Tally mutation. The live read that settled it: id 207
    had zero movements and zero on-hand, and was the only one of EIGHT tray masters not in
    `Nos.` No code change followed; the fraction rule was already correct.

    Two things this did NOT settle, and they stay open here:
    **(b-i)** `500ml Tray` (id 220, `Nos.`, 10 movements, 1470 on hand) and `500ML Tray IFF`
    (id 221, `Nos.`, 1 movement, 2000 on hand) may themselves be duplicates of each other —
    the live catalogue carries THREE 500ml tray masters, not the two this question named.
    **(b-ii)** `Stretch Film` is measured in `Nos.` yet carries the only fractional counted
    quantity in the entire database (58.2, an opening balance dated 07-Aug-2026). A count of
    58.2 is not a count. This looks like the same class of unit error, and it is the one live
    row the fraction rule would now refuse. *Both open since 2026-08-19.*

(c) **Which side governs a dual-unit line?** When a receipt is entered against a line that
    displayed two units, which one is the stock-keeping quantity? This is Q40's question and
    it stays open; the build carries the single unit and converts nothing.

(d) **Should GRN lot/bag traceability exist for COUNTED materials?** Today it is refused
    outright: "Bag lots are only supported for items measured in kg." A receipt of 5,000 trays
    completes and lands in stock correctly, but there is **no lot or bag trace** behind it —
    no supplier lot number, no per-carton identity, no link from a tray back to its delivery.
    For resin that trace exists and is load-bearing. Whether the factory needs the same for
    trays, boxes or caps — and what a "bag" would even mean for them — is an operations
    question, not an engineering one.

**An evidence gap worth closing, and it limits everything above.** This export set is
purchase-order-side only: there is no GRN and no Stock Journal in it. So the units below are
proven for what was ORDERED. That the same unit is the stock-keeping unit, and the unit that
posts to Tally, is a reasonable reading but is **not proven by this evidence**. A Stock
Journal / GRN export would settle it — and the UOM contract rests on exactly that claim.

**Reinforces Q54(a) with proof rather than inference.** That question asks whether every kg
material is a "common input" that must refuse a machine. The evidence now shows the concrete
harm: **six packing-FILM masters are measured in `Kgs.`** (Hm Polythene Bags, three LDPE
Cover variants, Poly Olefin Pouch, LDPE Cover 20X33X150). Because the only signal the data
carries is the unit, all six are currently treated as common resin input — so a film request
naming a machine is refused today, wrongly. Film is not resin. Engineering deliberately has
NOT invented a rule to separate them; the factory has to say which materials are the common
input.

**Blocks:** nothing. Counted and weighed materials both walk the full chain in their own
units, and the fraction rules are enforced. What is blocked is any conversion between units,
any lot/bag trace for a counted material, and any correct handling of the film-vs-resin
distinction.
*Open since 2026-08-18.*

## Q59 · Which item categories may each document use?

PR #8 added the nullable `items.category` column and four values — Raw Material,
Packing Material, Finished Good and Other — plus policy helpers intended for a
later enforcement phase. The migration says the owner stated three rules on
20-Aug-2026, but there is no corresponding decision record or original owner
artifact in the repository. Memory and a code comment are not evidence, so the
rules cannot be treated as factory decisions yet.

The classification column and the read-only proposal command do not need these
answers: they can continue to record what kind of thing an item is. What must
not proceed is making a document refuse an item until the following are settled:

(a) **Purchase orders:** are they only for Raw Material and Packing Material, or
    may `Other` items such as spares, tooling, stationery and consumables also
    be purchased through the ERP? The current code contradicts itself: the
    class comment says raw and packing only, while `Other->purchasable()` returns
    true.

(b) **Store material requests:** should eligibility continue to be the existing
    owner-editable `is_production_input` flag, or should category participate?
    The category class itself says it must not replace that flag, while its
    helper currently makes every raw/packing item requestable and every `Other`
    item non-requestable regardless of the flag.

(c) **Sales orders:** may only Finished Goods be sold, or are there legitimate
    sales of scrap, spare material or another `Other` item?

(d) **Unclassified (`category = NULL`) items:** when enforcement arrives, should
    a real document continue with a visible warning, or refuse until somebody
    classifies the item? PR #8 currently proposes allow-and-flag, but that is not
    recorded as the owner's answer.

**Blocks:** document-eligibility enforcement based on `ItemCategory`. It does
not block the nullable column, the honest dry-run classification report, or a
person recording categories they actually know.
*Open since 2026-08-21.*

## Q60 · Which ItemCategory does each Tally stock group map to? — RESOLVED

**Resolved 2026-08-27 by DEC-20260827-001**: the category is derived from the
Tally stock group. Finished good — the Finished Goods tree, HDPE Bottles &
Container, and Caps & Closures (caps because the factory SELLS them: 2 sales
invoice lines and 2 sales order lines in the 26-Aug export, and only a
finished good is sellable). Raw material — the Raw Material tree and Master
Batch (consumed on 10 stock-journal OUT lines beside resin). Packing material
— the Packing Material tree, Carton Box, Tray, BOPP TAPE, SHRINK ROLLS.
Other — Scrap (33 inward journal lines, absent from all 55 invoices and 34
orders; if it is ever genuinely sold it becomes a finished good by a NEW
decision). The 12 items in no group stay NULL. No enforcement is switched on
— that is Q59, still open.

`items:summary` read on live 24-Aug-2026 returned **624 active items, 624 of
them with NO category**. That column is what three document rules read — a
purchase order may be raised for raw and packing only, a sales order for
finished goods only, a material request for raw and packing only — so with
every row NULL none of the three can act on anything.

`inventory:classify-items` proposes a category only where the database already
holds evidence. Its dry run (same day) proposed **99**: 79 finished goods
carrying a production standard, 19 packing materials appearing in a packing
mapping, 1 raw material dosed as a masterbatch. Those 99 were written. The
remaining **525** carry no such evidence and no tool can classify them.

**But Tally already groups every one of them.** The `All Masters` export
carries `<PARENT>` on each stock item — 17 groups across all 624. The ERP
already stores this as `items.item_group_id`, whose own migration records that
*nothing in the application reads it*. The taxonomy has been there unused.

**PROVENANCE, stated first because it limits what this proves.** The export
read is from `SWAASHPET POLYMERS PVT LTD Testing`, **not the live company**,
and every file declares `TALLYREQUEST: Import Data`. It holds exactly 624
stock items and live holds exactly 624 active items, which is a strong hint
the two are in step — a hint, not proof. The group NAMES are very unlikely to
differ between companies, but the counts below should be re-read from a live
`All Masters` export before anything is written.

**The proposed mapping — an agent's proposal, not a decision:**

| Tally stock group | items | proposed | why |
|---|---|---|---|
| Amber Pet Bottle | 123 | `finished_good` | bottles the factory produces |
| Clear Pet Bottle | 89 | `finished_good` | " |
| Liquor Pet Bottle | 35 | `finished_good` | " |
| Milk White Pet Bottle | 29 | `finished_good` | " |
| Tablet Container | 29 | `finished_good` | " |
| Green Pet Bottle | 12 | `finished_good` | " |
| HDPE Bottles & Container | 10 | `finished_good` | " |
| Orange Pet Bottle | 8 | `finished_good` | " |
| Finished Goods | 35 | `finished_good` | named as such in Tally |
| Packing Material | 27 | `packing_material` | named as such in Tally |
| Carton Box | 15 | `packing_material` | boxes finished goods are packed in |
| Tray | 9 | `packing_material` | trays finished goods are packed in |
| Master Batch | 32 | `raw_material` | the colourant, dosed into a run |
| Raw Material | 11 | `raw_material` | named as such in Tally |

That is **370 finished, 51 packing, 43 raw = 464 of 624**, and none of those
fourteen rows looks like a judgement call. **Two groups are, and they are the
question:**

**(a) `Caps & Closures` — 132 items, the single largest group.** Caps,
closures and measuring cups; purchased, and consumed on a run (the BOM carries
`28mm Tamper-Evident Cap` as a component). `raw_material` and
`packing_material` are both defensible: a cap is fitted TO the bottle, a
measuring cup is packed WITH it, and ItemCategory's own words for packing are
"what finished goods are packed in or with". What is NOT in doubt is that it
must be one of those two: `requestableFromStore()` allows only raw and
packing, so any other answer makes 132 items invisible to a material request.
The floor asks the store for caps.

**(b) `Scrap` — 16 items** (`PET Scrap - Amber`, `Amber Lumps`, `Film Waste`,
`Grinding`, …; all Kgs except `Bag Waste`). Scrap is PRODUCED, not purchased —
FC-02: rejected bottles and lumps are real stock booked inward. So it is an
output, which points at `finished_good`; but it is not a product the factory
makes to order, which points at `other`. The consequence decides it, and only
the owner can: `sellable()` is TRUE for `finished_good` alone, so **if scrap
is ever to go on a sales order it must be `finished_good`** — and if it is
`other` it can still be bought and held, but never sold through the ERP.

**The 12 with no Tally group stay NULL, deliberately.** They are a mixed bag —
`32GB Pen Drive`, `Tally Prime Server`, `Servo Amplifier 0.75KW`, `Inlet
Filter`, `Mould Release Spray`, two 3D prototype samples, `Plastic Bags Used`,
a row literally named `Stock`, and two counted bottles. Several are plainly
`other` (spares, IT), but NULL means "nobody has said yet" and that is the
honest state for a row nobody has looked at. They are listed here so a person
can settle them by name rather than by rule.

**Blocks:** applying categories to the 525 the evidence-based classifier
cannot reach, and therefore purchase-order / sales-order / material-request
eligibility for most of the catalogue. It does not block the 99 already
written, which came from evidence rather than from this mapping.

*Open since 2026-08-25.*

## Q61 · May the ERP emit a Tally "Sales Order" voucher?

DEC-20260809-003 records that ALL real sales are invoiced directly in Tally and
that the ERP Sales module is demo-scale. That answer was given in the context of
the finance PULL — what the ERP reads out of Tally — and the sales-order
fulfilment work now raises the other direction, which the record does not cover:
when the sales desk raises an order in the ERP, should anything about it be
WRITTEN to Tally?

Two things were built that a voucher would need, and neither emits one:

* `customers.tally_ledger_guid` / `tally_ledger_name` — which Tally ledger a
  customer is, written only by `sales:import-customers-from-ledgers`. It exists
  so a screen can say "posts as {ledger}" and so a person can reconcile by eye.
* `sales_orders.customer_po_reference` — the customer's own PO number, the
  string that actually joins an order to an invoice in the factory's paperwork.

The question is three-part, and the middle part is the one the code cannot
guess: (a) may the ERP post a Sales Order voucher at all, or does the order book
stay ERP-only and Tally keep receiving invoices typed by the accountant? (b) if
it may, does the ERP become the ORIGIN of that order in Tally — with everything
that follows about who may then edit it and what happens when the ERP cancels an
order Tally already holds? (c) is a Sales Order voucher even wanted, given that
Tally's own order register is not in use today as far as the evidence shows.

Nothing has been assumed either way: no voucher code ships in this build, and
`TallySyncService` and the Tally agent are untouched. The two columns are
recorded and displayed only.

**Blocks:** any ERP→Tally emission for sales orders. It does not block the
fulfilment queue, reservations, the production queue or the planning read —
none of those touch Tally. *Open since 2026-08-26.*

## Q62 · Contested stock — who wins when two orders want the same bottles?

Store fulfilment lets the store hold FG stock against a confirmed sales-order
line. The build refuses to hold more than exists, but it does not decide WHOSE
hold survives when the free quantity cannot cover both orders, and that is a
commercial call, not a software one.

What the code does today, stated plainly so the answer can accept or overturn
it: the first hold placed keeps the stock, and a hold is only moved when a
person moves it — a store user may re-point an existing hold from one line to
another, which releases and re-takes it in one step and records the reason. So
the current behaviour is "first come, first served, with a manual override",
because that is the only rule that invents nothing.

What the owner needs to settle:

(a) Is first-hold-wins right, or should an earlier PROMISED DATE outrank an
    earlier hold — i.e. should the ERP ever move a hold on its own?
(b) If the store re-points a hold away from a customer, does that need anybody's
    approval, or is the store's judgement final? Today it is final and audited.
(c) Is there a customer priority the ERP does not know about — a party whose
    order always takes precedence? If so it must be recorded as a rule, not
    remembered by whoever is on shift.
(d) When stock is short and production is asked for the shortfall, is the queue
    strictly the order the requests were raised in (today's behaviour, with
    manual reordering by production), or does the promised date drive it?
(e) When is a request "answered"? Today a QUEUED request leaves the floor's
    queue the moment the line is covered by delivered pieces plus what it
    still holds — counted so that a delivered hold is never counted twice
    (the design draft's literal sum would have marked a 100-piece line
    produced at 60 delivered out of a 100-piece hold). A request the floor
    has already STARTED is never retired by paperwork — whether a running
    job should stop is the floor's call. If the owner's own sense of
    "covered" differs, the formula moves to match it, not the other way
    around.
(f) The planning dashboard quotes dates assuming ONE production line per
    queued product (config `production.planning.parallel_lines`, default 1,
    printed in every payload's basis). One line is the fewest the factory can
    run, so the quoted date is a ceiling the floor can beat — but if the real
    number per product is known, recording it tightens every date. What is
    it?

**Blocks:** any automatic re-allocation of a hold. It does not block the manual
flow already built — reserve, release, re-point and send-to-production all
require a person, and every one of them records who and why.
*Open since 2026-08-26.*

## Q63 · Does the factory use Tally Receipt Notes (GRNs) at all? — RESOLVED

**Resolved 2026-08-30 by DEC-20260830-001: the factory does NOT use Tally
Receipt Notes.** The ERP's goods receipt stays the arrival record (inward,
QC, barcodes, inventory); no Receipt Note voucher is posted or staged to
Tally, and `tally-sync.receipt_notes_enabled` stays OFF as a decided state
rather than a fail-closed reading. The related half the decision expressly
does NOT answer — what Tally should receive when material is rejected at
incoming QA — remains open inside DEC-20260825-001, for Accounts. Was open
since 2026-08-26; the original entry follows for history.

The 26-Aug XML export batch from the standalone Testing company was expected
to contain a goods-receipt-note sample (`receipt_note.xml`), but its 20
"Receipt" vouchers are MONEY receipts — bank allocations against customer
bills, no stock items. No GRN voucher sample exists in any export read so
far, and the voucher-type master list in the same batch cannot prove the
type is used, only that it is defined.

What this asks: when material arrives against a purchase order, does the
accountant book a Tally Receipt Note (and the ERP should mirror one), or is
the purchase invoice the ONLY Tally-side arrival record (and the ERP's goods
receipt stays ERP-only, feeding the invoice later)? Related: what Tally
should receive when material is rejected at incoming QA is already named
open inside DEC-20260825-001 — an answer here should settle both together.

**New input, 2026-08-28 — NOT YET A DECISION.** Asked where in Tally the
answer lives, the lead checked and reported back, in a Claude session on
28-Aug-2026, the words "No Recepts notes" — i.e. the factory does not book
Tally Receipt Notes. That is consistent with every artifact read so far:
`receipt_note.xml` in the 26-Aug export holds money receipts, not goods
receipts, and no GRN voucher sample exists in any export. It is recorded
here rather than as a decision record for two reasons, and both must be
cleared before it becomes one: the statement came from the lead in a
session, not from the owner in a quotable dated message, and no artifact
has been attached showing the Receipt Note voucher count — the Statistics
screen is where that number lives. Nothing in the code may rely on this
paragraph. `tally-sync.receipt_notes_enabled` stays OFF as the fail-closed
reading of an OPEN question, which is what it already says.

**Blocks:** the Tally-posting half of the goods-receipt flow. It does not
block ERP-side goods receipts, quality holds, or barcode issue at
acceptance, which carry on regardless of what Tally is later told.
*Open since 2026-08-26.*

## Q64 · May material be purchased without a purchase order?

The Testing-company books say direct purchases are normal practice: of the
17 purchase invoices in the 26-Aug export, most carry NO order reference —
only a minority link back to a PO (via the line's `ORDERNO`). The ERP's
procurement flow is being built to start a goods receipt from an OPEN
purchase order.

What this asks: (a) should the ERP allow an arrival/receipt with no PO
behind it (mirroring the books as they are), or must every purchase go
PO-first from now on (a policy change the ERP would then enforce)?
(b) If no-PO arrivals are allowed, do they still pass incoming quality the
same way? (Presumably yes — the material does not care what paperwork
preceded it — but presumption is not a decision.)

**Blocks:** whether the goods-receipt screen may offer "receive without
order". Receipt AGAINST an open PO is unaffected and proceeds either way.
*Open since 2026-08-26.*

## Q65 · Should Tally sync run on a clock (e.g. three times a day)?

Sync today is CONTINUOUS: the factory-PC agent polls the ERP roughly every
90 seconds, and shift production vouchers are additionally gated by the
shift-end release window (with a person's Sync Now for the impatient case,
DEC-20260825-002). A request has been made for a SCHEDULED cadence —
three runs per day — instead.

The two designs serve different instincts: continuous sync means Tally is
never more than minutes behind and a failure surfaces the same hour it
happens; a 3x/day clock means the accountant sees predictable batches
arrive at known times, and nothing lands mid-entry while they work. They
cannot both govern. Moving to a clock would also have to say what happens
to the shift-end release gate, which currently decides WHEN a production
voucher may leave regardless of any polling interval.

**Blocks:** any change to the agent's polling design. It does not block
the queue page, filters, retry/dismiss/release, or Sync Now, which work
the same under either cadence.
*Open since 2026-08-26.*

## Q66 · Where should Tally Sync sit in the sidebar?

On 21-Aug-2026 the owner named the module order one by one and put **Tally
Sync LAST of the modules** — after CRM, Finance and Maintenance. That
arrangement is pinned by `frontend/src/app/AppLayout.nav.test.ts`, which
exists for this and nothing else.

The 26-Aug product-identity build spec asked for Tally Sync to move to
**directly after Payroll**, i.e. immediately after the specified prefix and
before the unspecified "etc." group. That is a reversal of the 21-Aug
request, and a build spec is not owner authority: AGENTS.md makes the owner
the only authority for this, and a changed decision has to be a new record
rather than a quiet re-sort. No decision record covers either position — the
21-Aug order lives only in that test's docblock — so nothing here has been
promoted to a fact.

What this asks: does Tally Sync stay last of the modules (21-Aug), or move
to directly after Payroll (26-Aug spec)? Nothing else about the sidebar is
in question — CRM, Finance and Maintenance keep their relative order either
way, and the Downloads/Help/Administration utilities stay below the divider
regardless. Worth noting for the answer: an accountant is the heaviest user
of Tally Sync and reaches it several times a day, which is the case for
moving it up; against it, the 21-Aug order was given deliberately and the
factory has been using it for a week.

**Blocks:** the Tally Sync entry's position, and nothing else. The move is
NOT applied — the 21-Aug order stands, and the Phase 3 Inventory-menu
regrouping in the same spec is unaffected and did ship (no owner pin covers
the Inventory group's internal child order).

Where the answer lands: `frontend/src/app/AppLayout.tsx` (the `allNavItems`
entry and its header docblock) and `frontend/src/app/AppLayout.nav.test.ts`
(`CONFIGURED_ORDER`). Both refer to this question BY NAME rather than by
number, because this file re-mints question numbers at merge — so a re-mint
of this entry needs no code edit.
*Open since 2026-08-27.*

## Q67 · What may the FLOOR read on a job — the ETA, the free stock, the customer's date?

The Production Queue screen (`GET /api/v1/production/queue`, new on 27-Aug)
puts a job's demand and its date on one row. Opening that queue is OR-gated
`module:production,inventory` — the production request is a two-sided
document, raised by the store and run by the floor, and neither desk is
asked to hold the other's permission to read the one piece of paper they
share.

But the row JOINS ON figures that belong to other desks, and today each is
refused elsewhere:

- **the planning block** — free finished-goods stock, how many jobs are
  queued ahead, capacity per shift, shifts needed, the estimated ready date
  and its `cannot_estimate` refusal. Read today at
  `/inventory/fulfilment/planning`, **`module:inventory`**.
- **ordered / delivered** on the customer's line. Read today by the store on
  its own fulfilment queue, and by Sales.
- **the order's expected date.** Read today at `/sales/sales-orders` and in
  the dashboard's `demand` block — **`module:sales`, and nowhere else.**

**What ships in the meantime, and it is the conservative shape:** a caller
sees on this row only what they could already read somewhere else. A store
login (inventory.view) gets the worklist, the ETA, the free stock and the
line figures. A sales login additionally gets the expected date. A login
holding **production.view alone gets the worklist it already had** — no
dates, no free stock, no demand denominator. No refusal that stood before
this route existed has stopped standing.

**What this asks.** A machine operator or shift in-charge whose login holds
production.view and nothing else — should they see:

1. **the ETA and free stock** for the job they are about to run? (For: the
   floor deciding what to run first benefits from knowing what the store
   already has and when the queue lands. Against: free FG stock and the
   planning walk are the store's read, and the floor changes neither.)
2. **the customer's expected date**? (For: it is the reason the job is
   ranked where it is. Against: it is the commercial relationship, and the
   floor is given the priority number precisely so it does not have to
   reason about customers.)

The two can be answered separately — the code already gates them
separately.

**A wording caution for whoever answers.** This document deliberately does
NOT call `expected_date` a "promised date". Sales labels it *Expected Date*
everywhere it is authored — the order form, `SalesOrderResource`, the
export, the sort — and validates it only as `after_or_equal:order_date`.
Whether it represents a commitment made to the customer, or merely the
desk's own estimate, has never been recorded. If the answer to (2) is yes,
saying which of the two it is would settle a second thing.

**Blocks:** only which columns a production-only login sees on the
Production Queue page. It does not block the page, the endpoint, the
grouping, the queue's order, Start/Cancel, or any store or sales login —
all of those work under either answer.

Where the answer lands:
`backend/app/Modules/Production/Http/Resources/ProductionQueueResource.php`
(the `$seesStore` / `$seesSales` flags and their block spreads),
`backend/tests/Feature/Production/ProductionQueueEndpointTest.php` (the
three gate tests, which pin the current rule from both sides) and
`frontend/src/features/production/pages/ProductionQueuePage.tsx` (the
columns render only when their key is present, so a widened gate needs no
frontend edit).

Like Q66, the code refers to this question **by name** — "the
floor-visibility owner question", and on the backend by the path of this
file — never by its number, because this file re-mints question numbers at
merge. A re-mint of this entry needs no code edit.
*Open since 2026-08-27.*

## Q68 · Does the accountant want ERP-recorded supplier bills to post to Tally as Purchase Invoices?

The ERP now records supplier bills (28-Aug procurement build): the paper
invoice's number, date, lines, GST figures as printed, rounding, and an
attached scan — matched to purchase orders and arrivals, entered and
recorded by Accounts only (FC-06). What is deliberately NOT built is any
posting to Tally: the bill's Tally cell says so in one line.

What this asks, distinct from its neighbours: once a bill is recorded in
the ERP, should the ERP stage a Tally **Purchase Invoice** voucher (the
way batches stage Stock Journals), or does the accountant keep keying
purchase invoices into Tally directly, the ERP record being the factory's
own reference? This is the purchase-side sibling of DEC-20260809-003,
which settled that all real SALES are invoiced directly in Tally.

It cannot be answered by building: even a yes needs Q39 first (which
purchase ledger and rate a voucher names — the ERP holds the accountant's
per-bill ledger SELECTION but derives nothing) and touches Q41 (where GST
is filed from) and Q28 (whether a payments build follows). A no costs
nothing — the screen already works as a record.

Like Q66/Q67, the code refers to this question **by name** — the words
"Purchase Invoice posting awaits the accountant's answers" in
`frontend/src/features/procurement/supplierBills.ts` — never by number,
because this file re-mints question numbers at merge.

**Blocks:** only the enqueue path for a Purchase Invoice voucher. The
supplier-bill screen, its arithmetic, its matching and its attachment work
under either answer. *Open since 2026-08-28.*

## Q69 · ~~When material comes back from production, must it be returned against the store issue that put it there?~~ — RESOLVED (DEC-20260831-005)

The daily return was built on 30-Aug-2026: the store issues material to the
production area, production makes finished goods from it, and the balance is
returned to the store daily. Until then the only return in the system was
bounded by a store issue LINE, and seven of the nine materials standing in
production on the live instance have no store issue behind them at all
(`issued = 0`, a positive balance, `returned = 0` across the whole ledger).
They had no way home. `POST /api/v1/inventory/production-returns` now takes
both kinds of line in one call: with a `store_issue_line_id`, the return
closes that handover's own arithmetic; without one, it is bounded by the part
of the production balance that no open handover is standing against.

What this asks: when a store issue IS open for that material, must the
evening's return be attributed to it, or may the storekeeper record it as
unattributed? The build deliberately does not answer. Spreading a return
across open issues — FIFO or by any other rule — would invent an attribution
this factory cannot make (FC-01, DEC-20260807-007: a bag belongs to no
machine and no batch, and a batch's consumption is calculated). So the screen
shows the split and a person chooses.

> **RESOLVED — `DEC-20260831-005` (2026-08-31), owner.** Yes, where a Store
> Issue exists: the return must identify that exact issue, so the handover's
> own quantity closes and the ledger records which handover the material came
> back on. Where NO Store Issue exists — the seven materials the live floor
> could not bring home — it may be returned without one. The rule reaches as
> far as an issue exists to be named and never requires inventing one.
>
> The same decision goes further than this question asked, and the rest is
> built with it: material not returned stays available in Production/WIP as
> the next day's opening material, and the next request nets off the usable
> quantity already standing there for the same item and unit, showing total
> required / in production / balance to request.
>
> Read `CURRENT-DECISIONS.md` for the decision; this entry is kept only as the
> history of the question.

**Until it was answered, the build REFUSED rather than picked.** A material an
open store issue is standing on will not accept an unattributed return: the
refusal names the issue and points at its own line, which always works.
Materials with no handover behind them — all seven the live instance could
not bring home — are unaffected, so the case that provoked the build works
and the undecided case does not.

That is deliberate and it is the conservative direction. An earlier version
showed the split and let the storekeeper choose, which sounds like leaving
the decision to a person and is not: shipping the capability answers this
question "storekeeper's choice", and **an unattributed movement can never be
re-attributed afterwards**. If the answer turns out to be "must attribute",
every return recorded the other way has left a handover claiming material
that went home weeks earlier, with nothing able to tell the two apart.

Both answers are cheap from here. "Must attribute where an issue is open" is
the deleted condition — nothing to build. "Storekeeper's choice" is deleting
the refusal in `ProductionReturnService::undecidedRefusal()`.

**Related, and also open:** the ERP does not enforce that the return happens
daily — nothing warns at the end of a shift that material is still standing
in production. Whether it should chase, and against what clock, follows from
this answer.

**Blocks:** nothing built. The return works under either answer.
*Open since 2026-08-30.*

## Q70 · Does the factory use Tally DELIVERY NOTES at all?

The sales-side sibling of Q63, and the evidence points the same way Q63's did.
The owner's stated fulfilment sequence is "Sales Order → Delivery Note / stock
dispatch → Sales voucher", but the factory's own Tally does not contain that
middle step:

* `Transactions.xml` (the factory's July-2026 export) holds 195 Payments, 177
  Sales, 134 Receipts, 126 Sales Orders, 82 Journals, 64 Purchases, 38 Stock
  Journals, 15 Purchase Orders, 15 Contras and 1 Debit Note — and **ZERO
  Delivery Notes**;
* of those **177 real Sales vouchers, NONE references a delivery note**
  (`INVOICEDELNOTES.LIST` is empty in all 177), while **163 reference a Sales
  Order directly** (`INVOICEORDERLIST.LIST` → `ORDERTYPE` "Sales Order" +
  `BASICPURCHASEORDERNO`), and 176 carry `ORDERNO` on their inventory lines.

So the factory's real accounting sequence today is **Sales Order → Sales
Invoice**, with the invoice referencing the order. Two possibilities and the
code cannot choose: (a) the factory is ADOPTING Delivery Notes as a new
practice, and the ERP should post them; or (b) it does not use them, and the
ERP must stop — the DEC-20260830-001 outcome, one voucher type later.

Until this is answered the ERP stages nothing: `tally-sync.delivery_notes_enabled`
defaults OFF, fail-closed, and the listener no-ops. The ERP's own delivery, its
stock movement and its trace are untouched either way — this is only about what
reaches Tally. **Blocks:** any Delivery Note emission. *Open since 2026-08-30.*

## Q71 · When the ERP issues an invoice, which book originates the sale?

DEC-20260809-003 records that **ALL real sales are invoiced directly in Tally**
and the ERP Sales module is demo-scale. Yet issuing an ERP invoice has been
staging a Tally 'Sales' voucher with no gate at all. If Accounts also keys that
invoice into Tally, **the sale is booked twice**.

Independently of the business answer, the voucher the ERP would post is
malformed: the builder declares itself "BEST-EFFORT TEMPLATE — NOT YET
VALIDATED AGAINST A REAL TALLY INSTANCE", and against the factory's own export
it emits **no CGST/SGST ledger entries and no `Rounding Off`**, one sales ledger
where Tally uses per-line `ACCOUNTINGALLOCATIONS` (`Local Sales Taxable`), and
nests `ALLINVENTORYENTRIES` inside `ALLLEDGERENTRIES` where Tally has them at
voucher level. **A posted voucher would carry ZERO TAX.**

`tally-sync.sales_invoices_enabled` therefore defaults OFF, fail-closed. Turning
it on needs BOTH an Accounts answer here AND a builder validated field-by-field
against a real export. **Blocks:** any Sales voucher emission. *Open since
2026-08-30.*

## Q72 · Q61 revisited — the Tally Sales Order register IS in use

Q61 asks whether the ERP may emit a Tally "Sales Order" voucher, and notes as
part of its premise that "Tally's own order register is not in use today as far
as the evidence shows". **That premise is now contradicted by the evidence:**
the factory's exports carry **126 Sales Order vouchers in July 2026** and **34
in August** (`sales_order.xml`), with full party, GST, godown, batch and
per-line `ORDERNO` detail — and 163 of July's Sales invoices reference them.

This does not answer Q61 — whether the ERP may become the ORIGIN of those orders
in Tally is still the owner's and Accounts' call, and the questions Q61 raises
about who may then edit a Tally-held order, and what happens when the ERP
cancels one, stand unchanged. But it should be answered knowing the register is
real and in daily use, not assumed dormant. **Blocks:** nothing beyond what Q61
already blocks. *Open since 2026-08-30.*

## Q73 · What does it MEAN when the store rejects a quantity?

The owner's fulfilment flow has the store approve a hold in full, approve it in
part, or REJECT it, with production planning starting only for the quantity the
store explicitly rejects or cannot fulfil. The ERP can express the first two —
a hold is placed for the approved quantity — but it has **no field for a
rejection**: reservations live `active / released / consumed`, and a release may
equally be a re-point, a correction or a cancellation. A rejection is therefore
not a fact this database holds, and the control view reports it as
`not_recorded` rather than inferring it from a release.

Before the column is built, three things only the factory can settle: (a) does
rejecting a quantity END that quantity's claim on stock, or may a later store
decision re-offer it? (b) does a rejection AUTOMATICALLY raise the production
request for that quantity, or is asking the floor still a separate deliberate
act? (c) must a rejection carry a reason from a fixed list (no stock / quality /
customer on hold / other), and who may overturn one?

Related: **Q62** (contested stock — whose hold survives) and **Q27** (whether a
QC pass is required before dispatch, which the owner's flow answers as YES but
which is not yet a decision record). **Blocks:** the store-decision record, and
with it the "production plans only the rejected quantity" rule. *Open since
2026-08-30.*
## Q74 · Does a document reference from a retired warehouse prove the material is physically on the shelf today? — RESOLVED (DEC-20260831-001)

`inventory:preview-warehouse-recovery` lists the stock standing in
warehouses no picker offers and classifies each row from its own movement
history: DOCUMENTED (ordinary factory documents), OPENING (an opening
balance somebody seeded), TEST (a wiring check or demo), MIXED. Only the
DOCUMENTED rows are printed with a proposed destination.

That printed destination is the proposal to move real stock, and it rests on
an assumption the report cannot check: that a reference like `GRN for PO 4`,
`QC release to FG store` or `SPE 154` recorded against a retired location
means the material is **still physically there**. The report reads
references, not shelves.

What is asked: is that assumption sound, or must someone count these rows
before any of them move? And is the DOCUMENTED vocabulary itself right —
which reference wordings prove present physical stock?

**Blocks:** any recovery of the DOCUMENTED rows. The preview itself is
read-only and safe to run under either answer. *Open since 2026-08-30.*

## Q75 · What happens to the opening-balance and wiring-check stock in the retired warehouses?

The same preview withholds every OPENING and TEST row. On the live instance
these are the larger share: the retired RM store's rows are backed almost
entirely by a rehearsal opening balance, and the dispatch bay's rows by
wiring checks and demo documents. Moving them into the operational Store
would credit the factory with material it never received, with no error
anywhere.

What is asked: are these written off, left where they are, or corrected to
zero — and by whom? Note the third option is not free either: a correction
is itself a stock movement.

**Blocks:** closing out the retired warehouses. *Open since 2026-08-30.*

## Q76 · Recovering stranded stock would re-value the Store's stock of the same item — is that acceptable to Accounts?

A stock transfer carries the source row's average cost into the destination,
where the weighted average is recomputed. So recovering a stranded row does
not only move a quantity: it blends that row's cost into the Store's
existing average for the same item — and those costs came from a rehearsal
seeder or from the older Tally company the retired godowns belong to.

What is asked: does Accounts accept the re-valuation, should the recovery
carry a different cost, or should the quantity move without disturbing the
average?

**Blocks:** any recovery, including the DOCUMENTED rows Q74 covers. *Open
since 2026-08-30.*

## Q77 · Which materials must a goods receipt record lots and bags for?

Lot and bag traceability is switched on and has never produced a row: the
lots block is optional on a goods receipt, so omitting it creates no lot and
no bags — and the incoming-QC hold, which acts on bags, then has nothing to
hold. Making the lots block mandatory needs a rule for WHICH materials it is
mandatory for, and the obvious discriminator is not usable: every item in
the master carries `tracking_type = none`.

What is asked: is it every purchased material, only weighed materials
(resin, masterbatch), only named items, or a per-item flag someone
maintains? This decides what the store is asked to do at every arrival, so
it is a floor-process question, not a technical one.

Related and already recorded: DEC-20260825-001 leaves open "whether every
arrival line must wait for QA before the store may issue it or only named
materials", and "what carries the barcode for counted packaging". This
question is the third of that set and they are best answered together.

**Blocks:** making the traceability workflow operational for future
receipts. *Open since 2026-08-30.*

## Q78 · Is there a store-acceptance step for finished goods, and may the Storekeeper approve dispatch?

The Storekeeper role now exists as a definition
(`roles:define-storekeeper`, dry-run first). Two of the capabilities asked
for could not be granted, because neither is a thing the system currently
does:

  · **Receiving approved finished goods.** The finished-goods chain today is
    complete → quality-check → pm-approve → accountant-approve. There is no
    store-acceptance step. Adding one is a new stage in how the factory
    works.
  · **Final dispatch approval.** Deliveries sit under the sales module, so
    granting it means `sales.manage`, which also unlocks sales orders,
    customers and invoices — a wider grant than the words ask for.

What is asked: should a store-acceptance step exist for finished goods, and
who performs it? And should dispatch approval be separable from the rest of
Sales, or does the Storekeeper simply not do it?

**Blocks:** those two capabilities only. The rest of the Storekeeper role
works without them. *Open since 2026-08-30.*

## Q79 · What should the stock screen call the quantity a storekeeper may act on? — RESOLVED (DEC-20260831-002)

The stock list can be decomposed per row into on-hand, quantity held for
incoming QC, quantity reserved for a customer line, and quantity standing in
Production/WIP. The arithmetic is settled and already exists. What is not
settled is the WORDING, and the wording is the whole risk.

The quantity an issue is actually checked against subtracts the QC hold and
**not** customer reservations. So a row can honestly read on-hand 500 /
free 500 / reserved 120 — and a column headed "free to issue" would tell a
storekeeper 500 may go out while 120 are promised to a customer. With no
bags on the live instance today the QC hold is zero on every row, so that
column would duplicate on-hand exactly until the first lot is recorded.

What is asked: what should that column be called, and should the store's
headline figure net customer holds or not?

**Blocks:** shipping the stock-state decomposition to the Stock page. The
underlying figures are unaffected. *Open since 2026-08-30.*

<!--
  MERGE NOTE, 31-Aug-2026. The two procurement questions below arrived on the
  purchase-requisition-coverage branch numbered Q69 and Q70, and both numbers
  were already taken on main by unrelated questions merged while that branch
  was open. Re-minted here to Q80 and Q81, which is what this file has always
  said it does — "this file re-mints question numbers at merge", and why the
  code refers to a question BY NAME and never by number.

  Their decision records were re-minted for the same reason: DEC-20260831-001
  and -002 were taken on main by two inventory decisions, so the procurement
  pair are DEC-20260831-003 and -004. Records are immutable, so the ones
  already merged keep their ids and the unmerged pair moved.
-->

## Q80 · Does a DRAFT purchase order already reserve quantity against its requisition? — RESOLVED

**Resolved 2026-08-31 by DEC-20260831-003: NO — a draft reserves nothing.**
Nothing is held until the order goes to the vendor, and a draft may be typed
for any quantity. The owner chose this over the shipped default, accepting
the named cost: the refusal now arrives at Send rather than at typing, so two
drafts may each be raised for the whole requisition and the second is refused
after the vendor and the rates were typed. The combined-quantity rule
therefore lives in `PurchaseOrderService::send()`; a guard on create or amend
would be unreachable. The wording below is the question as it was asked.

The 30-Aug build gives every purchase-requisition line the four figures a
buyer needs before raising an order — what was requested, what has already
been ordered against it, what is still to order, and whether the line is Not
Ordered / Partially Ordered / Fully Ordered — and refuses, in the backend, a
purchase order whose lines would push the combined ordered quantity for an
item past what that requisition asked for.

"Combined ordered quantity" has to name a set of order states, and this
question is one half of what that set is. A purchase order is born a DRAFT
and is not sent to the vendor until somebody sends it. Two readings, both
defensible in a factory:

- **Draft reserves** (the build's current default). A buyer who has typed
  a draft for 500 of 800 cannot type a second draft for 500 as well; the
  requisition's Balance to Order falls the moment the draft exists. The
  cost is that an abandoned draft holds quantity until it is cancelled.
- **Draft does not reserve.** Only what the vendor actually holds counts,
  so nothing is reserved until Send. The cost is that two buyers, or one
  buyer twice, can raise drafts that together over-order, and the refusal
  arrives only at Send — after the rates and the vendor were typed.

What is known: nothing in the factory record answers this. DEC-20260812-002
puts the purchase order in the ERP and names "the ERP's remaining-quantity
tracking" as the thing that keeps the two books agreeing, but it does not
say which order states count towards it. The ERP's own lifecycle is
Draft → Sent → PartiallyReceived → Closed, with Cancelled beside it.

The build ships with **Draft reserves**, because of the two answers it is
the one that cannot let combined orders exceed the requisition — the exact
failure the refusal exists to prevent. Read that claim narrowly: it is about
THIS question only. The pair of defaults the build ships (draft reserves,
cancelled releases) is not "the conservative pair" — the next question's
default is the PERMISSIVE half of its own choice, and is a judgement rather
than a safety property. Neither default should be read as an
already-half-made decision.

It is a DEFAULT, not a decision: the states live in one named constant in
`RequisitionCoverageService`, the constant's comment names this question in
words, and a test varies only the order's status across that boundary, so
the other answer is a one-line change with a red test to apply it
deliberately.

**Blocks:** nothing on the screen. The four figures, the status words, the
UoM-wise display and the refusal itself all work under either answer — only
which orders are counted changes. *Open since 2026-08-30.*

## Q81 · When a purchase order is CANCELLED, does its quantity return to the requisition's balance? — RESOLVED

**Resolved 2026-08-31 by DEC-20260831-004: NO — a cancelled order keeps its
allowance, provided it was SENT.** A requisition is asked for once and
answered once; wanting the material again means a new requisition, which is a
fresh approval. The owner chose this over the shipped default.

Answering it needed a THIRD answer that was never filed as its own question,
and which the same decision records: **a cancelled order counts only if it was
ever sent.** Q69 and Q70 alone contradict — a draft holds nothing, yet a
cancelled order counts — so cancelling an abandoned draft would have consumed
a requisition the draft never held, and a typo could have eaten a requisition
permanently. The ERP now records `purchase_orders.sent_at` to tell the two
apart. The wording below is the question as it was asked.

The other half of Q69's set, and a separate factory judgement rather than
the same one restated. Cancel is available on a Draft or a Sent order with
zero receipts; it writes a reason, an actor and an instant, and — once the
PO→Tally gate is open — withdraws or dismisses the staged voucher.

- **Cancelled releases** (the build's current default). The quantity goes
  back to Balance to Order and the requisition can be ordered again. Cancel
  is the release valve that makes "Draft reserves" safe.
- **Cancelled still counts.** A requisition is asked for once and answered
  once; a cancelled order has spent the requisition's allowance, and
  wanting the material again means a new requisition, which is a fresh
  approval by whoever approves them.

What is known: nothing in the factory record answers this either. The
second reading is not a strawman — it is how an approval-controlled
requisition behaves in some factories, and it makes the requisition, not
the order, the unit of authorisation. The first is how most buyers expect a
cancel to behave, and the ERP already treats Cancelled as terminal
everywhere else (no receipt may be booked against it).

Shipped as **cancelled releases**, in the same constant, under the same
one-line-change rule as Q80 — but note plainly that this default is the
PERMISSIVE of its two answers, not the safe one. Releasing lets the
requisition be ordered again without a fresh approval; the stricter answer
("cancelled still counts") is the one that cannot be reached by accident.
It is shipped this way because it is how most buyers expect a cancel to
behave, not because arithmetic forces it. The two answers are independent:
the owner may answer either without the other.

**Blocks:** nothing on the screen — same as Q80. *Open since 2026-08-30.*
