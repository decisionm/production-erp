<?php

namespace App\Modules\TallySync\Models\Enums;

enum TallySyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';

    /**
     * Written off by a human: this voucher must NEVER reach Tally. A terminal
     * state for demo-era or otherwise-dead vouchers — the row stays as
     * history (nothing on this queue is ever deleted), but pending() cannot
     * offer it to the agent and retry() refuses to resurrect it.
     */
    case Dismissed = 'dismissed';
}
