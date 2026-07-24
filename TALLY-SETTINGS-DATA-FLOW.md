# Tally Settings — Data Flow & Safeguards

How the Tally Settings dropdowns are populated, whether it's push or pull, what
happens when a different Tally company is connected, and how we prevent one
company's data from corrupting another's. Companion to
`TALLY-MASTERS-SYNC-FLOW.md` (the masters pull) and `TALLY-SYNC-MASTER-PLAN.md`.

---

## 1. Are the dropdowns hardcoded? No.

Both dropdowns on **Tally Sync → Settings** are **live data pulled from your
Tally by the on-site agent**, stored in the ERP database, then served to the
page. Nothing is hardcoded except the *left column* of the ledger mapping (the
posting **roles**, below).

| Dropdown | Source | Stored in | Company-specific? |
|---|---|---|---|
| **Tally company** | Tally's *List of Companies* | `app_settings['tally_companies']` | No — lists all companies in that Tally |
| **Ledger** (mapping right-side) | The **selected** company's ledgers | `ledgers` + `ledger_groups` tables | **Yes** — from the selected company |
| Ledger mapping **roles** (left column) | `TallyLedgerRole` enum (code) | — | Fixed (app's posting roles) |

---

## 2. Company dropdown — flow

```
Tally  ──"List of Companies"──►  Agent  ──POST /tally-sync/companies──►  app_settings.tally_companies  ──►  Settings <Select>
```

1. `exportCompanies()` (`tally-sync-agent/src/tally/masters.ts`) sends Tally's
   *List of Companies* request to `localhost:9000`. Needs **no** company loaded.
2. `reportCompanies()` → `POST /api/v1/tally-sync/companies` →
   `TallySyncAgentController::companies()` stores the list in `app_settings`.
3. `GET /tally-sync/settings` returns `companies`; the frontend builds the Select.

## 3. Ledger dropdown — flow (from the SELECTED company)

```
SELECTED company  ──Ledger + Group collections──►  Agent  ──POST /tally-sync/masters──►  ledgers / ledger_groups  ──►  grouped <Select>
```

1. During a masters pull, `exportLedgers()` / `exportLedgerGroups()` send export
   requests with `<SVCURRENTCOMPANY>` = **`cfg.tallyCompanyName`** (the company
   configured in the agent). So ledgers are **company-specific**.
2. `POST /tally-sync/masters` → `MasterSyncService` upserts them into `ledgers`
   and `ledger_groups`, matched on Tally **GUID**.
3. `TallySettingsController::show()` returns `ledgers` as `{name, group}`; the
   frontend renders them grouped (Bank Accounts, Sundry Debtors, Duties & Taxes…).

**What refreshes it:** any masters pull — the agent's masters interval (hourly by
default), the tray's **Pull Masters from Tally**, or the setup UI's **Run sync
test**. The dropdown always reflects the last pull.

---

## 4. Push, not realtime pull

**The cloud ERP never connects to Tally.** Tally has no cloud API and its
gateway is a local socket (`localhost:9000`). All data moves **agent → cloud, by
push**:

```
   Tally (localhost:9000)  ◄──reads──  AGENT (on-site)  ──HTTPS push──►  Cloud ERP
```

- Reading masters/companies = the agent **exports** from Tally locally, then
  **POSTs** to the cloud. Not a live cloud→Tally query.
- Posting vouchers = the cloud **queues** them; the agent **pulls the queue** and
  posts to Tally. Again, the cloud never reaches into Tally.
- "Real-time" therefore means **within one poll interval**, not instantaneous —
  by design (`TALLY-SYNC-MASTER-PLAN.md` §1/§2).

---

## 5. What happens when a new / different Tally is connected or selected

This is **single-tenant**: one ERP instance ↔ **one** Tally company
(`TECHNICAL-DOCS.md` §2). So:

- **A new Tally machine** = install the agent there and configure it (host/port/
  **company**/token). That agent then pushes *its* company's data. A different
  *machine* running the *same* company is fine — GUIDs are stable per company.
- **Selecting a different company** must be done in the **agent's** config (its
  Settings window drives which company it pulls, via `cfg.tallyCompanyName`). The
  ERP's Settings company selection is the *bound* company (see §6); today the
  agent's local config is what actually issues the export — keep them in sync.
  *(Planned improvement: have the agent read the selected company from the ERP so
  they can't diverge.)*
- **The danger:** if an agent configured for a **different** company pushed
  masters, they'd upsert into the **same** `items`/`ledgers` tables and **mix two
  companies' data**. That's what the safeguards below prevent.

---

## 6. Safeguards against malfunction / data override

1. **Company binding (trust-on-first-use).** The masters pull carries its source
   `company`. The cloud (`TallySyncAgentController::masters()`):
   - binds the instance to the first company that pulls (sets
     `app_settings['tally_company']`) if none is selected yet;
   - **refuses with HTTP 409** any masters pull whose company differs from the
     bound one — so a misconfigured agent can never overwrite/mix another
     company's data. Switching companies is then a deliberate admin act (change
     the selection in Settings), never an accident.

2. **Match on stable GUID, never name.** Every master is upserted by its Tally
   GUID. Renames update in place (no duplicates, no orphaning); items from a
   *different* company have *different* GUIDs, so they could only ever be added —
   and the §6.1 guard stops that batch entirely.

3. **Idempotent, transactional upserts.** The whole masters batch runs in one DB
   transaction (`MasterSyncService`), so a mid-batch failure rolls back — no
   half-updated state. Re-pulling is always safe (it just updates).

4. **No destructive deletes.** A pull only ever inserts/updates. Deleting an item
   in Tally does **not** delete it in the ERP; a previously soft-deleted item
   reappearing in Tally is *restored*, not duplicated. Nothing is silently lost.

5. **One-directional masters + read-only in the UI.** Masters flow Tally → ERP
   only; Tally-sourced fields are treated as read-only in the ERP
   (`Item::isTallySourced()`), so a staff edit can't drift from Tally and there's
   no reverse push to corrupt Tally.

6. **Scoped agent tokens.** The agent authenticates with a Sanctum token scoped
   to `tally-sync:*` abilities only — it can sync, nothing else. Each install
   gets its own revocable token.

---

## 7. Quick reference — endpoints & storage

| What | Endpoint | Writes |
|---|---|---|
| Report companies | `POST /tally-sync/companies` (agent) | `app_settings['tally_companies']` |
| Masters pull (+ company guard) | `POST /tally-sync/masters` (agent) | `item_groups`, `godowns`, `ledger_groups`, `ledgers`, `items`; binds `app_settings['tally_company']` |
| Read settings | `GET /tally-sync/settings` (staff) | — |
| Select company | `PUT /tally-sync/settings/company` (staff) | `app_settings['tally_company']` |
| Ledger mappings | `PUT /tally-sync/settings/ledger-mappings` (staff) | `tally_ledger_mappings` |
