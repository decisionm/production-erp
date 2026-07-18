<?php

namespace App\Modules\CRM\Http\Resources;

use App\Modules\Sales\Http\Resources\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'source' => $this->source,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo?->name),
            'converted_customer_id' => $this->converted_customer_id,
            // convertedCustomer is a genuinely nullable relation (unlike the
            // required FKs other resources wrap directly in Resource::make),
            // so only build the nested resource when it actually resolved
            // to a model — otherwise the key is simply omitted.
            'converted_customer' => $this->when(
                $this->relationLoaded('convertedCustomer') && $this->convertedCustomer,
                fn () => CustomerResource::make($this->convertedCustomer),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
