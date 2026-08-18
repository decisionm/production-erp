<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One handover, as a reader sees it.
 *
 * `state_label` is the sentence the screens show, and it exists so that no
 * surface has to invent its own wording for the middle state. "Issued to
 * production" is not "consumed", and every reader must be able to tell them
 * apart at a glance.
 *
 * NO MONEY (FC-06): a store issue carries no rate, no amount and no vendor.
 * What a material cost and who supplied it are Owner/Accounts, and they are
 * absent from this shape rather than nulled — an omitted field cannot leak.
 */
class StoreIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issue_number' => $this->issue_number,
            'material_request_id' => $this->material_request_id,
            'status' => $this->status->value,
            'state_label' => match ($this->status) {
                StoreIssueStatus::Issued => 'Issued to production (not consumed)',
                StoreIssueStatus::PartiallyReturned => 'Partly returned — the rest is with production',
                StoreIssueStatus::Returned => 'Returned to store',
                StoreIssueStatus::Completed => 'Completed',
                StoreIssueStatus::Cancelled => 'Cancelled',
            },
            'is_open' => $this->status->isOpen(),
            'issued_by' => $this->issued_by,
            'issued_by_name' => $this->whenLoaded('issuedBy', fn () => $this->issuedBy?->name),
            'received_by' => $this->received_by,
            'received_by_name' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'lines' => StoreIssueLineResource::collection($this->whenLoaded('lines')),
            'bag_scans' => StoreIssueBagScanResource::collection($this->whenLoaded('bagScans')),
        ];
    }
}
