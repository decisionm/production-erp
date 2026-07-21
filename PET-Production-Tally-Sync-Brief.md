> **Superseded.** This document has been reconciled with `TALLY-PRODUCTION-SYNC-PLAN.md` into **`TALLY-SYNC-MASTER-PLAN.md`**, which is now the single source of truth for this project. Kept here for history only — don't plan against this file.

# PET Bottle Production → Tally Sync System
### Project Brief & Decision Log

**Business:** PET bottle manufacturing unit, Puducherry, India
**Goal:** Replace the current Excel-based, next-day production entry with near-real-time (per-shift) capture that updates Tally inventory at the **end of each shift**, plus a live dashboard, notifications, and batch/QR traceability.

---

## 1. Problem Statement

- Production data is currently hand-entered into Excel across multiple shifts.
- The accountant does intermediate calculations in Excel and posts to Tally **the next morning** — a full day's lag.
- Shop-floor cartons are labelled by **hand** (production date/month + "packed by" name).
- Need: shift-level (not next-day) inventory effect in Tally, a dashboard for units/wastage/raw-material consumption, and automated batch identification via QR/barcode usable during shipment.

**"Real-time" definition (clarified):** hour/shift granularity — *not* millisecond. The target is "effect Tally inventory at shift close" rather than next-day.

---

## 2. Core Architecture Decision

> **Tally is NOT the real-time hub.** The web app is the real-time system of record for production; Tally is the downstream accounting/inventory ledger that we sync *to* on shift close.

Rationale: Tally is a desktop app, can't push events, and may be offline. Running live dashboards off it directly is a losing battle. Making our own DB the source of truth means real-time is fully in our control, and Tally sync becomes a queue-and-retry (eventual consistency) problem.

**Four layers:**

| Layer | Role | Difficulty |
|---|---|---|
| 1. Capture | Operators log production per shift/batch into the app | Easy (our code) |
| 2. Our database | Source of truth for production; feeds dashboards & notifications | Standard |
| 3. Sync engine | Local agent near Tally with an outbound queue; pushes on shift close, polls masters | **Specialized / highest risk** |
| 4. Tally | Accounting & inventory ledger | — |

---

## 3. Tally Integration

### Mechanics
- Tally exposes a built-in **HTTP-XML gateway**, default **port 9000** (enable: `F1 → Settings → Advanced Configuration → HTTP Server`).
- Three action types: **export** (read), **import** (write), **execute**.
- Structure: `ENVELOPE → HEADER → BODY`. JSON supported since Release 7.0.
- POST XML to `http://<Tally-IP>:9000`. The target company must be **loaded** in Tally.

### Practical gotchas
- **Reverse-engineer the XML:** create the desired voucher in Tally's UI, export it as XML, and copy its tag structure into your request.
- **Errors hide in HTTP 200:** parse the response body (LINEERROR / created-altered-ignored counts), don't trust the status code.
- **Incremental sync:** use **AlterID** (monotonic counter on masters & vouchers) + MasterID/GUID; track the last AlterID seen and pull deltas. Masters and vouchers handled separately.
- **Idempotency:** writes must be idempotent so a retry never double-posts a shift/voucher.

### Bidirectional sync = split ownership by entity (KEY DECISION)
Avoid true field-level bidirectional editing of the same record (conflict-resolution swamp). Instead:

- **Masters flow DOWN (Tally → app):** stock items, BOMs, godowns/locations, ledgers, units. Poll via AlterID, cache locally.
- **Production flows UP (app → Tally):** shift output → **Manufacturing Journal**.

This turns "bidirectional" into two clean one-directional flows on different entity types.

---

## 4. Production Data Model (PET-specific)

**Process (confirmed):** single-stage, single cycle — resin → bottles → packed in cartons **within the same shift** → warehouse. Bottles are *not* carried as loose inventory across shifts.

### Decision: ONE Manufacturing Journal per shift (per line)
Because bottles are packed same-shift, the model collapses to a single voucher:

- **Consumption:** PET resin + masterbatch/additives — *actual issued* quantities (kg), not BOM standard.
- **Production:** packed cartons as the finished SKU (Nos), produced **directly into the Warehouse godown** (no separate transfer voucher needed).
- **Scrap / by-product** (tracked separately in the same voucher):
  - **Regrind** — recoverable, re-enters stock as a valued item.
  - **Non-recoverable loss** — purge, contaminated, burnt lumps.

### Headline cost metric
```
Resin wastage = actual resin consumed − (bottles produced × nominal gram weight)
```
Over-weight bottles quietly burn resin — this is usually the biggest cost leak in PET, so it gets top billing on the dashboard.

> **Note / future option:** if you ever need to isolate blow-molding wastage from packing wastage, or carry loose bottles across shifts, split into two chained Manufacturing Journals (resin→bottles, then bottles+cartons→packed carton). Not needed today.

### The real project risk: codifying the Excel logic
The reason the accountant needs "next-morning work" is that the sheet encodes **undocumented logic** — how regrind is credited back, how shared resin is split, rounding, valuation. Extracting this logic from the accountant's head and codifying it once is the hardest and most important part — more than the sync plumbing.

### Human approval gate (DECISION)
Do **not** auto-fire vouchers into Tally the instant a shift ends. Instead:
1. At shift close, the app creates a **pending** entry with all calculations pre-done.
2. Accountant reviews and approves in one click.
3. Approved entry syncs to Tally.

Benefits: same control the accountant has today (minus retyping and the day's lag), a clean retry/idempotency boundary, **and** an access-control point (important — see Security).

---

## 5. Dashboard & Notifications

Both read from **our own DB**, so they're live at shift close regardless of Tally sync status.

**Dashboard metrics:**
- Units produced (bottles and cartons)
- Wastage, broken down: resin-weight variance + rejected bottles + packing rejects
- Raw materials consumed: resin, additives, **and cartons/film** (not resin alone)
- Yield
- **Sync-health panel:** queued / failed vouchers

**Notifications trigger on:**
- Batch complete
- Shift close
- Yield / variance threshold breaches
- **Tally sync failures**

Channels: in-app, email, SMS, WhatsApp.

---

## 6. Batch Traceability & Barcoding

**Principle:** the **batch number is the single thread** tying everything together. Generate it once at packing; the same code becomes:
1. Printed on the carton (QR),
2. The batch name + mfg date on the Tally Manufacturing Journal,
3. The reference scanned at dispatch.

Result: Tally gives batch-wise stock/movement natively, and you get end-to-end traceability. Replaces both the handwritten date and the "packed by" name.

**Use QR (not linear barcode).** Encode:
- Batch number — scheme e.g. `LINE-SHIFT-YYYYMMDD-seq`
- Product SKU + mfg date (+ use-by if applicable)
- Shift, line, and **operator** ("packed by" now automatic from the logged-in shift entry)
- Carton count / sequence

### Decision fork: batch-level vs per-carton serial
- **Batch-level:** every carton in a shift shares one code. Simple; enough for recall and lot labelling.
- **Per-carton serial (RECOMMENDED):** each carton gets a unique ID (batch + running number). Enables scan-verified truck loading, exact "which carton went on which truck," and short/over-shipment detection. Small extra cost; makes "use during shipments" genuinely powerful.

Dispatch flow: scan cartons onto the truck → app builds the dispatch → posts to Tally as a Delivery Note / Sales voucher carrying the batch reference.

**Compliance:** batch + mfg date on carton is likely an FSSAI labelling requirement for beverage/water bottles — confirm against current rules; this doubles as compliance.

---

## 7. Hardware & Printing

### Printing approach (DECISION): app sends raw ZPL, no label software
The printers speak **ZPL/TSPL** over network port **9100** (or USB). Build the label template **once**; from then on the app sends the filled label directly.

- Operator's entire interaction: enter shift data → tap **Print**. No BarTender/NiceLabel, no driver, no design, no typing of dates.
- This is what makes it workable for non-tech-savvy operators — all setup is hidden behind the app.

### Number of printers for 10 machines
Driven by **packing points**, not machine count. A desktop printer does a label in ~1–2 s (hundreds/hour), so throughput is rarely the constraint — the enemy is operators *walking* to fetch labels.

- **Centralized packing (likely, given single-cycle same-shift):** **1–2 printers** + 1 pre-configured spare.
- **Packing spread across the floor:** zone the machines — one printer per **2–3 adjacent** machines (~3–4 total).
- **Dispatch/warehouse:** **no printer** (you scan existing labels; add one only for pallet/dispatch labels).

### Setup rules for a low-skill environment
1. Networked, **app-driven** printers with **static IPs** — not USB-tethered-to-a-PC per machine.
2. Keep a **pre-configured spare**; swapping = plug in + point app at its IP.
3. **One label size + one ribbon type** across all printers (single consumable to stock).
4. Printers assigned per station **in the app, once**; operator only ever sees "Print."
5. App-side **failover** — reroute jobs to nearest printer if one is down.
6. Make dispatch-dock scanning work **offline + queue** (dock network is always flaky).

### Cost (current India pricing)
| Item | Spec | Price (₹) |
|---|---|---|
| Label printer | Thermal-**transfer** (ribbon) desktop, 4", 203 dpi — TSC TE210 / TVS LP46 / Zebra ZD230 | ~14,000–25,000 |
| — avoid | Direct-thermal (fades/smudges on cartons) | — |
| — overkill | Industrial metal chassis (Zebra ZT411/ZT610, SATO CL4NX) | 1,00,000+ |
| Scanner (2D, QR) | Wired | 3,000–8,000 |
| Scanner (2D, QR) | Wireless/Bluetooth (recommended for dispatch) | 6,000–15,000 |
| Scanner | Rugged Android handheld (optional, later) | 20,000+ |
| Consumables | Label rolls + wax/resin ribbon (ribbon = recurring cost) | few hundred/roll |

**Starter budget:** ~₹20,000–35,000 for printer + wireless scanner + starter consumables. Buy from a **local dealer (Chennai/Puducherry)** for on-site service + AMC.

---

## 8. Security (Tally gateway)

> **The Tally gateway on port 9000 has essentially NO authentication of its own** — no API key, no token, no username/password in the XML. Whoever reaches the port can read ledgers and post vouchers.

- **Never expose 9000 to the public internet.** VPN/firewall only.
- **TallyVault and user roles do NOT gate the XML gateway** — TallyVault encrypts data on disk; user roles govern interactive login. The gateway acts on the loaded company without those credentials.
- Treat 9000 as an **unauthenticated local socket**.

**Where security actually comes from (the local-agent design):**
1. Bind Tally to **localhost only** (or a locked-down LAN segment); the agent runs on the same machine and talks to `127.0.0.1:9000`.
2. The web app **never** talks to 9000 directly. The agent makes **outbound authenticated** calls to your backend (API key / mTLS / signed tokens) — all real auth lives on that hop.
3. If remote access is unavoidable, use a **tunnel** (Cloudflare Tunnel / VPN) with auth on the tunnel (e.g. bearer secret), not on Tally.
4. Build your own **approval gate + audit log** in the agent (which voucher, from whom, when). The shift-close approval step (Section 4) is this access-control point.

---

## 9. Effort & Complexity Assessment

| Component | Complexity | Notes |
|---|---|---|
| Shift-capture app, DB, dashboard, notifications | Moderate | Standard web-app work |
| Barcode/QR printing (app → ZPL) | Low | Template built once |
| Dispatch scanning | Low–Moderate | Needs offline queue |
| **Tally connector / sync engine** | **High (main risk)** | Local agent, idempotent writes, AlterID deltas, scrap/variance mapping |
| **Codifying the Excel calculation logic** | **High (hidden risk)** | The real "project inside the project" |
| Bidirectional (split-ownership design) | Manageable | Would be a swamp if done naively |

- **PoC** (read a ledger, post a voucher): 1–2 days.
- **Production sync engine** (agent, deltas, retries, multi-company, mapping): weeks. The local-agent piece is the most commonly underestimated.

---

## 10. Open Questions / To Confirm

1. **Capture method:** do operators enter shift output by hand, or is production auto-pulled from machines/PLCs? (Changes the capture layer substantially.)
2. **Packing layout:** is carton sealing centralized (1–2 points) or at each of the 10 machines? → decides 1–2 vs 3–4 printers.
3. **Volume:** cartons per shift? Under a few hundred → the ₹15k desktop printer is fine; thousands → move up a duty-cycle tier.
4. **Label unit:** label on each carton, or on a shrink-wrapped bundle/pallet?
5. **Serialization:** confirm batch-level vs per-carton serial (recommend per-carton).
6. **FSSAI:** confirm current carton labelling requirements for your product type.

---

## 11. Recommended Build Order

1. Nail down the **Excel calculation logic** (interview the accountant; document every rule).
2. Build the **capture app + DB + dashboard** (immediate value; independent of Tally).
3. Add the **local agent + Tally sync** with the **shift-close approval gate** (idempotent Manufacturing Journal, one per shift).
4. Layer in **batch/QR generation + printing** (app → ZPL).
5. Add **dispatch scanning** (offline-capable) → Delivery Note/Sales voucher with batch reference.
6. Add **notifications** (batch/shift/threshold/sync-failure).
7. Harden **security & audit** around the agent.
