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

        // Efficiency % bands: >= ok -> ok, >= watch -> watch, else
        // investigate.
        'efficiency_ok' => (float) env('PROD_TOL_EFFICIENCY_OK', 95),
        'efficiency_watch' => (float) env('PROD_TOL_EFFICIENCY_WATCH', 85),

        // Hard gates (null = disabled). When set, accountant approval is
        // refused while the figure exceeds the threshold.
        'variance_blocking_pct' => env('PROD_TOL_VARIANCE_BLOCKING') !== null
            ? (float) env('PROD_TOL_VARIANCE_BLOCKING') : null,
        'unaccounted_blocking_kg' => env('PROD_TOL_UNACCOUNTED_BLOCKING') !== null
            ? (float) env('PROD_TOL_UNACCOUNTED_BLOCKING') : null,
    ],

    // Phase 6 — Lot/Barcode traceability & shift continuity. Master switch:
    // with this off (the default) the entire feature is invisible and inert —
    // every traceability route 404s and the SPA renders nothing new. Schema
    // stays applied either way (additive, harmless). Flip per deployment via
    // .env once the machine pilot starts (design doc "Rollout" §3).
    'traceability_enabled' => (bool) env('PROD_TRACEABILITY', false),

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
     *   - consumption_recipe defaults to 'warn' because no product carries a
     *     BOM yet. Blocking on it would refuse every batch on the floor.
     *   - colour defaults to 'warn' because it drives a suggestion (which
     *     masterbatch) and the scrap-item split, neither of which stops a
     *     shift from being recorded truthfully.
     *   - tally_item / tally_godown default to 'block' because they are
     *     voucher-fatal: Tally rejects the whole voucher, and the failure
     *     surfaces hours after the work is done.
     *
     * Raise each to 'block' as the corresponding masters get loaded. That
     * progression is the intended operating procedure, not a workaround.
     */
    'readiness' => [
        'enforced' => (bool) env('PROD_READINESS_ENFORCED', true),

        'checks' => [
            'item_active' => env('PROD_READINESS_ITEM_ACTIVE', 'block'),
            'uom' => env('PROD_READINESS_UOM', 'block'),
            'weight' => env('PROD_READINESS_WEIGHT', 'block'),
            'cycle_time' => env('PROD_READINESS_CYCLE_TIME', 'block'),
            'cavities' => env('PROD_READINESS_CAVITIES', 'block'),
            'packing' => env('PROD_READINESS_PACKING', 'block'),
            'consumption_recipe' => env('PROD_READINESS_RECIPE', 'warn'),
            'colour' => env('PROD_READINESS_COLOUR', 'warn'),
            'tally_item' => env('PROD_READINESS_TALLY_ITEM', 'block'),
            'tally_godown' => env('PROD_READINESS_TALLY_GODOWN', 'block'),
            'machine_active' => env('PROD_READINESS_MACHINE_ACTIVE', 'block'),
        ],
    ],
];
