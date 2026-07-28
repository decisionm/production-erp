<?php

namespace App\Modules\Production\Http\Requests;

class ReconciliationReportRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return $this->rangeRules() + [
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ];
    }
}
