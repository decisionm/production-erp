<?php

namespace App\Modules\CRM\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\CRM\Http\Requests\ListLeadsRequest;
use App\Modules\CRM\Models\Enums\LeadStatus;
use App\Modules\CRM\Models\Lead;
use App\Modules\Sales\Services\CustomerService;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(private readonly CustomerService $customers) {}

    /** Newest first when no sort is asked for — what this list always was. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = Lead::query()->with(['assignedTo', 'convertedCustomer', 'latestActivity']);
        ListSort::apply($query, $sort, ListLeadsRequest::SORTABLE, '-id');

        return $query->paginate($perPage);
    }

    /** Leads still being worked — anything not yet converted or disqualified. */
    public function openCount(): int
    {
        return Lead::query()
            ->whereNotIn('status', [LeadStatus::Disqualified, LeadStatus::Converted])
            ->count();
    }

    public function create(array $data): Lead
    {
        return Lead::create([
            'status' => LeadStatus::New,
            ...$data,
        ]);
    }

    public function update(Lead $lead, array $data): Lead
    {
        $lead->update($data);

        return $lead;
    }

    /**
     * Converting a lead creates a real Sales Customer via Sales' own
     * Service — never touches the customers table directly — and marks
     * the lead Converted. Does not auto-create an Opportunity: that's a
     * deliberate, separate step so conversion doesn't do too much at once.
     */
    public function convert(Lead $lead, string $customerCode): Lead
    {
        if ($lead->status === LeadStatus::Converted) {
            throw InvalidStatusTransitionException::make('lead', $lead->status->value, LeadStatus::Converted->value);
        }

        return DB::transaction(function () use ($lead, $customerCode) {
            $customer = $this->customers->create([
                'code' => $customerCode,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
            ]);

            $lead->update([
                'status' => LeadStatus::Converted,
                'converted_customer_id' => $customer->id,
            ]);

            return $lead->load(['assignedTo', 'convertedCustomer']);
        });
    }
}
