# Store & Inventory surfaces — what each one is for, and what was merged

Read on 01-Sep-2026 against `decisionm/main` at d36d5ea, for the store/inventory
end-to-end pass. This is an AUDIT, not a decision: nothing here binds the
factory, and where it reaches a limit it says so rather than choosing.

## The five surfaces the brief named

| Screen | URL | Who reads it | The question it answers |
|---|---|---|---|
| Find | `/inventory/find` | anyone holding a number | "What IS this number?" — one box over every identifier space the factory writes on something (item SKU, bag barcode, lot, batch, carton, store issue). Addressable via `?q=` so a scanner wedge lands straight on an answer. Writes nothing. |
| Barcode & Labels | `/inventory/barcode-labels` | whoever is standing at the printer | "Reprint the label this bag was born with." Two tabs: per BAG (the barcode in your hand) and per RECEIPT (the lot register, with GRN provenance and remaining kg). **Mints no identity** — DEC-20260807-008 — and there is no "generate barcode" action. |
| Store ↔ Production | `/inventory/store-production` | the storekeeper | Both halves of the daily material act: the queue of what production asked for and the issue against it, and the two return doors. |
| Store Fulfilment | `/inventory/fulfilment` | the storekeeper | "Which customer order lines are waiting on stock, and what is held against them" — reserve, release, re-point, send to production. Moves no stock; gates no dispatch. |
| Stock Movements | `/inventory/stock-movements` | anyone reconciling | The whole factory's ledger, server-paged, filterable by purpose. Read-only, append-only underneath. |

## What was merged, and what was not

**Nothing was merged or removed in this pass, and nothing was found redundant.**
Each of the five answers a different question for a different reader; there is
no pair where one screen's answer is contained in another's.

Three earlier merges are already in place and were confirmed intact rather than
repeated: Store Issue Queue + Returns → the tabs of Store ↔ Production; the bag
and lot registers → the tabs of Barcode & Labels; Work Centers, Scrap Reasons,
Molds, Shifts and Product Standards → tabs of Production Configuration. Every
retired URL is still mounted behind a **query-preserving** redirect, so printed
links and bookmarks still land — that is the repo's established way of retiring
a URL without losing history, and it was reused rather than reinvented.

The one pair worth checking closely was the three fulfilment-named surfaces:

- `/inventory/fulfilment` — order lines waiting on stock, and the store's four
  actions on them. A **write** surface.
- `/inventory/planning` — the ETA behind every open production request, computed
  on read and stored nowhere. A **read**, and its refusals cascade rather than
  quoting a caveat-date.
- `/sales/fulfilment-control` — Sales' own control surface, gated on the sales
  module.

Different data, different readers, different writes. Not redundant.

**They must also stay in the Inventory nav group**, and that is a permission
fact rather than taste: `buildNavItems` gates a whole group on its parent's
module, so a storekeeper holding inventory permissions alone would lose both
entries under Sales while the routes still mounted and their API still gated on
`module:inventory` — permitted, existing, and unreachable. This was tried on
27-Aug and reverted; `AppLayout.nav.test.ts` now pins it.

## One named surface could not be located: "barcode suggestion"

No screen, endpoint or component by that name exists. The two nearest things,
and neither is it:

- **`suggested_category`** on the item master — a category DERIVED from the
  item's Tally stock group (DEC-20260827-001), with a `firm`/`low` confidence.
  A category suggestion, not a barcode one.
- **Barcode & Labels** — which states outright that it mints no identity and
  has no generate action, so it suggests nothing.

Reported as not-found rather than guessed at. If the owner meant a screen that
PROPOSES a barcode for something not yet labelled, that does not exist and
building one is new work, not a merge.

## Changes this pass did make

- Warehouses moved to the END of the Inventory group. Setup, not daily use —
  which the group's own header already said — and it had been sitting between
  Store ↔ Production and Stock Movements, splitting the storekeeper's run.
- The item master now OPENS on Materials rather than All. See the note on
  `CATEGORY_FACET_MATERIALS` for the boundary and for what was deliberately
  not done to finished goods.
