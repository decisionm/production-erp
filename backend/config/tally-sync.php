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
    | Snapshots shown per voucher (Phase 7)
    |--------------------------------------------------------------------------
    |
    | How many of a voucher's snapshots the show endpoint (GET
    | /tally-sync/entries/{id}) carries — the NEWEST this many, newest
    | first. A voucher retried through a long Tally outage holds one
    | snapshot per attempt, each with an XML body of up to 2 MB, and the
    | drawer used to receive them all. The response says how many exist
    | (`snapshots_total`) and whether the list is cut (`snapshots_truncated`).
    | What is KEPT is the retention rule above; this is only what is SHOWN.
    | Engineering default, not a factory decision.
    |
    */

    'snapshot_show_cap' => (int) env('TALLY_SYNC_SNAPSHOT_SHOW_CAP', 20),

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
    | TESTING-ONLY MAPPING, NOT A PRODUCTION ANSWER. TallyLedgerRole::Purchase
    | is ONE global ledger for every line on every order. DEC-20260812-003
    | measured that the factory's real Tally actually posts through FOUR
    | purchase ledgers (local × interstate × rate), and Q39 (which rate
    | applies, and by what rule) is still open — so this single mapping is a
    | staging/testing simplification for the OFF-by-default gate above, never
    | the factory's production ledger scheme. It must not be read as "the"
    | purchase ledger, extended to production, or relied on as evidence of
    | how many ledgers real postings need.
    |
    */

    'purchase_orders_enabled' => (bool) env('TALLY_SYNC_PURCHASE_ORDERS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Purchase Order → Tally: the allowed Testing Tally company (fail-closed)
    |--------------------------------------------------------------------------
    |
    | The exact company name of the factory's TESTING Tally instance — never
    | a secret, but never guessed or hardcoded either: a purchase-order
    | voucher may only be staged for the company the owner has actually
    | opened for testing. Surrounding whitespace is trimmed once, here, before
    | this is treated as configured or blank and before it is written to the
    | payload — a config-authoring convenience only, never a relaxation of the
    | match itself. A blank (after trimming) or unset value means there is
    | nothing to check a staged voucher's destination company against, so
    | TallySyncService::enqueuePurchaseOrder() REFUSES to stage anything
    | while purchase_orders_enabled is true and this is blank — fail closed,
    | never defaulted to the ERP's own name or any other guess. The (already
    | trimmed) value is carried on every staged PO's payload
    | (`allowed_company`) so the desktop agent — which alone knows which
    | company its local Tally has open — can compare it, verbatim and
    | byte-for-byte with no further normalization of its own, against its
    | configured `tallyCompanyName` before it ever builds or posts anything.
    |
    */

    'purchase_orders_allowed_company' => env('TALLY_SYNC_PURCHASE_ORDERS_ALLOWED_COMPANY'),

    /*
    |--------------------------------------------------------------------------
    | Receipt Note → Tally (GRN/inward) — OFF by default
    |--------------------------------------------------------------------------
    |
    | Whether a goods receipt (GoodsReceiptNoteReceived) stages a Tally
    | 'Receipt Note' voucher at all. THE FACTORY DOES NOT USE TALLY RECEIPT
    | NOTES — decided by the owner, DEC-20260830-001 (30-Aug-2026) — so OFF
    | is the DECIDED state, no longer a fail-closed reading of an open
    | question. The machinery is kept disabled rather than deleted so
    | history and tests keep their meaning; turning this on would
    | contradict the decision and needs a NEW owner record, never an env
    | edit alone. OFF here means the TallySyncEventServiceProvider listener
    | no-ops (logs and returns) rather than calling
    | TallySyncService::enqueueGoodsReceiptNote() — no new queue row is
    | created. Existing/historical rows are never deleted or altered by
    | turning this off, but TallySyncService::pending() also withholds any
    | already-pending or retried Receipt Note rows from agent delivery while
    | the flag is off, until it is re-enabled.
    |
    */

    'receipt_notes_enabled' => (bool) env('TALLY_SYNC_RECEIPT_NOTES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Receipt Note → Tally: the allowed Tally company (fail-closed)
    |--------------------------------------------------------------------------
    |
    | The Purchase Order rule (`purchase_orders_allowed_company` above) on
    | the Receipt Note path — DEFENSE IN DEPTH ONLY. The factory does not
    | use Tally Receipt Notes (DEC-20260830-001), the feature above stays
    | OFF, and NOTHING needs configuring here on any deployment. The gate
    | exists so that even a mistaken enable cannot post to an unchecked
    | company (the 28-Aug rehearsal's failure mode): blank-after-trim or
    | unset REFUSES staging while receipt_notes_enabled is true; the
    | trimmed value rides the payload (`allowed_company`) and the desktop
    | agent compares it byte-for-byte before building anything.
    |
    */

    'receipt_notes_allowed_company' => env('TALLY_SYNC_RECEIPT_NOTES_ALLOWED_COMPANY'),

    /*
    |--------------------------------------------------------------------------
    | Delivery Note → Tally (dispatch) — OFF by default, FAIL-CLOSED
    |--------------------------------------------------------------------------
    |
    | Whether a dispatch (DeliveryDispatched) stages a Tally 'Delivery Note'
    | voucher at all. OFF is a FAIL-CLOSED READING OF AN OPEN QUESTION, not
    | a decision — the owner has not ruled, and the evidence says the
    | factory has never used this voucher:
    |
    |   Transactions.xml (the factory's own July-2026 export) holds 195
    |   Payments, 177 Sales, 134 Receipts, 126 Sales Orders, 82 Journals,
    |   64 Purchases, 38 Stock Journals, 15 Purchase Orders, 15 Contras and
    |   1 Debit Note — and ZERO Delivery Notes. Of the 177 real Sales
    |   vouchers, NONE reference a delivery note (INVOICEDELNOTES.LIST is
    |   empty in all 177) while 163 reference a Sales Order directly.
    |
    | That is the exact sales-side shape of DEC-20260830-001 on the purchase
    | side (the factory does not use Tally Receipt Notes). Until the owner
    | and Accounts rule, the ERP must not invent a voucher type the
    | factory's books have never contained. Turning this ON needs an owner
    | record, never an env edit alone.
    |
    | OFF means the listener no-ops (logs and returns) rather than calling
    | TallySyncService::enqueueDelivery() — no queue row is created. The
    | ERP's own delivery, its stock movement and its trace are untouched:
    | this gate governs ONLY what is sent to Tally.
    |
    */

    'delivery_notes_enabled' => (bool) env('TALLY_SYNC_DELIVERY_NOTES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Sales Invoice → Tally — OFF by default, FAIL-CLOSED
    |--------------------------------------------------------------------------
    |
    | Whether issuing an ERP invoice stages a Tally 'Sales' voucher. OFF is
    | fail-closed for TWO independent reasons, either of which is enough:
    |
    | (1) DOUBLE POSTING. DEC-20260809-003 records that ALL real sales are
    |     invoiced DIRECTLY IN TALLY and that the ERP Sales module is
    |     demo-scale. If Accounts keys the invoice and the ERP also posts
    |     one, the sale is booked twice. Which book originates a sale is an
    |     open Accounts question.
    |
    | (2) THE VOUCHER IS MALFORMED. Independently of (1), the builder
    |     (tally-sync-agent salesInvoice.ts) says of itself "BEST-EFFORT
    |     TEMPLATE - NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE", and
    |     against the factory's own export it emits NO GST ledger entries,
    |     NO 'Rounding Off', a single sales ledger where Tally uses
    |     per-line ACCOUNTINGALLOCATIONS, and nests ALLINVENTORYENTRIES
    |     inside ALLLEDGERENTRIES where Tally has them at voucher level. A
    |     posted voucher would carry ZERO TAX. That is wrong under ANY
    |     answer to (1), which is why this gate is not a business choice.
    |
    | Turning this on requires BOTH an owner/Accounts answer and a builder
    | validated against a real export.
    |
    */

    'sales_invoices_enabled' => (bool) env('TALLY_SYNC_SALES_INVOICES_ENABLED', false),

];
