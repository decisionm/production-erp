<?php

namespace App\Modules\Quality\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Quality\Models\Enums\NonConformanceStatus;
use App\Modules\Quality\Models\IncomingInspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NonConformanceReportService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return NonConformanceReport::query()
            ->with(['item', 'incomingInspection', 'raisedBy'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function openCount(): int
    {
        return NonConformanceReport::query()
            ->where('status', NonConformanceStatus::Open)
            ->count();
    }

    /**
     * @param  array{incoming_inspection_id?: int, item_id?: int, description: string, severity: string, quantity_affected?: string, raised_date: string}  $data
     */
    public function create(array $data, ?int $raisedBy): NonConformanceReport
    {
        // If raised against a specific inspection, the item is derived from
        // it server-side rather than trusting a possibly-inconsistent
        // client-supplied item_id — same reasoning as GRN/Invoice deriving
        // their denormalized fields from the parent document.
        $itemId = $data['item_id'] ?? null;
        if (! empty($data['incoming_inspection_id'])) {
            $itemId = IncomingInspection::findOrFail($data['incoming_inspection_id'])->item_id;
        }

        return NonConformanceReport::create([
            'incoming_inspection_id' => $data['incoming_inspection_id'] ?? null,
            'item_id' => $itemId,
            'description' => $data['description'],
            'severity' => $data['severity'],
            'status' => NonConformanceStatus::Open,
            'quantity_affected' => $data['quantity_affected'] ?? null,
            'raised_by' => $raisedBy,
            'raised_date' => $data['raised_date'],
        ])->load(['item', 'incomingInspection', 'raisedBy']);
    }

    public function close(NonConformanceReport $report, string $resolution): NonConformanceReport
    {
        if ($report->status !== NonConformanceStatus::Open) {
            throw InvalidStatusTransitionException::make(
                'non-conformance report',
                $report->status->value,
                NonConformanceStatus::Closed->value,
            );
        }

        $report->update([
            'status' => NonConformanceStatus::Closed,
            'resolution' => $resolution,
            'closed_date' => now()->toDateString(),
        ]);

        return $report;
    }
}
