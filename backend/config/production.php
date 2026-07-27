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
];
