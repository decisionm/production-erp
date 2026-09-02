<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorService;
use App\Modules\TallySync\Models\Ledger;
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
            /*
             * THE PROVENANCE OF WHAT CAME FROM TALLY. `tally_ledger_guid` is
             * the exact identity — present only on a vendor the import or the
             * Owner/Accounts review linked, absent on one typed in by hand —
             * and `tally_synced_at` is when the pull last CONFIRMED that
             * ledger's details, read from the mirror rather than from this
             * row's `updated_at`, which moves whenever anything here changes.
             *
             * A vendor with a guid and no stamp is honest too: it says the
             * link exists and no pull has refreshed the ledger since the
             * column was added.
             */
            'tally_source' => $vendor->tally_ledger_guid !== null ? [
                'source' => 'tally',
                'ledger_guid' => $vendor->tally_ledger_guid,
                // The MODEL, not ->value(): a plucked column bypasses the
                // cast and would come back as a raw driver string, which
                // formats differently on MySQL and sqlite.
                'synced_at' => Ledger::where('tally_guid', $vendor->tally_ledger_guid)
                    ->first(['tally_synced_at'])?->tally_synced_at?->toIso8601String(),
            ] : null,
            // DEC-20260902-026: one or more of five classifications, set by a
            // person — sorted values, never labels; the frontend maps those.
            'classifications' => $this->whenLoaded(
                'classifications',
                fn () => $this->classifications->map(fn ($row) => $row->classification->value)->sort()->values()->all(),
                [],
            ),
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
