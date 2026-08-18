# Deployment runbook — the Phase 0–4 stack (as of 2026-08-17)

Live is at `9a9cbe3` (deploy of PR #178, 16-Aug 15:52 UTC). **Nothing from the
engineering program is deployed yet.** Five phase PRs are open, stacked, all CI-green
and MERGEABLE/CLEAN, none reviewed. `main` auto-deploys (deploy.yml, maintenance
window). The merge chain (Builder → Cursor → Codex → owner) is the team's; this file
says exactly what each merge lands and what to check after, so nobody has to
re-derive it under time pressure.

## Order and content

| # | PR | Base | Migrations | Config / env | Live effect when deployed |
|---|---|---|---|---|---|
| 1 | #179 Phase 0 baseline | main | none | none | docs only (MASTER-PLAN, audit, logs) |
| 2 | #180 Phase 1 live-safety | #179 | none | `tally-sync.voucher_granularity` default `shift` (live already `shift` — no change); `/tally-sync/items` route REMOVED | FC-06 rate gates on 8 resources; local-fixture voucher hole closed; item `name` wire-key guard; ShowRoles read-only workflow |
| 3 | #181 Phase 2 sync foundation | #180 | `2026_08_16_100000_create_tally_sync_events_table`, `2026_08_16_100001_backfill_tally_sync_events` (idempotent) | none | events history table + backfill from existing entries; classification; filters/show/summary; payload rate-gated for non-finance readers (agent unaffected) |
| 4 | #182 Phase 3 every type | #181 | none | none | mapping states, summaries, drawer; **supplier identity withheld from readers without finance standing** (Tally rejection text included); ambiguous names fail closed in the preview (Q43); audit-fixtures command |
| 5 | #183 Phase 3.5 sales visibility | #182 | none | none | sales filters/show/trace/cancel; tally-mirror statement; **generic enqueue idempotent** (a re-fired event no longer mints a second voucher) |
| 6 | #184 Phase 4 snapshot | #183 | `2026_08_17_100000_create_tally_sync_snapshots_table` | `TALLY_SYNC_SNAPSHOT_RETENTION_DAYS` optional (default 90) | snapshot endpoint (accepts agent ≥ 0.3.8; older agents unaffected); **agent report endpoints (pending/ack/fail/snapshot) now require the agent's real token** — the live agent's PAT carries poll+report, so no change for it; a browser session can no longer ack/fail |
| 7 | #185 Phase 4.5 Export Center | #184 | `2026_08_17_120000_create_export_runs_table` | `EXPORT_ROW_CAP` optional (default 5000) | /exports (Downloads) for every login (catalogue filters by permission); PO/GRN lists gain filters (backward compatible; per_page 422 outside 1..1000 where it was clamped — no frontend caller affected) |
| 8 | #186 Phase 5 Product/SKU config | #185 | `2026_08_17_130000` (packaging unique index swap), `130001` (packing lines), `131000` (items.sku_provisional), `150000` (stock_movements.purpose), `150001` (purpose backfill — reversible only together with 150000) | none | two same-mode packings representable; identity-only PATCH; provisional SKU flag on newly pulled items; `inventory:check-ledger` available (read-only — run it after the deploy) |
| 9 | #187 Phase 5.5 Shift Floor | #186 | none | none | new batches stamp `production_v3_unified` (floored expected pieces — up to one shot below the paper's arithmetic; historical entries keep their formula); entries index filters; Completed Today server-side; a completion event (no listener) |
| 10 | #188 Phase 5.7 Shift Summary + CEC infra | #187 | none | none | Shift Summary report gains additive honesty keys (old keys aliased); `GET production/cec` (data only; format BLOCKED; export slot unchanged 409); Shift Summary page reconcile line + CEC preview (no download) |
| 11 | #189 Phase 6 Purchase chain + PO→Tally staged | #188 | `2026_08_18_100000` purchase_order_revisions · `100001` PO close/cancel + tally_staging + vendors.tally_ledger_name · `100002` goods_receipt_note_lines.stock_movement_id (all additive, no backfill) | **`TALLY_SYNC_PURCHASE_ORDERS_ENABLED` — leave UNSET/false.** Setting it true is an OWNER-GATED act (Q35(d)) and the first live PO post must be attended. The purchase ledger is a Ledger Mappings role (Purchase), not an env key | PO lifecycle actions (amend/close/cancel) + show/trace on the procurement screens; vendors gain a Tally ledger name field (empty until someone fills it — an unmapped vendor is REFUSED, never guessed); with the flag off nothing is staged and each order says 'not sent — disabled'. Agent 0.3.9 is NOT published, so the live agent (0.3.8) is unaffected and would refuse a Purchase Order entry it cannot build — which cannot arise while the flag is off |

Merging #179 first retargets #180 to `main` automatically when the base branch is
deleted on merge (GitHub behaviour) — merge top-down, one at a time, and let each
deploy finish before the next merge (each merge = one maintenance window).

## After EACH deploy — `.claude/skills/deploy-live-verify` applies, in this order

1. The deploy run's **migrate step output** — every migration named above must read
   `DONE` (a green tick is not evidence).
2. Site loads; the screens the phase touched render (Tally Sync page after #181/#182/#184;
   Sales pages after #183; PO/GRN drawers after #180).
3. **Tally Sync queue: no NEW failures, nothing stuck** — specifically an entry that is
   `pending` with `delivered_at` SET and no progress after a window that overlapped a
   sync cycle (issue #168 signature).
4. Server log for errors newer than the deploy (`read-server-log`, **≥ 10 min after the
   deploy's SSH session** — the Hostinger brute-force ban).
5. Already-posted vouchers byte-identical (no phase in this stack rewrites a payload;
   #183's idempotency and #184's snapshot are additive).

## Phase 1 live verification (owed before Phase 1 is "fully deployed")

After #180's deploy: (a) `show-roles` workflow (read-only) → confirm which roles hold
`finance.view/manage` — the FC-06 exposure map; (b) as a non-finance login on live, a
PO/GRN drawer shows no rate columns; (c) `tally-sync-status` workflow → queue healthy;
(d) an item rename attempt to a different wire name is refused (422) — API-layer check
by the reviewer, not a live write. Record the evidence in PHASE-LOG (Phase 1 →
"Deployment state").

## The agent

Agent 0.3.8 (Phase 4) is **built and tested on the branch, NOT published.**
Publishing is a deliberate manual act (`build-agent.yml` publish job, main-only;
`releaseContract.test.js` governs). Until it is published the cloud simply receives no
snapshots — nothing else changes. Publish only after #184 is live (the endpoint must
exist first).

## Rollback

Every migration in this stack is additive (new tables); rolling back code without
rolling back the tables is safe. `git revert` of a merge + deploy is the path; the
tables can stay.
