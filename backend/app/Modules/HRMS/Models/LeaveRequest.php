<?php

namespace App\Modules\HRMS\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'leave_type_id', 'start_date', 'end_date', 'days',
    'reason', 'status', 'approved_by', 'decided_at',
])]
class LeaveRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => LeaveRequestStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
