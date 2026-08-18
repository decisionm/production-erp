# Day Bin — full reference audit and classification (18 Aug 2026)

Commissioned after the owner reported Day Bin still visible on `/production/configuration`.
Scope: every remaining reference across frontend, backend, DB schema, API contracts, Start
Batch, material issue, production consumption, reports and historical data.

**The governing decision is DEC-20260817-001: the inventory locations are RM Store →
Production/WIP → FG Store, and there is no Day Bin.**

## The headline, stated plainly

The *target workflow* is free of the Day Bin. The *running system* is not. Day Bin is **not
a dead concept with leftover labels** — it is load-bearing in two places, and one of them
prices every batch the factory makes. A naive removal would not have crashed anything, which
is exactly what would have made it dangerous.

## What the owner was actually seeing

The `products` tab is innocent — `ProductStandardsPage.tsx` (2,741 lines) contains **zero**
Day Bin references. Three things rendered it, only one of which is tab-independent:

| # | What | Where |
|---|---|---|
| 1 | **The left-nav "Day Bin" entry** — renders on EVERY route, which is why it appeared on `?tab=products` | `frontend/src/app/AppLayout.tsx:95` |
| 2 | Machines tab copy — "…every batch, downtime entry and day-bin balance…" | `ProductionConfigurationPage.tsx:405` |
| 3 | Factory Rules tab help text — **inverted the rule** (below) | `ProductionConfigurationPage.tsx:715` |

(3) was not a stale label. It read: *"Where material issues from when the day bin cannot
supply it. The day bin itself is named on the Day Bin page."* — presenting the Day Bin as the
primary source and the RM Store as its fallback, the exact opposite of DEC-20260817-001.

All three are fixed. "Day Bin" is also no longer selectable as the location of a **new** stock
count (`ShiftProductionEntryPage.tsx:894`); rows already carrying that label keep it.

## Classification

Counts: **UI-only 14 · legacy-data 3 · actively-used 27 · historical-only 13.**

### actively-used — a NEW operation reads or writes it, or a figure depends on it

| Reference | File:line | Why it is live |
|---|---|---|
| `FactoryDayBinService::loadBag` → `ResinPoolService::fold` | `FactoryDayBinService.php:281` | **THE CRITICAL ONE.** Sole `fold` caller repo-wide; `completeBatch:877 → BagCostAllocationService::allocate` prices every batch's resin from that pool |
| `FactoryWarehouseResolver::consumptionSource()` day-bin branch | `FactoryWarehouseResolver.php:221-226` | Decides which warehouse `recordIssue` decrements at completion → `ShiftProductionEntryService:768,781` |
| `FactoryWarehouseResolver::dayBin()` | `:154-157` | Feeds the branch above |
| `closing_day_bin` request key | `CompleteBatchRequest.php:126-128`, `HandoverRequest.php:35-37` | → `recordClosingDayBin:3473`, fires on **every** completion and handover |
| `recordClosingDayBin` writer | `ShiftProductionEntryService.php:3473-3484` | Writes a `count` row every completion/handover |
| Handover opening basis writer | `:1658-1710`, `:1539` | Stamps `config_snapshot.opening_day_bin_basis` |
| Cancellation blocker | `:2778` | Blocks cancel when day-bin movements exist |
| `loadBag` 422 when no bin configured | `FactoryDayBinService.php:190-195` | Blocks the floor's Load-Material scan |
| `guardCommonInputBalance` ack gate | `:361-386`, threshold `config/production.php:91` | Refuses a scan above 25 kg estimated balance |
| `DayBinBalanceException` guards | `DayBinLedgerService::record` | Return/count balance refusals |
| `DayBinLedgerService` | whole class | Its own docblock calls itself historical-only, but `record()` still has 2 live writers |
| `GET/PUT production/settings*`, `factory-day-bin*`, `machine-resin`, `day-bin/load-bag`, `work-centers/{id}/day-bin` | `routes/api.php:546,550,562,566,583,891,880` | Live API contract |
| `FactoryDayBinPage` | whole page | Only writer of the warehouse setting; hosts a live scan |
| Complete-Batch closing field + consumption prefill | `ShiftProductionEntryPage.tsx:983,3037-3060,3178-3248` | Prefills the figures a supervisor posts |
| `WarehouseService::settingKeysNamingWarehouses()` | `WarehouseService.php:39` | Guards a warehouse from deletion while the setting names it |
| `WorkCenterService` / `ItemService` dependency checks | `:116-120` / `:108` | Guard machine and item deletes against `day_bin_movements` |
| `ResetTestData` reference-prefix filter | `ResetTestData.php:125-126` | Matches the literal `BAG_LOAD_REFERENCE_PREFIX` — **renaming the prefix silently breaks it** |
| `app_settings['production_day_bin_warehouse_id']` | `FactoryDayBinService.php:77` | **Actively-used IF SET — live value unread. See Q55(a)** |

### historical-only — real past values, read only to display history
`day_bin_movements` (all columns) · `material_bags.day_bin_work_center_id` · bag status
`in_day_bin` · `batch_resin_allocations.day_bin_movement_id` ·
`config_snapshot.opening_day_bin_basis` · carton internal trace (DEC-20260810-001) · PO trace
drawer day-bin terms · `ProductionReportService` / `TraceabilityReportExport` /
`PurchaseOrderTraceService` feeds · Start-Batch availability panel (`BinBayService:83-86` —
new segments read 0/null).

### legacy-data — exists, nothing reads it for a NEW operation
`TraceabilityService::loadBagToDayBin` / `returnFromDayBin` / `recordCount` (routes retired in
Phase 7.5; no HTTP caller remains) · `shift_stock_counts.location_label = 'Day Bin'`
(free-text, informational, never reconciled against StockBalance).

### UI-only — copy, labels, nav; nothing computed changes
The nav entry · the two config-page strings · the stock-count label · dashboard tile ·
Complete-Batch hints · resin auto-pick reason strings · report/approval/GRN copy · bag status
label · `resolveFactoryStore` option filter.

## Surfaces with NO Day Bin reference at all
The `products` tab component · `TallyGodownResolver` (**no godown resolves from the day-bin
setting** — it reaches Tally only indirectly, by deciding which warehouse lands on the
consumption line) · Tally voucher payload builders (comments only) · `StoreIssueService` /
`MaterialRequestService` / `ProductionWipLocationResolver` (comments only) · **`StartBatchRequest`
— no day-bin value gates a batch start.**

## What must NOT be touched

- Every row and column of `day_bin_movements`. DEC-20260807-006 requires the historical
  machine-stamped rows preserved; `work_center_id` must never be back-filled or nulled.
- `material_bags.day_bin_work_center_id` and the `in_day_bin` status value — real bag custody.
- `batch_resin_allocations.day_bin_movement_id` and its index — pre-2-Aug layer identity.
- `config_snapshot.opening_day_bin_basis` — frozen per-segment record.
- `shift_stock_counts` rows labelled `Day Bin` — real physical counts.
- `stock_movements` rows referencing `Day bin load — bag …`, **and**
  `FactoryDayBinService::BAG_LOAD_REFERENCE_PREFIX`, because `ResetTestData` matches that
  literal string.
- Anything already posted to Tally.

**No column is proposed for dropping. The rule is: stop new writes, leave the schema.**

## Removal plan

### (a) Done — pure UI, nothing computed changed
Nav entry removed · both config-page strings reworded · `Day Bin` no longer selectable on a
new stock count. Route left mounted and reachable by URL.

### (b) Blocked — needs a live read or an owner decision → **Q55**
1. **Read `production_day_bin_warehouse_id` on live.** Unset → the `consumptionSource` branch
   is a no-op and can be deleted behind a test. Set to a distinct warehouse → deleting it
   moves which warehouse new completions decrement.
2. **Build the store-issue-side cost inflow before retiring the scan.** Otherwise every batch
   silently drops from pool-priced to average-fallback/unpriced.
3. **Decide which of the five Day Bin refusals survive** — no-bin 422, the 25 kg ack gate, the
   return/count guards, the cancel blocker, and the honest `null`-not-zero consumption.
4. `closing_day_bin` must stay ACCEPTED (deprecated) even when retired — old floor tablets
   still send it.
5. Rename routes only behind an alias; `routes/api.php:571-580` records that old tablets still
   call the legacy path.
