<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use Illuminate\Database\Eloquent\Collection;

class LeadActivityService
{
    /**
     * Not paginated: a lead's follow-up history is a short, bounded
     * timeline meant to be read in full on its detail view, not a list
     * screen.
     */
    public function listForLead(Lead $lead): Collection
    {
        return $lead->activities()->with('createdBy')->get();
    }

    /**
     * @param  array{type: string, notes: string, activity_date?: string, next_follow_up_date?: string}  $data
     */
    public function create(Lead $lead, array $data, ?int $createdBy): LeadActivity
    {
        return $lead->activities()->create([
            'type' => $data['type'],
            'notes' => $data['notes'],
            'activity_date' => $data['activity_date'] ?? now(),
            'next_follow_up_date' => $data['next_follow_up_date'] ?? null,
            'created_by' => $createdBy,
        ])->load('createdBy');
    }
}
