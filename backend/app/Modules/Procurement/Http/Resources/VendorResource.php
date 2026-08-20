<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Vendor $vendor */
        $vendor = $this->resource;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'gstin' => $this->gstin,
            'state_code' => $this->state_code,
            // The vendor's ledger name in Tally (Phase 6) — null until Accounts sets it.
            'tally_ledger_name' => $this->tally_ledger_name,
            'is_active' => $this->is_active,
            // Archived-by-soft-delete, distinct from is_active. Both exist on
            // this table and only the screen can tell the operator which one
            // took the vendor out of service.
            'archived_at' => $vendor->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            /*
             * WHAT MAY BE DONE TO THIS RECORD, decided by the server
             * (DEC-20260817-002). `delete` is null on index — undetermined,
             * ask show() — because resolving it costs a COUNT per dependency
             * per row. show() and the lifecycle actions stamp the
             * authoritative block via withAbilities().
             */
            'can' => $vendor->can ?? app(VendorService::class)
                ->abilities($vendor, resolveDelete: false, user: $request->user()),
        ];
    }
}
