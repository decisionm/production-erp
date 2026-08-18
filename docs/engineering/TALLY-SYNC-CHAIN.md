# The Tally sync chain — as built, and what Phase 2 adds

**Status:** Phase 2 design + map · 2026-08-16 · basis `main` @ 9a9cbe3 + Phase 1 (#180)
**Rule:** this document describes code. Where it says "gap", the gap is proven by a
file:line, not inferred. Nothing here reads from Tally.

---

## 1 · The chain, link by link

```
ERP transaction ──► voucher type ──► master mappings ──► release state ──► agent payload ──► agent result ──► status/history
```

| Link | Where it lives today | Observable how |
|---|---|---|
| **ERP transaction** | `tally_sync_entries.syncable_type/id` (morph): `Invoice`, `JournalEntry`, `GoodsReceiptNote`, `Delivery`, `ShiftProductionEntry` (batch mode), `Shift` (shift mode) | resource `syncable_type`, `syncable_id` |
| **Voucher type** | `tally_sync_entries.tally_voucher_type` — one of `Sales`, `Journal`, `Receipt Note`, `Delivery Note`, `Manufacturing Journal` (label; wire = Stock Journal), `Stock Journal` | resource `tally_voucher_type` |
| **Master mappings** | item → `items.tally_stock_item_guid`; godown → `warehouses.tally_guid` via `TallyGodownResolver`; ledger → `tally_ledger_mappings` (only `sales` role read); packaging identity → `production_standard_packagings.item_id` frozen into `shift_production_entries.finished_item_id` | NOT on the entry — resolved at build time; the payload carries only **names** (`item`, `godown`, `party_ledger`, `sales_ledger`) |
| **Release state** | `ShiftVoucherReleaseGate::hold()` — computed from `status`, `delivered_at`, `released_at`, `syncable_type = Shift`, `payload.voucher_date` + `shifts.end_time`, `last_merged_at` + idle minutes. **Not stored.** | resource `hold {phase, shift_ends_at, last_merged_at, releasable_at}` |
| **Agent payload** | `tally_sync_entries.payload` (JSON, XML-agnostic). Every type carries `voucher_date`, `voucher_number`; four of five carry `party_ledger`; production carries `batch_number`, `shift`, `consumed[]`, `produced[]`, `withheld[]`, `resolution_log[]` | resource `payload` (raw) |
| **Agent result** | `POST /entries/{id}/ack` (no body) → `markSynced()`; `POST /entries/{id}/fail {error_message}` → `markFailed()`. **Agent identity is on the request** (Sanctum token name) but stored nowhere. Tally's raw response never leaves the factory PC | resource `status`, `synced_at`, `error_message`, `attempts` |
| **Status / history** | `status` ∈ `pending / synced / failed / dismissed`; timestamps `created_at`, `delivered_at`, `synced_at`, `released_at`, `last_merged_at`; `attempts` counter; `payload.resolution_log[]` | resource fields; `resolution_log` |

Direction: **every row in `tally_sync_entries` is ERP→Tally.** The Tally→ERP flows
(masters pull, company binding, stock-summary preview) go through
`TallySyncAgentController` and never create an entry.

## 2 · Where the history goes today — the proven gap

`TallySyncAgentController::agentLog()` (`:247`) already emits a stable event
vocabulary — `pending.delivered`, `voucher.synced`, `voucher.failed`,
`voucher.failure_refused`, `masters.received`, `company.bound`,
`companies.received`, `stock-summary.previewed` — **to a daily file**
(`storage/logs/tally-agent-YYYY-MM-DD.log`, 30-day retention). Not queryable, not
joinable, gone in a month.

On the entry itself the history is **destructive**:

| Fact | What happens to it |
|---|---|
| Each failure's error | `error_message` **overwritten** on the next failure |
| Each delivery | `delivered_at` **nulled** by `retry()` (:953) — the previous hand-out is gone |
| How many tries | `attempts` — a bare counter, no per-attempt error or time |
| Who retried / dismissed / released | `released_by` only; retry and dismiss record no actor |
| Which agent | never stored (token name is on every request) |
| Merge history (shift mode) | `last_merged_at` overwritten; which entries joined when — gone |
| Fix history | `payload.resolution_log[]` — inside **mutable JSON** the same code rewrites |

So the timeline the Control Center needs (created → approved → delivered → failed
(why) → retried (by whom) → delivered → acked) **cannot be reconstructed** for any
entry that has been touched more than once. That is the gap.

## 3 · What Phase 2 adds — and what it deliberately does not

### Adds

**One table, `tally_sync_events`** — append-only, the persistence of the event
vocabulary that already exists:

```
id · tally_sync_entry_id (nullable FK, cascade) · event (string) ·
direction (erp_to_tally | tally_to_erp | none) · occurred_at ·
actor_type (user | agent | system) · actor_id · actor_label (token/user name) ·
details (json) · created_at
```

Written by the SAME code paths that write the file log today plus the service
methods that mutate an entry (`enqueue`, merge, `pending()` delivery, `markSynced`,
`markFailed`, `retry`, `dismiss`, `releaseNow`, payload regenerate). Nullable
`tally_sync_entry_id` so **Tally→ERP** events (`masters.received`,
`company.bound`) become the first DB record of an inbound pull ever — direction
becomes filterable without inventing a mirror.

Backfill: one migration seeds best-effort events from existing timestamps
(`created_at`→`enqueued`, `delivered_at`→`delivered`, `synced_at`→`synced`,
`released_at`→`released`, current failed/dismissed state), each stamped
`details.backfilled = true` so nobody reads a reconstruction as an observation.

**`tally_sync_events` is on the posting path**: every enqueue, `pending()`
delivery, ack, fail, retry, dismiss and release writes it as part of the same
service call — the shift-voucher enqueue/merge, `pending()` and `retry()` inside
their own transactions, the rest immediately after the state change
(`TallySyncService` constructor docblock says which is which) — so a failing
event insert stops the mutation. The table cannot be dropped, renamed or made
read-only without touching posting.

**Classification, derived not stored** — `TallyTransactionCategory` enum +
`TransactionClassifier::classify(entry)` from `tally_voucher_type` +
`syncable_type`, exposed on the resource as `category {key, label,
wire_voucher_type, source_module, direction}`. The catalogue also names the
categories that **exist in Tally but not in the ERP** — Purchase, Purchase
Order (92 in the census; the ERP-originated version is planned, Phase 6),
Payment, Receipt, Contra, Credit/Debit Note (Statistics evidence,
TALLY-EVIDENCE §A) — as `source: tally`, and Sales Order (no such voucher type
in the books, DEC-20260809-003) as `source: absent`, all with count `null`, so
the Control Center can show them **honestly as "lives in Tally, not mirrored"**
without a read. A second axis, `erp_build: built | planned | none`, says what
the ERP has built for a category, so "in the books" and "the ERP will build it"
can both be true of Purchase Order without one word carrying both.

**A query service** (`TallySyncQueryService`) over `tally_sync_entries` with
server-side filters — status, category, wire voucher type, business date range
(`payload->voucher_date`, JSON path, both drivers), document/voucher number, party,
item, shift, machine (via members), `held` (gate evaluated on the small pending
Shift set, then `whereIn`), direction — plus a `summary` (today's counts in the
factory timezone; `held_now`, the gate's verdict at this moment regardless of
business date; per-category counts; catalogue; last agent ACTION from events —
a heartbeat poll that delivers nothing records no event).

**Endpoints, same route group:** `GET /tally-sync/entries` (filters added),
`GET /tally-sync/entries/{id}` (new — full resource + `history` = events),
`GET /tally-sync/summary` (new).

### Does not

- **No new status.** `needs_review` (audit §E-5) is deferred: today retry is manual
  only (the agent never re-picks a failed entry), so there is no infinite-retry
  loop to break; the failure + `fix.sentence` already say what to do. Adding a
  status touches every guard and 15 test files for a classification the events
  table now records. Revisit in Phase 3 with evidence from real failures.
- **No denormalised payload columns** (`business_date`, `document_number`).
  JSON-path filtering is unindexed but ~3–10 rows/day; revisit only if volume
  demands, and then as an index, not a copy.
- **No CSV here** — the export contract is built once in the Download Center
  (MASTER-PLAN Phase 4.5) on top of this read model.
- **No change to what reaches Tally**, to `voucher_number` formats, to the release
  gate, or to the agent. No Tally read of any kind. **One recorded deviation
  (Phase 3):** the entry resource now carries a `flags` object on EVERY response —
  the list, the action responses AND the agent's `GET /tally-sync/pending` — so the
  agent's rows gained one key. Accepted because the agent does no strict parse of
  the row — its `TallySyncEntry` interface (`tally-sync-agent/src/cloudApi.ts:12-33`)
  types the keys it reads and an extra key is ignored, not refused; the resource's
  own comment says so rather than claiming the prior shape is untouched. `flags`
  is always an object (`{}` when nothing is raised), never a list.
- **No UI redesign** — the existing page gets the filter bar and header counts
  wired to the new endpoints; the detail drawer and per-type views are Phase 3.

## 4 · Two invariants a later reader must not break

**Lock order between the poll and the shift merge (no deadlock possible).**
`TallySyncService::pending()` runs read → stamp → record inside ONE transaction and
locks only `tally_sync_entries` rows (id ascending). `enqueueShiftVoucher()` locks
`shift_production_entries` FIRST and then at most one `tally_sync_entries` row (the
merge target, `whereNull delivered_at`). Because `pending()` never touches
`shift_production_entries`, no lock cycle can be constructed — only bounded,
one-directional waiting. Do not add a `shift_production_entries` lock to `pending()`
or a second `tally_sync_entries` lock ahead of the member lock in the merge without
re-doing this analysis.

**`tally_sync_events.details` is not finance-gated — so nothing may write a rate
into it.** All twelve `record()` call sites today write counts, ids, voucher
numbers, error messages and flags; none writes `rate`, `amount`, `total_amount`,
`unit_cost` or `average_cost`. That is a rule, not an accident: the events history
is readable by anyone with `tally-sync.view`, and FC-06 applies to it. A recording
site that needs a money figure must gate the resource, not put it in `details`.

