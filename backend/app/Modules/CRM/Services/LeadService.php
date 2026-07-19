<?php

namespace App\Modules\CRM\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\CRM\Models\Enums\LeadStatus;
use App\Modules\CRM\Models\Lead;
use App\Modules\Sales\Services\CustomerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(private readonly CustomerService $customers) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Lead::query()
            ->with(['assignedTo', 'convertedCustomer', 'latestActivity'])
            ->orderByDesc('id')
            ->paginate($perPage);
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
