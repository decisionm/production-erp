<?php

namespace App\Modules\TallySync\Models;

use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'syncable_type', 'syncable_id', 'tally_voucher_type', 'payload',
    'status', 'attempts', 'error_message', 'synced_at',
])]
class TallySyncEntry extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => TallySyncStatus::class,
            'synced_at' => 'datetime',
        ];
    }

    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }
}
