<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Models\Enums\LeadActivityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'type', 'notes', 'activity_date', 'next_follow_up_date', 'created_by'])]
class LeadActivity extends Model
{
    protected function casts(): array
    {
        return [
            'type' => LeadActivityType::class,
            'activity_date' => 'datetime',
            'next_follow_up_date' => 'date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
