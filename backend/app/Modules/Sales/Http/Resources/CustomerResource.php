<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'created_at' => $this->created_at?->toIso8601String(),
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
