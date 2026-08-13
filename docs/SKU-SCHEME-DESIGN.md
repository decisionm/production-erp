# SKU scheme for the item master — what depends on it, and the safe path

**Status: REPORT. Nothing built, no database touched, no SKU changed.**

The situation: 644 of 655 items carry a SKU that is a copy of the item NAME
("100 Ml Master Box"). Only the 11 non-Tally items have a real SKU
(`BTL-PET-1000`) — and, not coincidentally, the only HSN codes. The masters
pull evidently filled `sku` from `name`.

---

## 1 · What depends on `sku` today

### SAFE — Tally never matches on it

**No Tally payload uses `sku`, and no Tally match resolves on it.** Three
separate confirmations:

- The voucher builders name the item by **`name`**, and the code says so
  explicitly at three places (`TallySyncService.php:55`, `:111`, `:161`):
  *"`<STOCKITEMNAME>` must match. Never `sku - name`."*
- Item identity against Tally is the **GUID** (`tally_stock_item_guid`), which
  644 of 655 items already carry.
- The one `item_sku` in the codebase (`TallySyncService.php:196`) is a **log
  line**, and the agent never reads it — `grep item_sku` across
  `tally-sync-agent/src/` returns nothing.

So renaming SKUs cannot break Tally posting or matching. **The thing that must
never be bulk-edited is `name`** — that is what Tally matches — and this work
must not drift into touching it.

### NOT SAFE — three real dependencies

**(a) The local-fixture rule IS the SKU prefix.** `Item::isLocalFixture()`
(`Item.php:70-73`) is `str_starts_with($this->sku, 'LOCAL-')`, and that judgment
decides **whether a voucher posts at all** (`TallySyncService.php:193`,
`:215-220`; also `VoucherPreviewService.php:142`, `:180`, and a queue filter at
`:622`). It cuts both ways:

- give a real item a SKU beginning `LOCAL-` and **its vouchers silently stop
  posting**;
- move a fixture off that prefix and it **starts posting a name Tally cannot
  accept**.

Any bulk assignment must refuse the `LOCAL-` prefix outright, and must leave the
existing `LOCAL-` items alone.

**(b) An item is resolved BY SKU from config.** `scrapItem()`
(`TallySyncService.php:496-506`) looks up
`config('production.scrap.rejected_item_sku')` against `sku`, falling back to
`name`. Its own docblock warns that *"a near-miss books real weight against the
wrong one."* Change that item's SKU without changing the config and scrap
resolution silently falls through to the name lookup — or to nothing.

**(c) A delivery scan matches on SKU.** `DeliveriesPage.tsx:97` —
`selectedOrder?.lines.find((l) => l.item.sku.toLowerCase() === trimmed)`. This
is functional, not display: someone scans or types a code on the dispatch
screen and it is matched against `item.sku`.

### VISIBLE — two consequences that are not breakage but will be noticed

**(d) Every picker label in the app changes at once.** `itemLabel()`
(`lib/itemLabel.ts:31-44`) deliberately collapses the duplicate:
`bare(sku) === bare(name) ? name : "sku — name"`. Because sku == name today,
every label reads `100 Ml Master Box`. The moment real SKUs land, the same
labels become `MB-100 — 100 Ml Master Box` — across pickers, tables and drawers,
simultaneously. That is the function working as designed; it is still a
whole-app visual change and the floor should be told rather than surprised.

**(e) Already-printed labels go stale — but they do NOT stop scanning.**
Measured on live: **317 printed cartons, all 317 undispatched**, and **zero
material lots** (so no printed bag labels exist at all).

The exposure is smaller than it looks, and the reason is worth stating
precisely: **a carton is resolved by its carton number, not by SKU.** The
dispatch scan calls `lookupCarton(code)` → `GET /cartons/{cartonNo}`, and the
carton barcode is `{batch}-C{nn}`, which a SKU change does not touch. The SKU
appears on the label only as human-readable text (`cartonLabel.ts:34-36`,
`${sku} — ${name}`) and inside one error message.

So **none of the 317 would fail to scan.** What goes stale is the printed
first line: an old label reads the old name-derived SKU while every screen
shows the new one. Cartons are reprintable from the Approve drawer, so this is
a legibility problem with a remedy, not a dispatch problem.

The one SKU-matching path — `handleLineScan` (`DeliveriesPage.tsx:97`) — is a
manual convenience for typing an item code against a sales order, not the
carton route. After a change it matches the NEW sku, so someone reading an old
label and typing it gets *"No line on this sales order matches"* — a clear
refusal, never a silent wrong match.

**No transition period is needed for scanning.** A reprint fixes legibility.

---

## 2 · The smallest safe path

**Never a direct database write.** The requirement that `sku` changes must not
be able to touch `tally_stock_item_guid`, and must land in the activity log with
who and when, rules out SQL by construction.

**Use the existing import path, not a new one.** `production:import-product-master`
already resolves rows to items and is wrapped by a manual GitHub workflow with
`write: false` as its default — the house pattern for every live master-data
change (AGENTS.md). The smallest safe shape is a **new, narrow command that
does one thing**: set `sku`, on items matched by `tally_stock_item_guid`, from a
reviewed list.

Its shape follows the two commands written this week:

- **Dry run by default**, `--write` a separate deliberate act, the plan printed
  as a table before anything is written (`ImportMachineConfigurations`'s
  throwaway-transaction pattern, so reported counts equal a real run).
- **Matched on `tally_stock_item_guid`, never on name.** 644 of 655 items carry
  one. Name matching is what the customer import already refuses, for the same
  reason.
- **Refuses** any proposed SKU beginning `LOCAL-` (dependency (a)), any SKU that
  collides with an existing one, and any row whose GUID matches nothing —
  reported, not skipped silently.
- **Leaves `name` and `tally_stock_item_guid` untouched by construction** — the
  command writes exactly one column.
- **Activity log**: the item update must go through the model so
  `spatie/laravel-activitylog` records who and when.
- Wrapped in a manual workflow with `write=false` default, inputs passed through
  `env:` and validated — the injection lesson from the customer-import
  workflow applies here too.

**Before it runs, the config in dependency (b) must be checked**, and the
`LOCAL-` items in (a) explicitly excluded from the input list.

---

## 3 · What is the SKU FOR? — the question that must precede a format

**No format is proposed here, deliberately.** The catalogue already carries four
identities: `sku`, `tally_stock_item_guid`, `hsn_sac_code`, and the carton/bag
barcodes minted at `LOT{id}-B{seq}` and `{batch}-C{nn}`. A fifth identity
without a stated purpose is another thing to keep in sync, and the format
follows the purpose rather than the other way round:

- **internal reference** — short, human-typable, stable; collisions matter more
  than readability;
- **barcode** — must be scannable and unique, and would want to agree with the
  existing carton/bag scheme rather than compete with it;
- **customer-facing** — appears on invoices and delivery notes, so it is a
  commercial decision, not a technical one;
- **Tally matching** — **already solved by the GUID**, and a SKU built for this
  purpose would duplicate an identity that already works.

This is filed as **Q42**.

---

## 4 · The overlap with HSN — one disruption, in the right order

HSN is missing on **the same 644 items**. If the team is going to review every
item row anyway, SKU and HSN in one reviewed pass is one disruption instead of
two.

**But only in this order: the HSN fetch from Tally must land FIRST.** Tally
already holds the HSN for these items — the Day Book carries `GSTHSNNAME` per
stock item — so anyone typing 644 codes by hand would be re-keying data the
factory already owns, and getting the `4819.10.10` / `48191010` spelling
inconsistency wrong by hand into the bargain.

The HSN work is a fetch-field change in the **agent**, shipped as a released
version installed on the factory PC, and triggered as an operator-run masters
pull inside the quiet window — small work with an operational tail. Sequenced:

1. HSN fetch lands in the agent, is installed, and one masters pull is run.
2. The team reviews items with HSN already populated, and supplies SKUs for the
   same rows.
3. One reviewed dry run, read, then one write.

Doing SKU first would mean touching every row twice, and would waste the review
pass that HSN needs anyway.
