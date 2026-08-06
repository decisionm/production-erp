# Tally Masters Sync & Settings — Flow

What was built this phase and how data moves through it. Companion to
`TALLY-SYNC-MASTER-PLAN.md` (this implements its **Phase 5 – Masters pull** plus
the config/Settings layer). For the deploy pipeline see `DEPLOY.md`; for the
client-onboarding questionnaire see `TALLY-CLIENT-DISCOVERY.md`.

---

## 1. What this adds (in one line)

The ERP now **pulls masters from the client's Tally** (item groups, godowns,
ledger groups, ledgers, items) into its own DB, and a **Settings UI** lets staff
pick which Tally company to sync and map posting roles → real ledger names —
**all config, no code**, so a differently-set-up client needs no code change.

This is **one-directional, Tally → ERP** (the "masters flow down" half of the
split-ownership rule in `TALLY-SYNC-MASTER-PLAN.md` §3). The existing outbound
voucher queue (Sales Invoice / Journal Entry → Tally) is **untouched**.

---

## 2. End-to-end data flow

```
   ┌──────────────┐   XML over HTTP     ┌───────────────────────┐   HTTPS + Bearer    ┌──────────────────────┐
   │    TALLY     │  (localhost:9000)   │   LOCAL AGENT (tray)   │   (Sanctum token,   │   CLOUD ERP (API)    │
   │  Prime, on   │ ◄─────────────────► │  tally-sync-agent/     │    scoped ability)  │  Laravel @ Hostinger │
   │  the client  │   export masters    │                       │ ──────────────────► │                      │
   └──────────────┘                     │ tally/masters.ts       │                     │ TallySyncAgentCtrl   │
                                        │  exportMasters()       │  POST /tally-sync/  │  ├ masters()         │
                                        │  exportCompanies()     │       masters       │  ├ companies()       │
                                        │ mastersSync.ts (loop)  │  POST /tally-sync/  │  └ items()           │
                                        │ cloudApi.ts            │       companies     │        │             │
                                        └───────────────────────┘                     │        ▼             │
                                                                                       │  MasterSyncService   │
                                                                                       │   → module services  │
                                                                                       │        │             │
                                                                                       │        ▼             │
                                                                                       │   MySQL (upserts)    │
                                                                                       └──────────────────────┘

   STAFF (browser) ──► Tally Sync → Settings page ──► GET/PUT /tally-sync/settings ──► app_settings + tally_ledger_mappings
```

**The cloud never talks to Tally directly** — only the on-site agent does. The
agent authenticates outbound to the cloud with a Sanctum token scoped to
`tally-sync:masters` (poll/report/items are the other abilities).

---

## 3. The two flows in detail

### 3a. Masters pull (inbound, automatic)

Runs on the agent's own **slower loop** (`mastersPollIntervalSeconds`, hourly by
default) — separate from the voucher poll so one never stalls the other. Also
triggerable from the tray ("Pull Masters from Tally").

Each cycle (`mastersSync.ts` → `runMastersSync()`):

1. `exportCompanies()` reads Tally's company list (needs **no** company selected)
   → `POST /tally-sync/companies` → stored in `app_settings['tally_companies']`.
2. `exportMasters()` reads, from the configured company, five collections via
   Tally `FETCH` exports and normalises them to clean JSON:
   | Tally collection | → payload key | shape |
   |---|---|---|
   | StockGroup | `item_groups` | `{guid, name, parent}` |
   | Godown | `godowns` | `{guid, name, parent}` |
   | Group | `ledger_groups` | `{guid, name, parent}` |
   | Ledger | `ledgers` | `{guid, name, group}` |
   | StockItem | `items` | `{guid, name, base_unit, parent, alter_id}` |
3. `POST /tally-sync/masters` → `MasterSyncService::sync()` upserts everything in
   **dependency order inside one transaction**, returning a per-section
   `{created, updated, total}` summary.

**Defensive by design** (real client Tally data varies — the demo `Amruthaa & Co`
is only a test company): every field optional, matched on the stable **GUID**
never on names, Tally's control-char root marker (`\x04 Primary`) stripped to
"no parent", unknown shapes filtered out.

### 3b. Settings (staff, manual config)

`Tally Sync → Settings` page (`module:tally-sync` permission):

- **Company selection** — a dropdown of the agent-reported companies; the choice
  is saved to `app_settings['tally_company']`.
- **Ledger mappings** — for each posting **role** (`TallyLedgerRole`: sales,
  purchase, cgst, sgst, igst, round_off, resin_consumption, regrind_credit) a
  searchable dropdown of the **pulled ledger names**. Saved to
  `tally_ledger_mappings` (role → ledger name). This is what will let the voucher
  builders post to the right ledger per client **without hardcoding**.

---

## 4. How the DB data lands (table by table)

Every Tally-sourced table is **matched on `tally_guid`** (idempotent upsert:
re-pulls update in place, a soft-deleted row reappearing is restored). Hierarchy
columns (`parent_id`) are **self-referencing** so any nesting depth works for any
client with no schema change; they are resolved **by name in a second pass**, so
the pull is order-independent (`app/Support/Tally/HierarchyUpsert.php`).

| Table | Source | Key columns | Link resolved by |
|---|---|---|---|
| `item_groups` | Tally StockGroup | `tally_guid`, `name`, `parent_id`, `tally_parent_name` | parent → own `name` |
| `items` (extended) | Tally StockItem | `tally_stock_item_guid`, `tally_alter_id`, `item_group_id`, `uom` | `item_group_id` ← group `name` |
| `warehouses` (extended) | Tally Godown | `tally_guid`, `parent_id`, `tally_parent_name`, generated `code` | parent → own `name` |
| `ledger_groups` | Tally Group | `tally_guid`, `name`, `parent_id` | parent → own `name` |
| `ledgers` | Tally Ledger | `tally_guid`, `name`, `ledger_group_id`, `tally_group_name` | `ledger_group_id` ← group `name` |
| `app_settings` | Settings / agent | `key`, `value(json)` | keys: `tally_company`, `tally_companies` |
| `tally_ledger_mappings` | Settings | `role` (unique), `tally_ledger_name` | — |

Tally ledgers are mirrored into **TallySync-owned** `ledger_groups`/`ledgers` —
deliberately **not** the ERP's own `gl_accounts`, so pulling a client's chart of
accounts never disturbs the app's native accounting.

Write ownership follows `CLAUDE.md`: `MasterSyncService` (TallySync) delegates to
each owning module's service — `ItemGroupService`, `WarehouseService`,
`ItemService` (Inventory), `LedgerSyncService` (TallySync) — never writing another
module's models directly.

---

## 5. What was impacted vs. left alone

**Extended (all additive / nullable — existing flows unaffected):**
- `items` gained `tally_stock_item_guid`, `tally_alter_id`, `tally_synced_at`,
  `item_group_id`. Manual item creation still works exactly as before.
- `warehouses` gained `tally_guid`, `parent_id`, `tally_parent_name`.
- Agent-token abilities: added `tally-sync:items` and `tally-sync:masters`
  alongside the existing `poll`/`report`. **Old tokens keep working** for their
  abilities; a token issued after this deploy gets all four.

**Untouched:**
- The outbound voucher queue (Sales Invoice / Journal Entry → Tally) and its
  retry dashboard.
- `gl_accounts` and the ERP's native accounting.
- Every other module.

**Not yet wired (next steps):**
- The voucher builders still hardcode `"Sales Account"` — they should be changed
  to read `tally_ledger_mappings` (the payoff of §3b).
- An item-group **tree** on the Items page to visualise the pulled hierarchy.
- The agent still takes the company from its **local** config; it could instead
  read the ERP-selected `tally_company` so Settings is the single source of truth.

---

## 6. API reference (added this phase)

| Method & path | Auth | Purpose |
|---|---|---|
| `POST /api/v1/tally-sync/masters` | token `tally-sync:masters` | Full masters pull (groups, godowns, ledgers, items) |
| `POST /api/v1/tally-sync/items` | token `tally-sync:items` | Stock-items-only pull |
| `POST /api/v1/tally-sync/companies` | token `tally-sync:masters` | Agent reports available Tally companies |
| `GET /api/v1/tally-sync/settings` | staff `tally-sync.view/manage` | Company, companies, roles, mappings, ledger pick-list |
| `PUT /api/v1/tally-sync/settings/company` | staff `tally-sync.manage` | Select the Tally company |
| `PUT /api/v1/tally-sync/settings/ledger-mappings` | staff `tally-sync.manage` | Save role → ledger-name mappings |

---

## 7. Verification (as of this phase)

- Backend: **14 TallySync feature tests pass** (masters upsert, hierarchy
  resolution, idempotency, ability gates, settings CRUD, company report). Pint
  clean; frontend typecheck clean.
- End-to-end against the live test Tally (`Amruthaa & Co`): pulled **21 item
  groups, 6 godowns, 28 ledger groups, 150 ledgers, 20 items**, all with
  hierarchy and links resolved in the live ERP DB; company reported and shown in
  the live Settings endpoint with the 150-ledger mapping pick-list.
