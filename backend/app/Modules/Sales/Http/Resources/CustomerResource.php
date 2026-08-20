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
