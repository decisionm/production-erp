# Current factory decisions

**GENERATED FILE — do not edit.** Regenerate with:
`python3 scripts/factory-knowledge/generate_current.py`

One line per decision still in force, newest first. The full record — evidence,
scope, what it replaced — is the file named by the ID in `decisions/`.

**16 current · 0 superseded**

- **DEC-20260806-011** (2026-08-06, products, packing) — Duplicate workbook rows for one bottle are PACK VARIANTS of a single product, not separate products — 18 of 103 rows. The variant label carries the box count (840/box vs 810/box) so the floor can tell them apart.
  - evidence: PR #122 (box count on the label); workbook-product-master (18 variant rows)
- **DEC-20260806-010** (2026-08-06, production) — A masterbatch is resolved from the item's own colour column and the factory's colour map — NEVER by scanning material names for colour words (that once pre-selected a WHITE masterbatch on a non-white run). Scrap items are never colourant candidates, which is why colour derivation has a masterbatch-only scope.
  - evidence: PR #134 (only-masterbatch scope; amber scrap left uncoloured); commit b872144 (name-scan rung removed)
- **DEC-20260806-009** (2026-08-06, inventory, tally-sync) — Syncing ERP stock to Tally means MATCHING the difference (receipt when Tally holds more, issue when less), never re-applying an opening balance — a later snapshot receipted on top of an earlier one doubles the stock silently. One snapshot reconciles once; a fresh snapshot can reconcile again.
  - evidence: Owner 06-Aug 'we can sync the live stock from the tally and start consume from here'; PR #130; TallyStockReconcileTest
- **DEC-20260806-008** (2026-08-06, production) — Machines carry the floor's own codes ASB-1..ASB-10 (matching the handwritten reports), shown on floor screens; office display names are unchanged. Batch numbers are unaffected by the recode.
  - evidence: PR #121 (rename command), PR #126 (floor screens render the code); owner screenshots 06-Aug
- **DEC-20260806-007** (2026-08-06, production) — The factory's shifts are Shift A (06:00), Shift B (14:00), Shift C (22:00) — keyed on START TIME, which is a shift's identity; a name is what gets renamed. There is no Night shift: the duplicate was merged into Shift C with its history repointed.
  - evidence: Owner 06-Aug 'THERE IS NOT NIGHT, SHIFT A TO C' (screenshot); PR #121, #125 (seeder fix), #127 (merge)
- **DEC-20260806-006** (2026-08-06, packing, tally-sync) — Every boxed batch carries two STANDING packing lines — a final carton and a polymer cover over it — one of each PER BATCH (not per master box), editable on the row. Bag-packed products get neither. The Tally items are not yet named (PENDING Q5, Q6); until named the lines show and post nothing.
  - evidence: Owner 05/06-Aug, four requests, quoted in PR #136 ('one final box for all the batches completion need to be add in consumption'); PR #136
- **DEC-20260806-005** (2026-08-06, packing, tally-sync) — An HM/LD cover sitting in the POUCH column is a cover over a finished box, counted PER COVER: covers = bottles / nos_per_pouch (workbook figures 145, 110, 161, 83, 120). Never per tray (10x over-issue on 90ML RIB) and never one per box (400ML ROUND takes 1.66).
  - evidence: Owner 06-Aug 'if it is single packaging conver like HM and Ld, we have the calcuatio'; PR #132; workbook rows 76/78/80/81/98/99
- **DEC-20260806-004** (2026-08-06, production) — The amber masterbatch is 'Master Batch Amber' (not 'Master Batch Pet Amber'). Mapped in masterbatch_colour_map so amber runs pre-select it.
  - evidence: Owner 06-Aug 'Master Batch Amber is the standard' + 'for amber amber is the corret one'; the 38 Stock Journals consume Master Batch Amber; PR #135; live map written 06-Aug
- **DEC-20260806-003** (2026-08-06, production, tally-sync) — The masterbatch standard dose is 2.5 PERCENT of the bottle's own weight, editable on every run. A per-product dosing row a person stated outranks the percentage. The owner twice proposed 2.25%; the July/August journals dose amber at 0.32 g on a 12.9 g bottle = 2.5%, and the owner confirmed against that evidence.
  - evidence: July/Aug Stock Journals (amber 0.32 g/bottle on 12.9 g); PR #129; MasterbatchPercentageTest pins 2.5
- **DEC-20260806-002** (2026-08-06, packing) — 'LD 28.5 X 38' and 'LD28.5 X 39' are the SAME cover; 38 is the correct dimension (the dose sheet's 39 is a slip). Both spellings map to LDPE COVER (28.5x38x120G) at 40 g.
  - evidence: PR #127 body (owner confirmation quoted); Tally item name LDPE  COVER (28.5x38x120G)
- **DEC-20260806-001** (2026-08-06, packing, tally-sync) — Pouch and cover weights come from the factory's COUNTED nos-per-kg figures (pouches: 750x610=71, 780x610=59, 835x610=56; covers: HM 30.5x49=11, LD 28.5=25, LD 30x49=20, LD 30.5x39=15). The rounded per-piece column on the same sheet is unusable (29% error at voucher scale), and a size the factory did not count is NEVER interpolated — a derived HM 30x49 figure went live once and was withdrawn.
  - evidence: Dose-sheet photos 06-Aug (see sources/manifest.yaml: dose-sheet-photos); PR #127 (seed), PR #128 (withdrawal)
- **DEC-20260805-005** (2026-08-05, production) — One bottle has ONE unit weight per run, resolved configuration -> standard -> item master, and the screen must show the same figure the server computes with. The paper production report is the arbiter when figures disagree.
  - evidence: PR #113 (one bottle, one weight — the paper report settles it); PR #119
- **DEC-20260805-004** (2026-08-05, packing) — A product whose carton column holds an HM or LD bag packs straight into that bag, and the bag is the WHOLE pack: no tray line, no pouch line, no tape line. 17 of the 103 workbook rows pack this way.
  - evidence: PR #111 / commit c6088ce; owner 05-Aug 'when HM, no need to use the tray or pouch and other packing material'
- **DEC-20260805-003** (2026-08-05, packing, tally-sync) — One pouch covers ONE TRAY, not one carton. Five trays take five pouches. The pouch quantity in kg = trays x grams-per-pouch / 1000.
  - evidence: PR #111 / commit c6088ce; owner 05-Aug 'five trays, five pouches'; all 55 tray rows of the workbook
- **DEC-20260805-002** (2026-08-05, production, inventory) — The real purchased resins are 'Relpet G5801M' (Reliance) and 'PET Polyster Chips' — both at Rs.132-138/kg in the books. The item 'Pet Resin' the first live batches consumed appears in NO real journal and is demo data.
  - evidence: Relpet in 24/38 and PET Polyster Chips in 7/38 Stock Journals (Transactions.xml, 30 Jul export)
- **DEC-20260805-001** (2026-08-05, production, tally-sync) — Scrap and lumps are NOT discarded from the books: they are produced PET Scrap (per colour) and post as an inward line on every production Stock Journal. The owner first said rejects are discarded, then reversed on seeing the journals — the accountant had been booking scrap all along.
  - evidence: 31 of 38 Stock Journals (Transactions.xml, 30 Jul export) book Pet Scrap inward at Rs.17-32/kg; PR #110; commit 824def3
