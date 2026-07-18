<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Enums\OpportunityStage;
use App\Modules\CRM\Models\Opportunity;
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
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Opportunity::query()
            ->with(['customer', 'lead', 'assignedTo'])
            ->orderByDesc('id')
            ->paginate($perPage);
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
