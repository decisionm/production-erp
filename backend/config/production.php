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
