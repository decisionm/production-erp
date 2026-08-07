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

];
