<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'gstin' => $this->gstin,
            'state_code' => $this->state_code,
            /*
             * WHICH TALLY LEDGER THIS CUSTOMER IS — read-only, both null until
             * `sales:import-customers-from-ledgers` links the row. Deliberately
             * NOT in Customer's #[Fillable], so no form and no request can
             * write them: a posting identity is imported from Tally, never
             * typed. The screen shows "posts as {tally_ledger_name}" (or that
             * there is no ledger) and offers no edit.
             *
             * A CUSTOMER ledger, which is why FC-06 does not reach it: no
             * supplier identity and no rate of any kind is exposed here.
             */
            'tally_ledger_guid' => $this->tally_ledger_guid,
            'tally_ledger_name' => $this->tally_ledger_name,
            'is_active' => $this->is_active,
            // Archived-by-soft-delete, distinct from is_active — both exist
            // on this table and only the screen can say which one applied.
            'archived_at' => $customer->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            /*
             * WHAT MAY BE DONE TO THIS RECORD (DEC-20260817-002). `delete` is
             * null on index — undetermined, ask show() — because resolving it
             * costs a COUNT per dependency per row, and a customer carries
             * five. show() and the lifecycle actions stamp the authoritative
             * block via withAbilities().
             */
            'can' => $customer->can ?? app(CustomerService::class)
                ->abilities($customer, resolveDelete: false, user: $request->user()),
        ];
    }

    /**
     * The customer as a delivery, an invoice's order stub or a trace names
     * it — {id, code, name}, identity only. One shape, defined once.
     *
     * @return array{id: int, code: ?string, name: ?string}
     */
    public static function stub(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
        ];
    }
}
