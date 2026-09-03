<?php

namespace App\Modules\HRMS\Models;

use App\Models\User;
use App\Modules\HRMS\Models\Enums\AttendanceImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source', 'period_from', 'period_to', 'file_name', 'uploaded_by', 'status',
    'employee_count', 'day_count', 'issue_count', 'applied_at',
])]
class AttendanceImport extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AttendanceImportStatus::class,
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'employee_count' => 'integer',
            'day_count' => 'integer',
            'issue_count' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AttendanceImportLine::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
