<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Http\Requests\ListOpportunitiesRequest;
use App\Modules\CRM\Models\Enums\OpportunityStage;
use App\Modules\CRM\Models\Opportunity;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Unlike the document-workflow modules (PO, SO, Invoice, ...), an
 * opportunity's stage is not a one-way gate — sales reps move deals
 * backward and forward through a pipeline routinely (negotiation back to
 * proposal, etc.), so stage changes go through plain update(), not a
 * guarded transition method.
 */
class OpportunityService
{
    /** Newest first when no sort is asked for; an undated expected close sorts last either way. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = Opportunity::query()->with(['customer', 'lead', 'assignedTo']);
        ListSort::apply($query, $sort, ListOpportunitiesRequest::SORTABLE, '-id', ['expected_close_date']);

        return $query->paginate($perPage);
    }

    /** Deals still in the pipeline — any stage before won or lost. */
    public function openCount(): int
    {
        return Opportunity::query()
            ->whereNotIn('stage', [OpportunityStage::Won, OpportunityStage::Lost])
            ->count();
    }

    public function create(array $data): Opportunity
    {
        return Opportunity::create([
            'stage' => OpportunityStage::Prospecting,
            'estimated_value' => 0,
            'probability' => 0,
            ...$data,
        ])->load(['customer', 'lead', 'assignedTo']);
    }

    public function update(Opportunity $opportunity, array $data): Opportunity
    {
        $opportunity->update($data);

        return $opportunity->load(['customer', 'lead', 'assignedTo']);
    }
}
