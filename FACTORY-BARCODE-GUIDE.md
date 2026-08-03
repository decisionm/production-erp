# Barcode guide for the factory floor

Two barcodes exist in this system. A **resin bag** barcode and a **finished carton** barcode.
They are not related and they do different jobs. This guide gives the exact screens, in order.

Menu names below are exactly what you see on the left of the screen.

---

## Flow 1 — The resin bag

From the Tally purchase order to the machine.

### Step 1 — The order (Procurement → Purchase Orders)

The purchase order lives in **Tally**. It appears here so the store can see what is coming.
You do not create the order here.

### Step 2 — The arrival (Procurement → Goods Receipts)

When the lorry arrives, record the arrival here. This is the **only** screen that receives material.
Enter the supplier lot number and the bags.

### Step 3 — The bag labels (Inventory → Material Receipts & Bag Labels)

Open the supplier lot you just received and press **Print / Reprint**.
Stick one label on each physical bag.

The label is permanent. If a label is torn or unreadable, come back to this screen
and press **Print / Reprint** again — you get the same barcode, not a new one.

> The screen says it too: *"Creating or printing a label here does not consume material
> and does not post anything to Tally."*

### Step 4 — Incoming QC (Quality → Incoming Inspections)

Check the material that arrived and record the result.
Do this before the bag goes to the floor.

### Step 5 — Scan the bag in (Production → Shift Floor → **Load Material**)

Scan the bag barcode into the **common resin input**.

This is the one place resin enters the factory. **There is one common resin input for
all machines.** You are not saying which machine will use the bag, and you are not saying
which batch will use it. You are only saying: *this bag is now on the floor.*

---

### Four things that are true about the resin bag, and must stay true

1. **One common input for every machine.** Resin is not loaded per machine.
2. **A bag is never tied to a machine.** Scanning a bag does not claim a machine.
3. **A bag is never tied to a batch.** Scanning a bag does not claim a batch.
4. **Scanning a bag is not consumption in Tally.** Nothing is posted to Tally when you
   scan a bag in. Consumption is worked out later, from the shift, and costing is
   weighted average **per exact resin item** — the exact item name, not a group of them.

If anyone tells you the bag scan books material out in Tally, that is wrong.

---

## Flow 2 — The finished carton

From the approved batch to the lorry.

### Step 1 — Finish the batch (Production → Shift Floor)

Complete and count the batch on the Shift Floor.

**Cartons cannot be labelled until the batch is completed.** If you try earlier the
system says so: *"this batch is still running — cartons are labelled once the batch is
completed and counted."* That is on purpose — before the batch is finished the packed
count is still moving.

Note this is **completed**, not approved. You do not have to wait for the approvals.

### Step 2 — Make the carton labels (Production → Approve Production)

Open the entry for that batch. Once the batch is completed you will see a section
called **Carton Labels**. Press the **Carton labels** button.

Every packed box gets its own **permanent barcode** naming its batch.

### Step 3 — Print them (**Print / Reprint**)

The labels appear on screen. Press **Print / Reprint** and stick one on each carton.

Pressing **Carton labels** a second time does **not** create new numbers — it gives you
back exactly the same ones. Generate and reprint are the same button. A carton keeps
its number for life.

### Step 4 — Scan the cartons out (Sales → Deliveries)

Open the delivery and use the box that says:

> *Scan a carton barcode to add its box…*

Scan each carton as it goes onto the lorry. The system checks each one as you scan,
so if you pick up a wrong box you hear about it **while you are holding it**, not
twenty scans later.

A carton that has already gone out on another delivery cannot be scanned out a second
time. Two people scanning at once cannot send the same box twice.

---

## Quick reference

| I want to… | Go to |
|---|---|
| See what resin is on order | Procurement → Purchase Orders |
| Record resin that arrived | Procurement → Goods Receipts |
| Print or reprint a bag label | Inventory → Material Receipts & Bag Labels |
| Record incoming quality check | Quality → Incoming Inspections |
| Put a bag onto the floor | Production → Shift Floor → Load Material |
| Complete and count a batch | Production → Shift Floor |
| Make or reprint carton labels | Production → Approve Production → **Carton Labels** |
| Send cartons out | Sales → Deliveries |

---

## The one rule to remember

**Printing a label changes nothing. Scanning a bag changes nothing in Tally.**

Labels and bag scans are about *knowing where things are*. Stock and Tally move when
the shift is approved and when a delivery is made — not when a barcode is printed
or scanned in.
