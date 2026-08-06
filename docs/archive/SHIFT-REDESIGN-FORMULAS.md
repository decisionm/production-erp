# Shift Redesign — Formula Dictionary (Phase 1 deliverable)

Discovery output, 27 Jul 2026. Every formula below was read from actual
workbook cells or existing app code — nothing invented. Statuses:
CONFIRMED (formula observed) vs NEEDS-VINCENT (ambiguous/conflicting/absent).
The worked examples are the unit-test fixtures for the calculation engine.
See GO-LIVE-PLAN.md for the deployed baseline this builds on.

# FORMULA DICTIONARY — Shift Production Redesign

Sources: **WB1** = `/Users/newuser/Downloads/Daily Production sample data.xlsx` (daily sheet, cols A–Q), **WB2** = `/Users/newuser/Downloads/Daily Production sample data2.xlsx` (Daily Production Review, cols B–Y), **APP** = current ERP flow (`ShiftProductionEntryService.php`, `ShiftProductionEntryPage.tsx`, `ShiftProductionEntryResource.php`).

---

## 1. Formula Dictionary

| # | Quantity | Source | Exact formula | Input UOM | Output UOM | Rounding | In app today | Redesign placement | Status |
|---|----------|--------|---------------|-----------|------------|----------|--------------|--------------------|--------|
| 1 | Piece weight | WB1 `B` / WB2 `E` / APP `item.nominal_weight_grams` | static master datum (WB2 enters it manually per row, "per product/mold") | — | g/pc | none | Item master field; drives kg conversions | Item (or item+mold) master | CONFIRMED as datum; per-mold variability NEEDS-VINCENT |
| 2 | Pack (pieces per box) | WB1 `G` / WB2 `H` / APP `item.nos_per_box` | static master datum | — | pcs/box | none | `item.nos_per_box`, used in box auto-fill | Item packing master | CONFIRMED |
| 3 | Trays per box | WB1 `D` / APP `item.trays_per_box` | static master datum | — | trays/box | none | Exists in item master + ItemResource, **never used** in flow | Item packing master (activate) | CONFIRMED datum, dead in app |
| 4 | Actual boxes | WB1 `F` / WB2 `M` (`X = +M`) / APP `no_of_box` | **Manual entry**, addition chain one addend per shift/lot (`=12+12+10`); APP instead auto-fills `ceil(quantity_produced / item.nos_per_box)` | boxes | boxes | WB: none; APP: `ceil` | Complete-batch drawer field (auto-fill, editable) | Complete Batch, per-shift/lot addends preserved | CONFIRMED input — but see Conflict C2 (direction reversed) |
| 5 | Pieces produced | WB1 `H = F×G` / WB2 `N = M×H` / APP `quantity_produced` (manual) | `pieces = boxes × pack` | boxes × pcs/box | pcs | none | Manual number in Complete Batch (primary input) | Auto-derive from boxes × pack, editable | CONFIRMED — see Conflict C2 |
| 6 | Production weight (good kg) | WB1 `I = H×B/1000` / WB2 `O = N×E/1000` / APP `quantity_produced_kg = bcdiv(bcmul(qty, grams, 4),'1000',4)` | pcs × g/pc | kg | WB: none (full float); APP: bcmath 4dp | Computed in service + read-only preview `((qty*g)/1000).toFixed(4)` | Same, Complete Batch auto-calc | CONFIRMED |
| 7 | Rejected pieces | WB1 `J` / WB2 `J` / APP `quantity_scrap` | **Manual entry**, addition chain per shift/lot (`=245+122+20`) | pcs | pcs | none | Manual field in Complete Batch | Complete Batch, per-shift addends | CONFIRMED input |
| 8 | Rejection weight (production) | WB1 `K = J×B/1000` / WB2 `P = J×E/1000` / APP `quantity_rejection_kg` | `rej_kg = rej_pcs × g/pc / 1000` | pcs × g/pc | kg | WB fmt 0.000 (display only); APP bcmath 4dp | Computed in service, shown in approval detail | Same, auto-calc | CONFIRMED (note: 61 WB2 rows have P typed as literal kg with J empty) |
| 9 | Rejection weight (QC) | WB2 `Q` | **Manual weighed literal** (29 rows shortcut `=+P`, QC defaulted to production) | kg | kg | fmt 0.000 display | **Absent** | New QC verification step before approval | CONFIRMED input (WB2 only) |
| 10 | Production-vs-QC rejection difference | WB2 `R = P − Q` | `diff = rej_kg_production − rej_kg_QC` | kg | kg | none | **Absent** | QC step / approval variance panel | CONFIRMED |
| 11 | Lumps (production) | WB1 `L` / WB2 `K` / APP `scraps[type=lumps].quantity_kg` | **Manual kg entry** | kg | kg | none | Complete Batch scraps[] row (no stock movement) | Complete Batch; scrap-stock movement per confirmed backlog | CONFIRMED input |
| 12 | Lumps (QC) | WB2 `L` (added 16.07) | manual kg; **feeds no per-row formula** (audit column only; section SUM exists) | kg | kg | none | Absent | Unclear — record-only vs gating | NEEDS-VINCENT |
| 13 | Total rejection kg | WB2 `S = Q + K` (QC rej kg + **production** lumps; QC lumps L ignored); WB1 day-level `K192 = K191+L191` ("scrap pet") | `total_rej = rej_kg_QC + lumps_production` | kg | kg | fmt 0.000 display | Absent as a named figure | Approval / Shift Summary | CONFIRMED formula; mixed QC+production sourcing → see Conflict C4 |
| 14 | Total raw-material consumed | WB1 `M = I + K + L` / WB2 `T = S + O = Q + K + O` / APP `actual_kg = Σ material_consumptions.quantity_issued_kg` | three competing definitions: good+prodRej+lumps (WB1); good+QCrej+lumps (WB2); Σ weighed RM issues (APP) | kg | kg | WB fmt 0.000; APP bcadd 4dp | APP version feeds VarianceSection | One canonical definition needed | **NEEDS-VINCENT** (Conflict C3) |
| 15 | Physically weighed production kg | WB1 `N` | **Manual** addition chain of weighment slips (`=130.29+131.606+111.52`) | kg | kg | none | Absent | New weighment capture (per lot/shift) | CONFIRMED input |
| 16 | Weighed-vs-calculated variance | WB1 `O = N − M` (**reversed** `=M−N` on rows 5, 99) | `variance = weighed_kg − calculated_total_kg` | kg | kg | fmt 0.000 display; sign must be normalized | Absent (APP variance is issue-based, different concept) | QC/approval cross-check; tolerance ~1–2 kg accepted in practice | CONFIRMED (normalize sign) |
| 17 | Expected consumption (RM norm) | APP `expectedConsumptionKg` | BOM wins: `expected = produced × Σ quantity_per` over BOM lines with kg-family uom (`norm_source='bom'`); else `produced × grams/1000` (`norm_source='item_weight'`); else null | pcs | kg | bcmath 4dp | VarianceSection on ApproveProductionPage | Keep; extend to resin/MB split | CONFIRMED (app only) |
| 18 | Expected resin (split) | none | — no formula anywhere splits PET into resin vs masterbatch | — | kg | — | Absent (BOM sums all kg components together) | Brief wants resin norm separately | **NEEDS-VINCENT** |
| 19 | Expected masterbatch | none | — no MB %, no MB column, no MB BOM convention in evidence | — | kg | — | Absent | Brief wants MB norm | **NEEDS-VINCENT** |
| 20 | Unaccounted material | APP | `unaccounted_kg = actual − expected − rejection_kg − scrap_kg` (chained bcsub, 4dp); null if expected null | kg | kg | bcmath 4dp; UI danger at \|x\| > 0.5 kg | VarianceSection | Approval panel; reconcile with WB1 col O concept and its ~1–2 kg tolerance | CONFIRMED (app) — see Conflict C5 |
| 21 | Consumption variance % | APP | `variance_pct = round((actual−expected)/expected × 100, 1)` | kg | % | round 1dp; thresholds ≤2 OK / ≤5 Watch / >5 Investigate | VarianceSection | Approval panel | CONFIRMED (app) |
| 22 | Expected boxes (EST BOX) | WB2 `W` | `ROUND(3600/CT × CAVITY × RUNNING_HRS / PACK, 0)` | s/shot, pcs/shot, hrs, pcs/box | boxes | `ROUND(,0)`; 14 rows +1 and 4 rows +2 manual fudge | **Absent** (no CT/cavity/hours captured) | New efficiency block; needs CT, cavity, running-hours capture | CONFIRMED |
| 23 | Expected pieces | WB2 (embedded in `W`) | `3600/CT × CAVITY × RUNNING_HRS` (never surfaced as its own cell) | s, pcs/shot, hrs | pcs | none | Absent | Optional intermediate display | CONFIRMED (derived) |
| 24 | Production efficiency | WB2 `Y = X/W × 100`; totals row: `Σ X / Σ W × 100` (ratio of sums, not avg of %) | `eff = actual_boxes / expected_boxes × 100` | boxes | % | stored full precision, displayed fmt '0' | Absent | Shift Summary / machine efficiency report; aggregate as ratio-of-sums | CONFIRMED |
| 25 | Actual trays | WB1 `E` / APP `no_of_trays` | WB1 per-product variants: `D×F` (92 rows), `F×const` (14), `F×n/m` e.g. `F×5/65` (56), `F/n` e.g. `F/12` (24), a few manual; APP: `ceil(quantity_produced / item.nos_per_tray)` | boxes (WB) / pcs (APP) | trays | WB: none, fmt 0.0 display (fractional trays kept); APP: `ceil` | Auto-fill in Complete Batch from item standard | Complete Batch; needs one canonical tray model + master data for the ratios | **NEEDS-VINCENT** (Conflict C6) |
| 26 | Expected trays | none | — no expected-tray norm anywhere (WB1 E is *actual consumption*) | — | trays | — | Absent | Brief implies expected vs actual | **NEEDS-VINCENT** |
| 27 | Nos per tray | APP `item.nos_per_tray` | master datum; WB equivalent would be `G/D` where D>0 — never verified | — | pcs/tray | — | Drives tray auto-fill | Item packing master | CONFIRMED field; WB reconciliation NEEDS-VINCENT |
| 28 | Tape rolls consumed | WB1 `C` | `F × 3.5/65` (168 products) · `F × 1.5/65` (14 products) · `F×3.5/65 + (1×6×F)/65` (rows 153–156, i.e. 9.5 m/box) — metres-per-box × boxes ÷ 65 m/roll | boxes | rolls (decimal) | none, fmt 0.00 display | **Absent** | Packing-consumption feature (confirmed backlog); needs per-product metres/box master field | CONFIRMED formula; per-product variant assignment NEEDS-VINCENT |
| 29 | Pouches | none | — zero evidence in WB1, WB2, or app | — | — | — | Absent | Named in brief | **NEEDS-VINCENT** |
| 30 | Running hours | WB2 `V` | manual; 8 = full shift, partials 2/4/6 | hrs | hrs | none | Absent | Needed for EST BOX/efficiency; interacts with downtime log | CONFIRMED input (WB2) |

---

## 2. CONFLICTS

- **C1 — Piece weight sources disagree.** WB1 `B` is a fixed template constant (often embedded in the product name, e.g. "250ml Square Clear - 18 g"); WB2 `E` is typed per row "per product/mold" and varies; APP has a single `item.nominal_weight_grams`. Candidate value clash: the pack-1040 100 ml item is 11.7 g in WB1 (row 107, "100 ml Boston Amber") vs 12.9 g in WB2 (row 7, same pack 1040 — product name not captured). One weight per item may be wrong if weight is really per-mold.
- **C2 — Derivation direction is reversed.** Workbooks: boxes are the primary input, pieces are derived (`H=F×G`, `N=M×H`). App: pieces (`quantity_produced`) is the primary input, boxes are derived (`ceil(qty/nos_per_box)`). App's `ceil` also fabricates a fractional extra box that the factory's box-first counting can never produce.
- **C3 — Three definitions of "total RM consumed".** WB1 `M = good + production-rej + lumps`; WB2 `T = good + **QC**-rej + lumps`; APP `actual_kg = Σ issued RM (weighed issues, includes Nos-uom lines like caps/cartons summed as if kg)`. The app additionally has WB-absent expected/variance math; the workbooks additionally have WB-only physical weighment (`N`). None of the three agree.
- **C4 — Total rejection mixes QC and production sources.** WB2 `S = Q (QC rej) + K (production lumps)`, ignoring QC lumps `L` entirely even after the 16.07 schema added it.
- **C5 — Two incompatible "variance" concepts.** WB1 `O = weighed output − calculated output` (tolerance ~1–2 kg accepted in practice, sign reversed on rows 5/99); APP `unaccounted_kg = issued − expected − rejection − scrap` (UI flags at 0.5 kg). Different bases, different tolerances, same word.
- **C6 — Tray math has no single model.** WB1 `E` has at least 4 formula families per product (D×F; F×const; F×n/m; F/n) plus manual rows, yielding *fractional* trays; APP does `ceil(pieces / nos_per_tray)` (integer). `item.trays_per_box` (≈ WB1 `D`) exists in the app but is dead.
- **C7 — Efficiency exists only in WB2** and is boxes-based at *actual recorded CT* and *actual hours* (`Y = X/W×100`), with 18 rows where `W` was manually fudged (+1/+2). App captures no CT/cavity/hours, so nothing comparable exists; app's variance % is a weight-based measure, not efficiency.
- **C8 — Rounding regimes differ.** Workbooks: zero ROUND/INT/CEILING except `W`'s `ROUND(,0)` — everything else full float with display-only formats (0.00/0.0/0.000). App: bcmath 4dp strings + `ceil` on packing + 1dp on variance %. Test fixtures must decide which precision is canonical.
- **C9 — Internal workbook defects any importer must not replicate:** WB1 `M8` references another product's lumps (`=+I8+L11+L8`, omits K8); WB1 O sign flip rows 5/99; WB1 SUM-range drift (H sums 3–190, K/L sum 3–189, M/N sum 4–192); WB1 sheet 31.3 `M193` overwritten manually (−53.08 kg vs true sum); WB1 `I176` overwritten with weighed values; WB2 rows 320–330 `T=R+O(+S)` copy error; WB2 `N104`-style typed-not-summed cells.
- **C10 — Batch/shift granularity.** Workbook addition chains (`F`, `J`, `N` in WB1; WB2 one row per machine/shift) carry per-shift/per-lot detail; the app collapses everything into one number per batch entry, losing the addends.

---

## 3. GAPS (candidates for Vincent)

1. **Resin vs masterbatch split** — no formula, ratio, or column anywhere separates PET resin from MB; app BOM lumps all kg components into one expected figure. What is the MB dosing rule (% of shot weight? per-colour master?)?
2. **Pouches** — demanded by the brief; zero evidence in either workbook or the app. UOM, norm, and where they're consumed all unknown.
3. **Expected trays / expected tape** — only *actual* consumption formulas exist; no norms to compare against.
4. **Canonical tray model + master data** — which of WB1's four tray-formula families is right per product, and what are the `n/m` ratios as master fields?
5. **Tape metres-per-box per product** — 3.5 vs 1.5 vs 9.5 m/box assignment (esp. rows 153–156's extra 6 m) is template lore, not master data.
6. **QC rejection & QC lumps process** — who weighs, when, and does QC disagreement block accountant approval? (WB2 `Q`/`L` exist; app has no QC actor at all — approval chain is Supervisor→PM→Accountant.)
7. **Physical weighment (`N`) capture** — per-lot weighed slips have no app home; is this the intended replacement for, or complement to, issued-RM tracking?
8. **Canonical "total RM consumed"** — resolve Conflict C3 (production-rej vs QC-rej basis vs issued-RM basis).
9. **Variance tolerance** — 0.5 kg (app) vs the ~1–2 kg accepted in WB1 col O practice; and whether tolerance is absolute kg or % of throughput.
10. **CT / cavity / running-hours capture** — required for EST BOX and efficiency; not in the app (relates to downtime log: is `V` = 8 − downtime?). Also the legitimacy of the +1/+2 EST BOX fudges.
11. **Shift A/B/C ↔ app shifts mapping** and whether redesign rows are per machine-shift (WB2 grain) or per batch (app grain).
12. **Nos-uom consumption lines in `actual_kg`** — caps/cartons counted in Nos currently sum into a "kg" total; intended?
13. **Efficiency aggregation rule confirmation** — WB2 uses ratio-of-sums for the day total; confirm for weekly/monthly rollups.

---

## 4. WORKED EXAMPLES (unit-test fixtures)

All lifted verbatim from the workbooks; keep full float noise where shown — it is real fixture data.

**Pieces produced (`pcs = boxes × pack`)**
- WB1 29.3.26 row 62: F=8 (as `=2+6`), G=1944 → H = **15552**
- WB2 row 6: M=7, H(pack)=840 → N = **5880**

**Production weight (`kg = pcs × g/pc / 1000`)**
- WB1 row 62: 15552 × 8 / 1000 = **124.416** (app bc: `bcdiv(bcmul(15552, 8, 4),'1000',4)` = `124.4160`)
- WB2 row 6: 5880 × 12 / 1000 = **70.56**

**Rejection weight, production (`kg = rej_pcs × g/pc / 1000`)**
- WB1 31.3.2026 row 107: J=1853 (`=888+445+520`), B=11.7 → K = **21.6801**
- WB2 row 7: J=601, E=12.9 → P = **7.7529**

**Rejection weight QC + difference (`R = P − Q`)**
- WB2 row 6: P=0.624, Q=0.62 (literal) → R = **0.004**
- WB2 row 179 (direct-kg era): J empty, P=4.43 typed, Q=4.43 → R = **0**

**Total rejection (`S = Q + K_lumps`)**
- WB2 row 7: Q=7.75, K=0.55 → S = **8.30**
- WB2 row 179: Q=4.43, K=2.84 (QC lumps L=2.95 **ignored**) → S = **7.27**

**Total RM consumed — both competing versions**
- WB1 (`M = I + K + L`) row 62: 124.416 + 3.16 + 0.5 = **128.076**
- WB2 (`T = S + O`) row 6: 0.62 + 70.56 = **71.18**; row 179: 7.27 + 67.62 = **74.89**

**Physically weighed kg + variance (`O = N − M`)**
- WB1 30.3.26 row 150: M = 184.828 + 26.068 + 0.55 = **211.44600000000003**; N (`=38.406+73.752+99.288`) = **211.44599999999997**; O = **0** (float-cancel — fixture proves 3dp display masks float diff)
- WB1 31.3.2026 row 107: M = 192.7321, N = 192.723 → implied diff **−0.0091** (O blank on this row)

**Expected boxes (`W = ROUND(3600/CT × CAV × HRS / PACK, 0)`)**
- WB2 row 6: CT=10.6, CAV=5, HRS=8, PACK=840 → 3600/10.6×5×8/840 = 16.1725… → **16**
- WB2 row 7: CT=12, CAV=5, HRS=8, PACK=1040 → 11.538… → **12**
- WB2 row 179: CT=13.6, CAV=2, HRS=8, PACK=161 → 26.30… → **26**
- Partial shift, WB2 row 131: HRS=4 → W=**9**

**Expected pieces (embedded numerator)**
- WB2 row 6: 3600/10.6 × 5 × 8 = **13584.9** pcs (÷840 → the 16.17 above)

**Production efficiency (`Y = X/W × 100`)**
- WB2 row 6: 7/16×100 = **43.75**
- WB2 row 179: 15/26×100 = **57.69** (display fmt '0' shows 58)
- WB2 row 131: 9/9×100 = **100**

**Tape rolls (`boxes × m_per_box / 65`)**
- WB1 row 62 (3.5 m): 8×3.5/65 = **0.4307692307692308**
- WB1 row 150 (1.5 m): 41×1.5/65 = **0.9461538461538461**
- WB1 rows 153–156 (9.5 m): `=F×3.5/65+(1×6×F)/65`

**Actual trays — one fixture per WB1 formula family**
- `D×F`: row 62: 6×8 = **48**
- `F×n/m`: row 107: 14×5/65 = **1.0769230769230769**
- `F/n`: row 150: 41/12 = **3.4166666666666665**
- App version (`ceil(pcs/nos_per_box)` cross-check): ceil(6601/161) = **41** = WB1 F150 (exact multiple; non-multiples would diverge)

**App expected/variance (item_weight norm, constructed on WB1 row 62 data — app-only formula)**
- expected = 15552×8/1000 = 124.4160; hypothetical issued actual = 130.0000 → variance_kg = **+5.5840**; variance_pct = round(5.584/124.416×100,1) = **+4.5** ("Watch"); with rejection 3.1600 and lumps 0.5000 → unaccounted = 130 − 124.416 − 3.16 − 0.5 = **+1.9240** (> 0.5 kg → danger highlight)

**Known-bad fixtures (importer must flag, not reproduce)**
- WB1 M8 `=+I8+L11+L8` (cross-row reference bug); WB1 rows 5/99 O reversed sign; WB2 rows 320–329 `T=R+O`, row 330 `T=R+O+S`; WB1 31.3.2026 M193 manual override 2587.68 vs true SUM 2640.757 (Δ −53.08); WB2 W rows 20,93,204,245,250,266,269,272,314,357,381,516,629,639 (+1) and 335,370,415,426 (+2)
