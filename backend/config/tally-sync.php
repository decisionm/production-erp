<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Production voucher granularity
    |--------------------------------------------------------------------------
    |
    | How approved shift production entries become Tally Stock Journal
    | vouchers:
    |
    |   'shift' (default) — one aggregated Stock Journal per
    |             (production_date, shift): entries approved for the same
    |             shift merge into a single pending voucher
    |             (SJ-{Ymd}-S{shift_id}); entries approved after that
    |             voucher has already synced open a follow-up voucher
    |             (-2, -3, ...). Membership is tracked on
    |             shift_production_entries.tally_sync_entry_id so an entry
    |             appears in exactly one voucher. This is the factory's
    |             decided mode (DEC-20260807-010) and what live has run
    |             since the 07-Aug-2026 flip (DEC-20260807-014) — the
    |             packaged default says so, so a reader of this file alone
    |             is not misled about production.
    |   'batch'           — one voucher per approved entry (SPE-{id}),
    |             the original per-entry behaviour, retained byte-for-byte
    |             and still selectable via the env.
    |
    */

    'voucher_granularity' => env('TALLY_VOUCHER_GRANULARITY', 'shift'),

    /*
    |--------------------------------------------------------------------------
    | Shift-voucher release idle-hold (DEC-20260807-011)
    |--------------------------------------------------------------------------
    |
    | Under 'shift' granularity a voucher is offered to the agent only when
    | its shift's end_time has passed for its production date AND at least
    | this many minutes have passed since the voucher's last merge — so a
    | trickle of post-shift approvals keeps consolidating instead of the
    | agent's next poll freezing the voucher after the first one. The
    | accountant's "Release now" button overrides the wait. Irrelevant in
    | 'batch' mode, where vouchers are never held.
    |
    */

    'release_idle_minutes' => (int) env('TALLY_RELEASE_IDLE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Factory timezone
    |--------------------------------------------------------------------------
    |
    | The ONE definition of the factory's wall clock. app.timezone stays UTC
    | (changing it on a live system would silently shift the meaning of every
    | stored timestamp), so any comparison between now() and a factory
    | wall-clock string — a shifts.end_time, a day boundary — must localize
    | the wall-clock side through this timezone first. now() alone is never
    | compared against a wall-clock string. Scripts outside Laravel
    | (scripts/factory-knowledge) honour the same FACTORY_TIMEZONE variable.
    |
    */

    'factory_timezone' => env('FACTORY_TIMEZONE', 'Asia/Kolkata'),

    /*
    |--------------------------------------------------------------------------
    | Post-Tally snapshot retention (Phase 4)
    |--------------------------------------------------------------------------
    |
    | After each post the agent uploads a snapshot — the XML it sent, its
    | sha256, and what Tally answered — kept in tally_sync_snapshots beside
    | the entry so the Sync Control Center can show "what the agent sent /
    | what Tally answered". The XML bodies are bulk, not history: snapshots
    | older than this many days are deleted, for ANY entry, each time one is
    | stored (there is no scheduler on the host, so the prune rides on the
    | write). The history row (snapshot.stored on tally_sync_events, with the
    | sha256 and counts) is never pruned. Zero or less: keep everything.
    | Engineering default, not a factory decision.
    |
    */

    'snapshot_retention_days' => (int) env('TALLY_SYNC_SNAPSHOT_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Purchase Order → Tally (Phase 6, STAGED — OFF by default)
    |--------------------------------------------------------------------------
    |
    | Whether sending an ERP-raised purchase order enqueues a Tally 'Purchase
    | Order' voucher (DEC-20260812-002: POs are raised in the ERP and sent to
    | Tally as ORDER vouchers). OFF until the owner answers Q35(d) (the first
    | live PO write to real Tally is an OWNER GATE — it never happens
    | unattended, and no test, seeder, command or workflow flips this),
    | Q35(e) (which ledgers a PO voucher names — tax/rounding) and Q39 (one
    | purchase ledger or one per rate).
    |
    | What flipping it does: PurchaseOrderService::send() fires
    | PurchaseOrderSent; the TallySync listener calls
    | TallySyncService::enqueuePurchaseOrder(), which writes ONE
    | tally_sync_entries row (voucher type 'Purchase Order') and NOTHING
    | else — no stock movement, no balance, no lot, no journal
    | (PurchaseOrderTallyStagingTest counts them). The agent (≥ 0.3.9) then
    | builds an ORDER voucher — VCHTYPE 'Purchase Order', ISINVOICE No —
    | which Tally posts to neither accounts nor stock BECAUSE OF ITS TYPE,
    | not because any ledger block is left out (they are present, as in
    | every real export). While OFF, send() records tally_staging
    | {state: 'disabled'} on the PO and enqueues nothing; the PO's Tally link
    | is null and the resource says so honestly.
    |
    | The purchase ledger is NOT an env key: it is TallyLedgerRole::Purchase
    | via TallyLedgerMappingService (Settings → Ledger Mappings), one role for
    | now (Q39 pending). Unmapped → the enqueue REFUSES with a named reason;
    | nothing is defaulted, nothing is guessed. Same for the vendor's ledger
    | (vendors.tally_ledger_name) and each line's item (Tally-sourced items
    | only). Refusals are recorded on the PO (tally_staging.state 'refused'),
    | never thrown out of send().
    |
    */

    'purchase_orders_enabled' => (bool) env('TALLY_SYNC_PURCHASE_ORDERS_ENABLED', false),

];
