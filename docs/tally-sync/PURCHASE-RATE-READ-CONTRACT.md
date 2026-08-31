# Purchase-rate read contract

What the agent reads out of the factory's Tally, what the cloud does with it,
and the lines neither may cross. The sibling of `PO-VOUCHER-CONTRACT.md`, which
covers the outbound direction; this one is **inbound and read-only**.

## The shape of the claim

> Tally already holds what this factory agreed to pay and what it was billed.
> A buyer raising a purchase order should be able to see that instead of
> remembering it.

Nothing more. The ERP does not become the rate master, does not hold a per-item
price, and does not post anything.

## The hard lines

1. **No write to Tally, on any path.** `tally-sync-agent/src/tally/purchaseRates.ts`
   exports and parses; there is no post function in it. The cloud endpoint
   (`POST /api/v1/tally-sync/purchase-rates`) writes one table,
   `tally_purchase_rates`, and nothing in the ERP posts a voucher from that
   table. Existing approved workflows continue to handle voucher posting.
2. **No automatic read from Tally.** Since agent v0.3.4 the factory's rule is
   that the agent reads only when a person asks it to — the masters pull was
   demoted to a tray action after the 07/08-Aug-2026 corruption scare. A Day
   Book export is a *heavier* read than the masters one, so it is a tray action
   too: **Pull Purchase Rates from Tally**. There is deliberately no timer, and
   adding one would violate a standing factory rule.
3. **Owner/Accounts only.** Purchase rates and supplier identity are FC-06. The
   read endpoint and the vendor review both sit behind `module:finance` and
   re-ask `AgentIdentity::mayReadPurchaseDetails`. A login without that standing
   is refused the whole answer, never served a thinned one.
4. **Nothing invented.** A field Tally did not return is absent, not defaulted.
   A duty head listed without a rate stays null rather than becoming 0%; a rate
   that cannot be read is null rather than 0; a stock item the ERP does not
   mirror keeps its Tally name and no link.

## What is read, and where the tag names come from

Not guessed. Every tag below was read off the factory's own exports —
107 Purchase Order vouchers (12-Aug-2026) and 17 Purchase vouchers
(24-Aug-2026), registered in `docs/factory/sources/manifest.json` and held
outside the repo because they carry live purchase rates (Q38, FC-06).

Request: `TALLYREQUEST=Export`, `TYPE=Data`, `ID=Day Book`, with
`SVFROMDATE` / `SVTODATE` / `SVCURRENTCOMPANY` and **`EXPLODEFLAG=Yes`** —
without the last one Tally returns voucher headers with no inventory lines.

| What | Tag | Note |
|---|---|---|
| Kind | `VCHTYPE` / `VOUCHERTYPENAME` | `Purchase Order` → agreed; `Purchase` → billed. Whole-name match, never a substring — a `Purchase Return` must not be read as a purchase. |
| Identity | `GUID` + line position | The pair is the cloud's key, so a re-read updates rather than duplicates. |
| Date | `DATE` | `20260701` → `2026-07-01`. |
| Reference | `VOUCHERNUMBER`, `REFERENCE` | |
| Party | `PARTYLEDGERNAME` (then `PARTYNAME`, `BASICBASEPARTYNAME`) | A Day Book voucher carries **no party GUID** — the name is the link. |
| Party GSTIN | `PARTYGSTIN` | |
| Item | `ALLINVENTORYENTRIES.LIST` → `STOCKITEMNAME` | |
| **Rate** | `RATE` | **`674.000/Kgs.` — a number AND its basis.** Both halves are kept. |
| Quantity | `BILLEDQTY`, else `ACTUALQTY` | ` 48.000 Kgs.` |
| Amount | `AMOUNT` | Stored as a magnitude; Tally signs the inventory side of a purchase negative by its own convention. |
| GST | `RATEDETAILS.LIST` → `GSTRATEDUTYHEAD` + `GSTRATE` | Per line, on that voucher's date. The state head is spelled `SGST/UTGST`. |
| HSN | `GSTHSNNAME` | |
| Purchase ledger | `ACCOUNTINGALLOCATIONS.LIST` → `LEDGERNAME` | The local-vs-interstate evidence (DEC-20260812-003). Read, never enforced. |

## What is dropped, and why each matters

- **Any other voucher type.** The Day Book carries everything the factory did.
- **`ISCANCELLED` / `ISDELETED` / `ISOPTIONAL`.** Q39 names voucher 72 of the
  92 as the cancelled one. A cancelled voucher feeding "the latest rate" is a
  withdrawn number presented as evidence.
- **A line with no `RATE`.** Measured: 8 of 18 inventory entries in the 24-Aug
  purchase export carry one. A line index is *not* renumbered around a dropped
  line — the index is half the row's identity, and renumbering would make a
  later line change identity the day somebody fills that rate in.
- **A line whose voucher has no party or no readable date.**

## The unit rule — the safety story

Tally quotes a rate *per something*. Q40 records **28 of 382** purchase-order
lines carrying two units: trays and covers are bought by weight and counted in
pieces. A bare number prefilled onto a line whose unit is the other one
silently restates the price of a real order, and nothing on the screen would
show it.

So `PurchaseRateLookup` returns `may_prefill` and the form obeys only that:

- unit matches the item's own → may prefill (the buyer still presses **Use**);
- unit differs → **shown with both units**, prefill withheld, reason printed;
- no unit recorded → shown, prefill withheld. "No basis" ≠ "the same basis".

Comparison is case-folded, trimmed, and tolerant of Tally's trailing dot
(`Kgs.` = `Kgs`) — and nothing further. `Kgs` and `Nos` are not reconciled.
Netting one unit off another is the mistake FC-03 exists about; this is that
mistake with money.

## GST is not a per-item master

Q39 measured that 9 of 43 items appear under **both** 5% and 18%, and 3 of 20
vendors use both — the rate is a property of neither. It is stored on the
voucher line and shown attributed to the voucher's date. Nothing in this path
reads or writes `gst_rates`.

## The vendor half

`TallyVendorReviewService` computes, on every request, what Tally now says
about a party against what the ERP records. The queue is **never stored**, so
it cannot go stale behind a re-sync; the only thing persisted is what a person
set aside, scoped to the value dismissed so a later, different value is raised
again.

Matching, in order:

1. **Exact Tally identity** (`vendors.tally_ledger_guid`) — a GUID is Tally's
   own stable key.
2. **GSTIN, only when it is unambiguous.** Measured on the live All Masters
   export, **23 GSTINs appear on more than one ledger**, two Sundry Creditors
   among them sharing one. An ambiguous GSTIN produces a row that says so and
   offers nothing to apply (Q83).
3. Otherwise the party is proposed as a **new** vendor — and only inside the
   ledger groups the owner has named, which default to none (a creditors group
   is not a list of suppliers).

**Tally silence never clears anything.** A difference is raised only where
Tally holds a value. Contacts are nearly absent from these books — 4 emails and
78 phones across 1742 ledgers (Q84) — and letting that absence overwrite a
number somebody typed would be data loss dressed up as a sync.
