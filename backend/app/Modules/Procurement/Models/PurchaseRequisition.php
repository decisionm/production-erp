<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'requested_by', 'needed_by_date', 'notes'])]
class PurchaseRequisition extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PurchaseRequisitionStatus::class,
            'needed_by_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
