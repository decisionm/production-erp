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
    |   'batch' (default) — one voucher per approved entry (SPE-{id}),
    |             the original behaviour, byte-for-byte.
    |   'shift'           — one aggregated Stock Journal per
    |             (production_date, shift): entries approved for the same
    |             shift merge into a single pending voucher
    |             (SJ-{Ymd}-S{shift_id}); entries approved after that
    |             voucher has already synced open a follow-up voucher
    |             (-2, -3, ...). Membership is tracked on
    |             shift_production_entries.tally_sync_entry_id so an entry
    |             appears in exactly one voucher.
    |
    */

    'voucher_granularity' => env('TALLY_VOUCHER_GRANULARITY', 'batch'),

];
