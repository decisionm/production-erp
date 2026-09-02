<?php

namespace App\Modules\HRMS\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Enums\AttendanceImportIssue;
use App\Modules\HRMS\Models\Enums\AttendanceImportResolution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attendance_import_id', 'employee_id', 'employee_code', 'employee_name', 'date', 'raw_status',
    'first_in', 'last_out', 'ot_minutes', 'late_minutes', 'early_minutes', 'worked_minutes',
    'issue', 'resolution', 'resolved_check_in', 'resolved_check_out', 'resolved_by', 'resolved_at',
    'notes', 'applied_at',
])]
class AttendanceImportLine extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'issue' => AttendanceImportIssue::class,
            'resolution' => AttendanceImportResolution::class,
            'ot_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'resolved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AttendanceImport::class, 'attendance_import_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** An issue line nobody has answered yet. */
    public function isOpen(): bool
    {
        return $this->issue !== null && $this->resolution === null;
    }
}
