# seed-demo

One-off script that seeds a Tally company with masters (ledgers, stock groups, units,
godowns, cost categories, voucher types, stock items) and sample vouchers for the
single-stage ISBM PET bottle manufacturing demo ("XYZ Polymers Pvt Ltd" from the
planning doc). Not part of the shipped Electron agent — run manually against a live
Tally instance to get a populated company to build/test the sync logic against.

```bash
node run.js all           # everything, in dependency order
node run.js ledgers       # or one phase at a time — see `phases` in run.js
```

Env vars: `TALLY_URL` (default `http://127.0.0.1:9000`), `TALLY_COMPANY` (default
`Amruthaa & Co`).

## Hard-won gotchas (all confirmed against a live Tally Prime instance)

1. **Tally Educational Mode only accepts voucher dates on the 1st, 2nd, or last day
   of a month.** Every other day fails with `Voucher date is missing for: ...` — an
   error message that has nothing to do with the date's actual content or format.
   This took a long isolation session to identify (initially looked like a timing
   race, then like specific ledgers being broken) before the pattern became clear:
   days 1, 2, 31 worked; 3, 5, 10, 11, 15, 21, 22, 23, 25, 30 all failed, identically,
   regardless of ledgers/amounts/voucher type. A real (non-Educational) license
   removes this restriction. `vouchers.js` dates are pre-mapped to valid days.

2. **Root-level "Primary" parent must be omitted, not sent literally**, for Stock
   Groups — `<PARENT>Primary</PARENT>` fails with `Stock Group 'Primary' does not
   exist!` (Tally stores the reserved root internally with a control-char language
   marker). Omit the `<PARENT>` tag entirely for top-level groups; see
   `stockGroupXml` in `builders.js`.

3. **Cost Centres have no implicit root** unless the company's F11 "Maintain Cost
   Centres" feature is switched on first (a GUI-only toggle — not something to flip
   via XML). Without it, every cost centre create fails with `Cost Centre 'Primary'
   does not exist!`, with or without a `<PARENT>` tag.

4. **A failed master-import batch can leave "poisoned" name reservations** that
   block re-creating the *exact same name* later — even after restarting Tally —
   while a brand-new name works immediately. Hit this after one bad batch (an
   `<ISADDABLE>` tag on Stock Groups triggered the same "Primary" error above).
   Fix: rename the affected root-level master, or for sub-masters, re-`Create` with
   a valid non-root parent — Tally auto-converts it to an alter and heals the stub.

5. **Stock item alternate units**: the real field is `ADDITIONALUNITS`, not
   `ALTERNATEUNITS`. `CONVERSION` is a plain multiplier — "how many BASEUNITS equal
   1 of this unit" (e.g. base `Nos`, additional `Kg`, conversion `0.025` for a 25 g
   bottle) — not a `"1000=25"`-style string. Confirmed via Tally's `EXPORT
   TYPE="OBJECT"` request (see below), which silently omits fields it didn't
   recognize rather than erroring — so a "successful" import with `exceptions=0`
   is not proof the data actually landed.

6. **BOM (Set Components) and per-item HSN/GST rate are NOT implemented here.**
   Guessed tag shapes (`COMPONENTLIST.LIST`, `BOMNAME`, `HSNCODE`, nested
   `GSTDETAILS.LIST`) were silently dropped by Tally — no error, just absent on
   re-export. Only `GSTAPPLICABLE` is confirmed working. Getting BOM/HSN right
   needs the same approach as gotcha #5: create one item by hand in Tally's GUI
   with the feature configured, then read back its real shape via:

   ```xml
   <ENVELOPE><HEADER><TALLYREQUEST>EXPORT</TALLYREQUEST><TYPE>OBJECT</TYPE>
   <SUBTYPE>StockItem</SUBTYPE><ID TYPE="Name">Item Name</ID></HEADER>
   <BODY><DESC><STATICVARIABLES><SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   <SVCURRENTCOMPANY>Company Name</SVCURRENTCOMPANY></STATICVARIABLES>
   <FETCHLIST><FETCH>*</FETCH></FETCHLIST></DESC></BODY></ENVELOPE>
   ```

7. **Production/Regrind Recovery Stock Journals are not included** (spec entries
   (d) and (e)) — same class of unvalidated-shape risk as BOM, and this is exactly
   the "Manufacturing Journal" gap the agent's own `../../README.md` already flags
   as needing a hand-validated real Tally export before building. Don't guess it
   blind; use the technique in #6 once a real example exists.

8. **All ledgers here are created with `ISBILLWISEON=Yes`** regardless of group —
   simpler for a seed script, not a realistic per-group setting for a real company.

9. A stray `Test Group No Parent Tag` / `BatchRoot` / `BatchTestA` / `BatchTestB` /
   `Raw Materials Test2` stock groups and a `TEST-REPEAT-A` / `TESTA`...`TESTK-*`
   voucher may be sitting in the company from this debugging session — harmless,
   left alone rather than risk another delete-confirmation dialog hang (see #10).

10. **Deleting a master via XML can pop a confirmation dialog inside Tally's GUI**
    that blocks the single-threaded gateway — a subsequent unrelated request will
    time out until a human switches to the Tally window and dismisses it.

11. **Vouchers are NOT deduplicated by `VOUCHERNUMBER` the way masters are
    deduplicated by `NAME`.** Re-running `node run.js vouchers` (or `all`) posts
    genuine *duplicate* vouchers each time — same number, same date, same
    ledgers, but a new internal entry — rather than altering the existing one.
    `run.js` is safe to re-run for every other phase (masters correctly show
    `altered` on a second pass), but re-running `vouchers`/`all` after vouchers
    already exist will inflate ledger balances. Clean up duplicates via Tally's
    Day Book (filter by voucher number, delete extras) if this happens — don't
    script the delete given gotcha #10's confirmation-dialog risk.
