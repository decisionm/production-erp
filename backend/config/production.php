<?php

/*
 * Reconciliation tolerances for the approval screen. Every band the UI
 * colours comes from here — nothing is hard-coded in code or frontend,
 * per the shift-redesign brief ("tolerance values must be configurable").
 * All thresholds are overridable per deployment via .env.
 *
 * The blocking thresholds default to null = OFF: exceeding them then
 * merely shows the "investigate" band. Set them to enforce a hard gate
 * at accountant approval (entry must be rejected back or corrected).
 */
return [
    'tolerances' => [
        // Consumption variance % vs norm: |pct| <= ok -> ok, <= watch ->
        // watch, else investigate.
        'variance_pct_ok' => (float) env('PROD_TOL_VARIANCE_OK', 2),
        'variance_pct_watch' => (float) env('PROD_TOL_VARIANCE_WATCH', 5),

        // Unaccounted material (kg) beyond which the figure is flagged.
        'unaccounted_kg' => (float) env('PROD_TOL_UNACCOUNTED_KG', 0.5),

        // Efficiency % bands, checked in this order: > over -> over_standard,
        // >= ok -> ok, >= watch -> watch, else investigate.
        //
        // efficiency_over DEFAULTS TO EXACTLY 100, and that is a physical
        // fact rather than a tuning knob. The owner's words (30-Jul): "the
        // efficiency should not go more than 100%. if a machine can produce
        // a certain [amount] of material how can it be more than that". A
        // run measured above its own standard is therefore not a triumph,
        // it is evidence that one of the inputs is wrong — produced count,
        // running hours, cavities, or (most often) a standard cycle time
        // set slower than the machine really runs. The band exists so every
        // screen can say so LOUDLY; it never blocks anything, because the
        // pieces were genuinely made and the shift must still be recorded.
        //
        // The env override exists so the factory can later allow a small
        // measurement margin (say 102) without a deploy — a deliberate
        // decision made against real shifts, not a default anyone should
        // drift into. Raising it does not make over-standard runs correct,
        // it only widens the noise floor before we shout about them.
        'efficiency_over' => (float) env('PROD_TOL_EFFICIENCY_OVER', 100),
        'efficiency_ok' => (float) env('PROD_TOL_EFFICIENCY_OK', 95),
        'efficiency_watch' => (float) env('PROD_TOL_EFFICIENCY_WATCH', 85),

        // Hard gates (null = disabled). When set, accountant approval is
        // refused while the figure exceeds the threshold.
        'variance_blocking_pct' => env('PROD_TOL_VARIANCE_BLOCKING') !== null
            ? (float) env('PROD_TOL_VARIANCE_BLOCKING') : null,
        'unaccounted_blocking_kg' => env('PROD_TOL_UNACCOUNTED_BLOCKING') !== null
            ? (float) env('PROD_TOL_UNACCOUNTED_BLOCKING') : null,
    ],

    /*
     * FOUR EYES ON THE APPROVAL CHAIN.
     *
     * The plant manager verifies the shift, the accountant reconciles it and
     * posts it to Tally. Two gates are only two gates if two people clear
     * them. Until this existed nothing stopped one account doing both: the
     * PM stage became a formality, and the audit trail recorded the same
     * name in both signature columns — which is precisely the evidence an
     * auditor asks for and precisely what it would fail to be.
     *
     * With this false (the default) the accountant approver must be a
     * DIFFERENT user from the plant-manager approver, and the refusal says
     * so in plain words. Set PROD_APPROVALS_ALLOW_SAME_USER=true for a
     * genuine one-person office where the owner really is both roles — a
     * deliberate decision, written down in .env where anyone can see it,
     * rather than a control nobody noticed was missing.
     *
     * THERE IS DELIBERATELY NO ADMINISTRATOR EXEMPTION, and that omission is
     * the whole point rather than an oversight. "Let admins sign twice" is
     * the obvious next thought and it would swallow the rule entire: in this
     * deployment the people who approve shifts are the same handful who hold
     * the Administrator role, so exempting them would mean the rule binds
     * nobody capable of breaking it. A one-person office relaxes it with the
     * flag above — in the open, for everyone — not silently for whoever
     * happens to carry a role.
     *
     * SCOPE is the accountant gate and the quality gate.
     *
     * The quality gate compares the checker against the person who COUNTED
     * the output at Complete Batch (shift_production_entries.completed_by) —
     * a check that certifies its own count certifies nothing. The accountant
     * gate compares against the plant manager's signature. The same flag
     * relaxes both, because a one-person office is one-person for all of them
     * or none.
     *
     * IT IS STILL NOT THE PM GATE, and the reason is now narrower than it was.
     * It used to be "the PM is first in the chain, so there is no earlier
     * signature to collide with"; with quality sitting ahead of the PM that is
     * no longer true — a QC checker who also holds the Plant Manager role
     * could check a batch and then approve their own check. Whether that
     * should be barred is a policy question the owner's brief does not answer
     * ("all the machines will go to quality queue... then go to next level"
     * says nothing about who stands at the next level), so the behaviour is
     * deliberately left as it was rather than tightened on a guess. Raise it
     * with the factory before adding a third comparison here.
     */
    'approvals' => [
        'allow_same_user' => (bool) env('PROD_APPROVALS_ALLOW_SAME_USER', false),

        /*
         * THE QUALITY GATE, between the supervisor's completion and the plant
         * manager's approval (owner, 30-Jul): "all the machines will go to
         * quality queue, and quality will do the check... so the total
         * production will reduce if rejection, otherwise same, then go to
         * next level."
         *
         * ON (the default): a completed batch sits in the quality queue and
         * pmApprove() refuses it until a quality check is recorded. OFF: the
         * chain is exactly what it was before this stage existed —
         * completion → PM → accountant → Tally — and nothing about a batch's
         * figures changes. The off path is pinned by a test, because "we can
         * turn it off" is worth nothing if nobody has checked that turning it
         * off restores the previous behaviour rather than a third one.
         *
         * It exists as a switch rather than a hard-wired stage for one
         * reason: the gate is fail-CLOSED. If the quality desk is unstaffed
         * for a shift, every batch that shift stops before the PM and
         * production cannot reach the books. That is the correct default —
         * an unchecked batch should not post — but the factory must be able
         * to stand the gate down deliberately and visibly (in .env, where
         * anyone can see it) rather than by someone quietly approving around
         * it.
         */
        'quality_stage_enabled' => (bool) env('PROD_QUALITY_STAGE_ENABLED', true),
    ],

    /*
     * WHERE QUALITY-REJECTED BOTTLES GO. The owner, asked whether rejected
     * bottles are ever reworked: "no — go to the rejected scrap only."
     *
     * So a quality rejection moves stock twice, in one transaction: the
     * rejected pieces are ISSUED out of finished goods (they are not sellable
     * product and must stop counting as it), and their mass — piece count ×
     * the run's frozen unit weight — is RECEIVED as scrap. Mass in equals
     * mass out; the ERP never invents stock at this gate.
     *
     * THE SECOND HALF NEEDS A SCRAP ITEM, AND THIS ERP HAS NONE YET. There is
     * no scrap-item master, no colour → scrap-item mapping, and nothing that
     * resolves "amber scrap" or "clear scrap" (the item resolver handles
     * masterbatch only). The factory's Tally books DO already carry "Pet
     * Scrap" as a produced line on their daily Stock Journals, so the item
     * exists in THEIR world — it has simply never been mirrored here.
     *
     * Name it here (by SKU, else by exact item name) and the scrap receipt
     * happens. Leave it null — the default, because guessing which item is
     * "the scrap one" would silently book real weight against the wrong
     * master and the mistake would surface as a Tally rejection days later —
     * and the rejection is still recorded in full, the finished-goods issue
     * still happens, and the skipped receipt is written onto the entry where
     * the approval screen shows it. Under-recording a movement is
     * recoverable; mis-recording one against a guessed item is not.
     */
    'scrap' => [
        'rejected_item_sku' => env('PROD_SCRAP_ITEM_SKU'),
    ],

    /*
     * Stock behaviour on the COMPLETION path — and nowhere else.
     *
     * THE INCIDENT (owner's screenshot, 30-Jul). A real shift's completion
     * was refused with:
     *
     *   "Could not complete batch — Insufficient stock for item #592 at
     *    warehouse #10: available 0.0000, requested 118.998."
     *
     * The day bin held zero RECORDED stock because no opening stock had been
     * entered for it yet. The shift genuinely consumed that resin whether or
     * not the computer knew any had arrived, so the refusal did not prevent
     * anything — it only prevented the truth being written down, at 6am, by
     * the one person who was standing next to the machine.
     *
     * This is the same philosophy pinned all over this codebase: THE BATCH
     * CAN STILL RUN. The readiness gate warns and lets the batch start
     * (see 'readiness' below); the cavity rule warns and lets the mould run;
     * the material-shortage prompt at Start records the answer instead of
     * refusing it; an over-100% efficiency shouts and still approves. A
     * paperwork gap must never become lost production. Tally itself permits
     * negative stock for exactly this reason.
     *
     * So with this ON (the default) a completion whose consumption exceeds
     * the recorded balance ISSUES ANYWAY, drives the balance negative, and
     * records the shortfall on the entry — surfaced as metrics
     * .stock_shortfalls so approval can flag it loudly. It is a flag, never
     * a gate: the ACCOUNTANT fixes the stock (a missed receipt, an opening
     * balance never entered), because the stock record is what was wrong,
     * not the supervisor's account of the shift.
     *
     * Set PROD_ALLOW_NEGATIVE_ON_COMPLETION=false to restore the hard block
     * for a factory that would rather stop the completion than carry a
     * negative bin. The message it refuses with is now readable (see
     * InsufficientStockException), but it is still a refusal — choose it
     * deliberately.
     *
     * Scope is exactly the completion path (completeBatch, and therefore
     * handover). Work orders, rework, subcontract, deliveries and
     * maintenance issues keep their hard block whatever this says: they are
     * planned movements against a known store, not a supervisor writing
     * down what a running machine already ate.
     */
    'stock' => [
        'allow_negative_on_completion' => (bool) env('PROD_ALLOW_NEGATIVE_ON_COMPLETION', true),
    ],

    /*
     * Machine capability by cavity count.
     *
     * The factory's rule (30-Jul): a mould running `cavity_threshold` cavities
     * or more is only mounted on the machines listed here; everything below
     * that runs on any machine. 12 of the master's 103 rows are affected
     * (8 at 6 cavities, 4 at 7).
     *
     * A RULE, not a mapping table. 90 standards × 10 machines of stored rows
     * would need regenerating on every re-import and would silently keep
     * whatever the last one decided; the cavity count already lives on the
     * standard, so the rule reads it directly.
     *
     * Machines are listed by ID, not name — a name stops matching the moment
     * someone renames "Machine 10" in the work-centre master, and the
     * restriction would vanish with nothing to show it had ever applied.
     *
     * ADVISORY, deliberately. Exceeding it warns loudly on Start Batch and is
     * recorded against the batch, but never refuses the start: the cavity
     * figure comes from a sheet with known ambiguities, and one wrong number
     * would otherwise make a real product unrunnable mid-shift. Set
     * PROD_CAVITY_RULE_ENFORCED=true once the rule has been watched against
     * real shifts and the factory wants it to bite.
     */
    'machine_capability' => [
        'cavity_threshold' => (int) env('PROD_CAVITY_THRESHOLD', 6),

        // Comma-separated work-centre IDs, e.g. "10" or "9,10".
        'high_cavity_work_center_ids' => array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', (string) env('PROD_HIGH_CAVITY_WORK_CENTERS', '10')))),
            fn (int $id) => $id > 0,
        )),

        'enforced' => (bool) env('PROD_CAVITY_RULE_ENFORCED', false),
    ],

    // Phase 6 — Lot/Barcode traceability & shift continuity. The centralized
    // GRN → bag labels → bin-bay workflow is now the factory's live path, so
    // it ships on. A deployment can still fail closed by explicitly setting
    // PROD_TRACEABILITY=false; schema changes remain additive either way.
    'traceability_enabled' => (bool) env('PROD_TRACEABILITY', true),

    'traceability' => [
        // Vincent Q3 (FIFO mandatory vs preference) absorbed by config:
        // true (default) = scanning a newer bag while an older one is still
        // open in the store requires the production.override-fifo permission
        // plus an explicit override flag (and records who); false = FIFO is
        // a suggestion only — the pick list still sorts oldest-first but any
        // bag loads freely.
        'fifo_enforced' => (bool) env('PROD_TRACE_FIFO_ENFORCED', true),
    ],

    // How packing suggestions and "vs standard" notes round a partial
    // container: 'ceil' (default — a part-filled pouch/tray still needs
    // packing, matches current behaviour), 'round', or 'floor'. Used by ALL
    // packing suggestions, backend metrics (expected_pouches) and frontend
    // alike. NOT applied to the WB2 expected-boxes formula, which stays
    // half-up ROUND to keep matching the workbook — see
    // ShiftProductionEntryService::productionMetrics().
    'packing_rounding' => env('PROD_PACKING_ROUNDING', 'ceil'),

    /*
     * EST BOX rounding — the factory's estimated-box target.
     *
     * Separate from packing_rounding on purpose. packing_rounding governs
     * how many containers you need to PACK a quantity (a part-filled box
     * still needs packing, so ceil). This governs the TARGET a shift is
     * measured against, which the factory workbook rounds to nearest.
     *
     * Do not set this to 'floor' unless the factory rules that only
     * completely filled boxes count toward the target — flooring lowers
     * every target by up to a box and inflates efficiency accordingly.
     */
    'est_box_rounding' => env('PROD_EST_BOX_ROUNDING', 'round'),

    /*
     * Which rejection figure feeds total rejection when both exist.
     *
     * 'qc' (default, matches the workbook) — QC weighed it on a scale.
     * 'production' — the piece count multiplied by the nominal weight.
     *
     * NEEDS VINCENT. The workbook also excludes its separate QC-lumps
     * column from this sum; that is mirrored rather than "fixed", because
     * it may be deliberate.
     */
    'rejection_precedence' => env('PROD_REJECTION_PRECEDENCE', 'qc'),

    /*
     * How the masterbatch percentage relates to polymer weight.
     *
     * 'included'   — MB is part of the finished polymer weight. Expected
     *                resin = polymer weight − MB, and showing both as
     *                additive would DOUBLE-COUNT the MB.
     * 'additional' — MB is added on top of the resin weight.
     * 'unconfirmed' (default) — the honest state today. Expected resin and
     *                MB are shown with a warning that their basis is
     *                unconfirmed, rather than presenting a total that may
     *                be overstated by the MB.
     *
     * NEEDS VINCENT — see PHASE0 audit and the factory question sheet.
     */
    'masterbatch_basis' => env('PROD_MASTERBATCH_BASIS', 'unconfirmed'),

    /*
     * The production-readiness gate (ProductReadinessService). Master switch
     * first: with `enforced` false the gate still evaluates and still shows
     * every finding, it just never refuses a Start Batch — the way to watch
     * the gate against real shifts before letting it bite.
     *
     * Per-check severity: 'block' | 'warn' | 'off'.
     *
     * The defaults below are DELIBERATELY NOT all-blocking, and the reason is
     * master-data coverage, not a judgement about which fields matter:
     *
     *   - colour defaults to 'warn' because it drives a suggestion (which
     *     masterbatch) and the scrap-item split, neither of which stops a
     *     shift from being recorded truthfully.
     *   - tally_item / tally_godown default to 'block' because they are
     *     voucher-fatal: Tally rejects the whole voucher, and the failure
     *     surfaces hours after the work is done.
     *
     * Raise each to 'block' as the corresponding masters get loaded. That
     * progression is the intended operating procedure, not a workaround.
     *
     * `enforced` DEFAULTS TO FALSE — watch-only — and that default is a
     * safety property of the deployment, not a preference. Roughly 364 of
     * ~410 finished-good items still lack cycle time and cavities. A
     * deployment that reached a server whose .env had not been edited yet
     * would, with a `true` default, refuse every batch for those products
     * on the next shift. Watch-only cannot cause that: the gate evaluates,
     * displays every finding, and refuses nothing.
     *
     * Flip to true (PROD_READINESS_ENFORCED=true) once master coverage is
     * good enough that blocking is what the factory wants. That is a
     * deliberate decision made against real data, which is exactly why it
     * should not be arrived at by forgetting to set an .env line.
     */
    'readiness' => [
        'enforced' => (bool) env('PROD_READINESS_ENFORCED', false),

        'checks' => [
            'item_active' => env('PROD_READINESS_ITEM_ACTIVE', 'block'),
            'uom' => env('PROD_READINESS_UOM', 'block'),
            'weight' => env('PROD_READINESS_WEIGHT', 'block'),
            'cycle_time' => env('PROD_READINESS_CYCLE_TIME', 'block'),
            'cavities' => env('PROD_READINESS_CAVITIES', 'block'),
            'packing' => env('PROD_READINESS_PACKING', 'block'),
            'colour' => env('PROD_READINESS_COLOUR', 'warn'),
            'tally_item' => env('PROD_READINESS_TALLY_ITEM', 'block'),
            'tally_godown' => env('PROD_READINESS_TALLY_GODOWN', 'block'),
            'machine_active' => env('PROD_READINESS_MACHINE_ACTIVE', 'block'),
        ],
    ],
];
