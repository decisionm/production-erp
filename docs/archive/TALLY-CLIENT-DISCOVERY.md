# Tally & Production Discovery — Questions for the Client

**Purpose (plain):** We are building a simple tool for your shop floor to record each shift's
production, and then post that production into **your existing Tally** automatically — so Tally's
stock stays up to date without anyone re-typing yesterday's figures the next morning. Your Sales,
Purchase and accounting stay in Tally exactly as they are today; we are only adding the production
step and keeping stock in sync.

To do that safely, we need to understand how **your** Tally is set up. Nothing here changes your
Tally — these are just questions so our tool matches your company's names, units and ledgers exactly.

**How to use this sheet:** The accountant can answer most of Parts A–F just by looking at Tally on
screen — no technical knowledge needed. Part H is for whoever looks after the Tally computer/IT.
Where you're unsure, write "not sure" — that's a useful answer too. Feel free to attach screenshots.

> 💡 Boxes marked **"We can do this for you"** mean you don't have to — we can read it directly from
> Tally once the small connector tool is installed, or help you set it up cleanly.

---

## Part A — About your Tally (basics)

1. **Exact company name** as it shows at the top of Tally (spelling, spacing, "& / Pvt Ltd" etc.):
   `____________________________________________`
   - Do you keep **more than one company** in Tally? If yes, which one holds the real production/stock we should sync to?
     `____________________________________________`

2. **Tally version:** Tally **Prime** or **Tally.ERP 9**? Release number (Help → About):
   `____________________________________________`

3. **Which computer runs Tally?** Is it left **switched on during all production shifts** (incl. night)?
   `____________________________________________`

4. Is Tally **single-user** or **multi-user (network/server)**?
   `____________________________________________`

5. Is there a **TallyVault password** (Tally asks for a password to open the company)? Yes / No
   _(We don't need the password itself — just whether one exists, as it affects the setup.)_

6. **Financial year / "books beginning from" date** currently in use (e.g. 1-Apr-2026):
   `____________________________________________`
   _(This matters — Tally rejects vouchers dated outside the active year.)_

---

## Part B — Your stock items (products & raw materials)

7. Are **all** of these already created as **Stock Items** in Tally? Tick what's in Tally:
   - [ ] Finished bottles (each size/type as a separate item?)
   - [ ] Caps / closures
   - [ ] Cartons / boxes
   - [ ] Labels / film / packing
   - [ ] PET resin (raw material)
   - [ ] Masterbatch / colour / additives
   - [ ] Anything else: `__________________________________`

8. **How are items named?** Is there a consistent style, or are names typed freely?
   Give 3–4 real examples exactly as they appear in Tally:
   `____________________________________________`

9. **Stock Groups** — are items grouped (e.g. "Finished Goods", "Raw Material", "Caps & Closures")?
   List the groups you use:
   `____________________________________________`

10. **Units of measure** — what units are your items in? (e.g. bottles in **Nos**, resin in **Kg**)
    Do you use **compound units** like "Box of 12 Nos" or "Bag of 25 Kg"? List them:
    `____________________________________________`

11. **Godowns / Locations** — does Tally track stock by godown/location? How many, and their names?
    `____________________________________________`

12. **Batch tracking** — do any items use **Batches** in Tally (batch number + mfg/expiry date)?
    Yes / No — if yes, which items:
    `____________________________________________`

> **We can do this for you:** pull your full item list, groups, units and godowns directly from Tally
> once the connector is installed — you don't have to type them out. Question 8 (naming style) and
> 12 (batches) are the ones we most need _you_ to confirm.

---

## Part C — How production is recorded today (+ the recipe / BOM)

13. **Do you record production in Tally at all today**, or only the finished-goods sale?
    `____________________________________________`

14. If you do record production, **which voucher do you use?** (tick)
    - [ ] Stock Journal
    - [ ] Manufacturing Journal
    - [ ] Physical Stock / Stock adjustment
    - [ ] Not recorded in Tally — only in Excel
    - [ ] Not sure

15. Is **"Manufacturing Journal" / BOM** switched on? _(In Tally: F11 Features → Inventory → "Enable
    Manufacturing Journals" / Bill of Materials.)_ On / Off / Not sure

16. **How do raw materials get reduced in Tally today?** (e.g. resin stock going down as bottles are
    made) — is it automatic, entered manually, or not tracked in Tally?
    `____________________________________________`

17. **The morning Excel sheet** — the calculations the accountant does before posting to Tally are the
    single most important thing for us to understand. Please describe (or better, **share the actual
    Excel file**):
    - How is **resin wastage** worked out? `__________________________________`
    - How is **regrind / reusable scrap** credited back? `__________________________________`
    - When one machine/shift runs **several items**, how is shared resin **split** between them?
      `__________________________________`
    - Any **rounding rules** or **valuation method** (how a bottle's cost/value is decided)?
      `__________________________________`

18. **The recipe (Bill of Materials) for a finished bottle** — for us to raise a correct production
    entry, we need to know what goes into each bottle. For each main finished item, roughly:
    - Finished item: `________________` → resin per bottle (grams): `______`
    - Masterbatch / colour used, and **how much** (grams or %): `__________________________________`
    - Cap: `________________` (1 per bottle? which cap item)
    - Any label/film per bottle: `________________`
    - Is it easier to give this **per 1000 bottles** or **per 1 kg of resin**? Either is fine:
      `__________________________________`
    - Do you already have this **BOM defined inside Tally** for each item? Yes / No / Not sure

> **This is the part only your accountant/production team can answer** — it's the logic in their
> head/Excel today. Sharing the real Excel sheet(s) and one filled production report saves the most time.

---

## Part D — Purchase, Goods Receipt & Delivery (how goods move in and out)

_We need the full picture of your voucher flow, because production **consumes** raw material — so
we must know how that raw material's stock got into Tally in the first place, and how finished goods
leave._

19. **How do you enter purchases** of resin / caps / cartons? (tick the flow you actually use)
    - [ ] Straight **Purchase voucher** (bill entered directly, stock goes up then)
    - [ ] **Purchase Order → Receipt Note → Purchase voucher** (the full three-step flow)
    - [ ] Purchase Order → Purchase voucher (no separate receipt note)
    - [ ] Not sure

20. Do you raise **Purchase Orders** in Tally before buying? Yes / No / Sometimes

21. Do you use **Receipt Note (GRN)** vouchers when material physically arrives? Yes / No
    - If yes, **at which step does raw-material stock actually increase** in Tally — at the **Receipt
      Note**, or only at the **Purchase invoice**?
      `____________________________________________`

22. When you **dispatch finished bottles**, do you raise a **Delivery Note** voucher, or only the
    **Sales invoice**? (tick)
    - [ ] Delivery Note first, then Sales invoice
    - [ ] Sales invoice only (stock leaves at the invoice)
    - [ ] Not sure
          _(This decides how our planned dispatch/loading scan posts back to Tally.)_

23. Do you use any of these inventory vouchers regularly? (tick any)
    - [ ] **Stock Journal** for transfers between godowns
    - [ ] **Rejections In / Out** (returns)
    - [ ] **Physical Stock** (stock count adjustment)
    - [ ] Debit / Credit Notes
    - [ ] Others: `__________________________________`

24. **Who** enters purchases/receipts, and are they entered **same-day** or in a batch later?
    `____________________________________________`

> **Why we ask:** if resin stock only goes up at the _Purchase invoice_ (not at goods receipt), Tally
> can show negative/wrong resin stock mid-month once production starts consuming it — we need to know
> so our sync and reports account for it. And Q22 (Delivery Note vs Sales-only) directly shapes the
> dispatch-scanning step we plan to build later.

---

## Part E — Ledgers & accounting names

25. **Sales ledger** name(s) used on a bottle sale (e.g. "Sales - Bottles", "Sales @18%"):
    `____________________________________________`

26. **GST ledgers** — exact names as in Tally, and the usual rate(s):
    - CGST ledger: `________________` SGST ledger: `________________` IGST ledger: `________________`
    - Usual GST rate on your products: `______ %`

27. **Customer (party) ledgers** — created per customer? Under which group ("Sundry Debtors")?
    `____________________________________________`

28. **Supplier ledgers** for resin/caps/cartons — under "Sundry Creditors"? Any naming pattern?
    `____________________________________________`

29. **Purchase ledger** name(s) used on a purchase bill (e.g. "Purchase - Raw Material"):
    `____________________________________________`

30. Any **custom voucher types** you've created (renamed/extra Sales, Purchase, Stock Journal, etc.)?
    `____________________________________________`

> **We can do this for you:** export your full Chart of Accounts (ledger list) directly from Tally —
> but please confirm the **Sales, Purchase and GST** ledger names in Q25–29, as our vouchers must post
> to the exact same names.

---

## Part F — Please send us these (a big help)

Screenshots or exports are perfect — whatever is easiest:

- [ ] A **printout/PDF of one real Sales invoice**.
- [ ] A **printout of one Purchase bill** (and a **Receipt Note**, if you use them).
- [ ] A **printout of one Delivery Note** (if you use them).
- [ ] A **printout of one production/stock entry** as recorded today (if any).
- [ ] The **production Excel sheet(s)** used before posting to Tally (Q17).
- [ ] A screenshot of your **Stock Item list** (Gateway of Tally → Stock Items) — or we pull it directly.
- [ ] A screenshot of your **Chart of Accounts / Ledger list** — or we pull it directly.
- [ ] A blank **carton label** you use today (handwritten date/"packed by") — for the new printed label.

---



---

## What we recommend / can help set up

- **Enable "Manufacturing Journal + BOM" in Tally** (Q15/Q18) if you want Tally to track raw-material
  consumption automatically. If that's not wanted, we fall back to a simpler **Stock Journal** that
  just raises finished-goods stock — we can go either way; Q14–16 and Q18 decide which.
- **Consistent item names + stock groups + units** make everything downstream cleaner. If your item
  list is messy or half-built, we can help tidy it once (or set up a fresh, clean structure) before
  we start syncing — better to fix it now than sync mistakes in.
- **Get the goods-in flow right first (Part D):** if raw-material stock isn't reliably increased at
  purchase/receipt, production sync will look wrong even when it's correct. We can advise on a simple,
  consistent purchase → receipt → invoice habit if one isn't in place.
- **You stay in control:** production logged on the floor does **not** post to Tally automatically.
  The accountant **reviews and approves** each shift in one click, and only then does it go to Tally —
  the same control you have today, minus the retyping and the day's delay.

---

### For our team (internal notes — not for the client)

- Reverse-engineer every voucher by real export (MASTER-PLAN §3). Part F asks for sample Sales /
  Purchase / Receipt Note / Delivery Note prints; we still need to export the real
  Manufacturing/Stock Journal XML from their company once the gateway is up, to build the production
  voucher template against the version they actually run.
- Q17 (Excel logic) + Q18 (BOM recipe) = Phase 1.5 + Phase 4 payload — the highest hidden risk. Get
  the sheet, write it up as testable rules, have the accountant sign off before encoding.
- Part D (purchase/GRN/delivery) maps to: raw-material stock accuracy (context for production
  consumption), and Q22 Delivery Note vs Sales-only decides the Phase 7 dispatch-scan voucher target.
- Q1 (which company) + Q8 (naming) + Q10 (units) gate the items pull we've already built the cloud
  endpoint for. Confirm the real company before pulling — the demo `Amruthaa & Co` data looked
  half-set-up (parent = name, unit "rs"), which would sync junk if it's not the real target.
- Q15/Q31 (Mfg Journal on? gateway on?) decide Phase 4 payload shape and whether the agent can pull at all.
