<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Models\Enums\OpportunityStage;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'customer_id', 'lead_id', 'stage', 'estimated_value',
    'probability', 'expected_close_date', 'notes', 'assigned_to',
])]
class Opportunity extends Model
{
    protected function casts(): array
    {
        return [
            'stage' => OpportunityStage::class,
            'estimated_value' => 'decimal:4',
            'probability' => 'decimal:2',
            'expected_close_date' => 'date',
        ];
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
