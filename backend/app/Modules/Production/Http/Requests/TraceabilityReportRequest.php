<?php

namespace App\Modules\Production\Http\Requests;

class TraceabilityReportRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return $this->rangeRules() + [
            'lot_id' => ['nullable', 'integer', 'exists:material_lots,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
        ];
    }
}
