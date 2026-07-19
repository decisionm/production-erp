<?php

namespace App\Modules\Quality\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Quality\Models\Enums\CapaStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'non_conformance_report_id', 'title', 'problem_statement', 'root_cause', 'corrective_action',
    'preventive_action', 'owner', 'due_date', 'status', 'verified_effective', 'closed_date', 'created_by',
])]
class Capa extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CapaStatus::class,
            'due_date' => 'date',
            'verified_effective' => 'boolean',
            'closed_date' => 'date',
        ];
    }

    public function nonConformanceReport(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class);
    }

    public function ownerEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
