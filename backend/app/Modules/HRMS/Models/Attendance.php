<?php

namespace App\Modules\HRMS\Models;

use App\Modules\HRMS\Models\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'date', 'status', 'check_in', 'check_out', 'notes'])]
class Attendance extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'date' => 'date:Y-m-d',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
