# Daily production entry → Tally: the backlog

The owner's priority, verbatim (05-Aug): *"the daily production entry, each page
needs to enter in our app, and with consumption — all raw material, packing and
production material all goes to Tally."*

Everything below is either evidence from the factory's own records or a decision
still owed. Nothing here is assumed.

---

## Settled by evidence — build to these

### How each material leaves in Tally

Read off a real Stock Journal screen from their live company (05-Aug):

| Material | Tally unit | Example |
|---|---|---|
| `60 Ml Tray`, `100 Ml Tray`, `500ml Tray` | **Nos** | 464, 500, 60 |
| `200 Ml Round Master Box` | **Nos** | 200 |
| `500 Ml PAD` | **Nos** | 60 |
| `Poly Olefin Pouch` | **Kgs** | 233.000 @ ₹296/kg |
| `LDPE Cover 20X33X150 KGS` | **Kgs** | 42.500 @ ₹175/kg |
| `Packing Tape - Transparent` | **Nos** | 60 @ ₹37.58 |

So trays, boxes, pads and tape are **counted**; films and covers are **weighed**.
The ERP already models film as grams-per-piece into kg, which matches.

`500 Ml PAD` is a material class we had not seen anywhere else — not in the
workbook, not in the ERP. It needs a home.

### Quantities per batch (owner, 05-Aug)

- **Carton** — one item per batch, chosen from a dropdown. Quantity = boxes.
- **Tray** — one item, from the standard. Quantity = boxes × trays-per-box.
- **Pouch** — one per tray, so quantity = tray count → × grams ÷ 1000 = kg.
- **Bag (HM/LD)** — the entire pack. No tray, no pouch, no tape. *(shipped)*

### The paper production report has NO packing columns

Its columns are: `M/C · PRODUCT · WT · CT · BATCH · NOS/TRAY · NO OF TRAYS ·
NOS/BOX · NO OF BOX · PRODUCTION NOS · REJECTION NOS · PRODUCTION KGS ·
REJECTION KGS · LUMPS · CONSUMPTION KGS · CONSUMPTION (MB)`.

**Only resin and masterbatch are recorded per shift.** Cartons, trays, pouches
and tape appear in the *daily Tally journal* but not on the shift report — so the
packing counts are derived from the box/tray counts, not written by the machine
operator. That is why the tray and pouch numbers must come from the standard.

### Consumption arithmetic

`production kg + rejection kg + lumps kg`, all at **one** bottle weight.
Verified against every row of the paper. *(the two-weight bug is fixed)*

---

## Owed by the factory — each blocks something specific

| # | Question | Blocks |
|---|---|---|
| 1 | **Tape: how many metres in one roll?** We hold metres-per-box for 13 box types; Tally counts rolls at ₹37–44. Without the roll length there is no conversion. *Alternative: let the store enter rolls directly, since tape is a daily store issue, not a per-machine figure.* | Tape posting |
| 2 | **Pouch film: is `750×610` the tray cover or the box cover?** You described both. The sheet names one. | 9 unmapped film specs |
| 3 | **Is `Poly Olefin Pouch` the item for 750×610 / 780×610 / 835×610, and what does one piece of each weigh?** It is in **Kgs** in their journal, so a per-piece weight is required. | Film kg calculation |
| 4 | **`500ML` names three trays** — `500ML IFF Tray`, `500ml Tray`, `500ML Tray IFF`. Which, and are two of them the same thing? | 2 tray specs |
| 5 | **`500 Ml PAD`** — what is it, and which products use it? | A material class with no home |
| 6 | **Which resin?** `Relpet` (24 of 38 journals) and `PET Polyster Chips` (7 of 38), both ₹138/kg. Two real resins. | Default selection |
| 7 | **Scrap item** — their journals use plain `Pet Scrap`. Four scrap items exist in the masters. Confirm and we set it. | Scrap posting |

---

## Owed by us

### Shipped 5 August

- Backdate a batch, with a bounded window
- Cancel a batch from the machine card
- Scrap as a second produced line
- Opening stock from Tally's own closing position, refusing a second run
- Bag rule — HM/LD is the whole pack
- One pouch per tray, not one per carton
- One bottle, one weight — the resin over-report
- Packing specs re-mapped; terse notes
- 27 foreign/demo items retired

### Next, in order

1. **Hide CRM, Finance, HRMS, Payroll.** Empty menu items. Ten minutes.
2. **Rename shifts to A / B / C.** The paper proves it. Ten minutes.
3. **Rename machines to ASB-1 … ASB-10.** The floor and the paper both say ASB;
   the ERP says MC-01. Nobody on the floor recognises MC-01.
4. **The completion screen as the paper form** — dropdowns for carton, tray,
   pouch and colour; standard pre-selected; every number editable; no prose.
5. **Pack variants**, so 840 and 810 are a choice at the batch. Removes 18
   duplicate products and three record conflicts.
6. **Shift-page entry** — one screen per paper page, 10–12 machine rows entered
   together. Today a page costs roughly 60 interactions; this is the priority
   once the row itself is right.
7. **Idle time capture** matching the paper's six sections: breakdown, mould
   change, machine-wise rejection reason, resin stock in four places, masterbatch
   stock by colour, power interruption.
8. **Close the purchase loop** — PO → receipt → barcode → store → day bin.
9. **Dashboard** — daily / weekly / monthly, rejection rate, idle hours,
   efficiency loss with reasons.

### Known-open, lower priority

- Two overlapping configuration groups (#49/#50, #57/#58)
- No screen shows a cancelled batch
- `cycle_time_source` is not recomputed at completion (only cavities was fixed)
- Three demo vouchers still failing in the sync queue, left deliberately
- Their accountant posts **one journal per day**; we post one per batch. Same
  stock effect, more vouchers in the Day Book — tell them before they see it.
