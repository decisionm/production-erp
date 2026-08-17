<?php

namespace App\Modules\HRMS\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Enums\EmployeeStatus;
use App\Support\Configuration\Concerns\RecordsConfigurationAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_code', 'name', 'email', 'phone', 'date_of_birth', 'date_of_joining',
    'designation', 'department', 'status', 'manager_id', 'user_id',
])]
class Employee extends Model
{
    use RecordsConfigurationAudit;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'date_of_birth' => 'date',
            'date_of_joining' => 'date',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
