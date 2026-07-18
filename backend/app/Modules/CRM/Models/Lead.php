<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Models\Enums\LeadStatus;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'phone', 'company', 'source', 'status', 'notes', 'assigned_to', 'converted_customer_id'])]
class Lead extends Model
{
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
        ];
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }
}
