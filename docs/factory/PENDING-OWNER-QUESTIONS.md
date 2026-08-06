# Pending owner questions

The questions only the factory can answer. Each entry stays here until the
owner answers it — then it becomes a decision record (see the
`record-factory-decision` skill) and the entry is marked resolved with the
decision id. **Nothing in this file is a fact yet.** An agent that needs one
of these answers must not guess, interpolate, or promote a proposal to an
answer.

Format: `## Q<n>` heading · the question · what is known so far · what it
blocks · open-since date.

---

## Q1 · How many metres of tape are in one Tally "No"?

Tally counts `Packing Tape - Transparent` in Nos; the factory gave metres per
box. Until one No is defined (a 65 m roll → 65?), tape is display-only and
never posts (FC-03). **Blocks:** tape consumption reaching Tally.
*Open since 2026-07-31.*

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
**Blocks:** the final-carton line posting. *Open since 2026-08-05.*

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
