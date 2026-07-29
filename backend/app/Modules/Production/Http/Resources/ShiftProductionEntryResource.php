<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\HRMS\Http\Resources\EmployeeResource;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftProductionEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'work_center' => WorkCenterResource::make($this->whenLoaded('workCenter')),
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'production_date' => $this->production_date?->toDateString(),
            'batch_status' => $this->batch_status->value,
            'batch_number' => $this->batch_number,
            // Phase 6 shift segments — surfaced only while the traceability
            // flag is on, so a flag-off deployment's API shape is untouched.
            'parent_entry_id' => $this->when(
                (bool) config('production.traceability_enabled'),
                $this->parent_entry_id,
            ),
            'quantity_produced' => $this->quantity_produced,
            'quantity_produced_kg' => $this->quantity_produced_kg,
            'quantity_scrap' => $this->quantity_scrap,
            'quantity_rejection_kg' => $this->quantity_rejection_kg,
            'scrap_reason' => ScrapReasonResource::make($this->whenLoaded('scrapReason')),
            'nos_per_tray' => $this->nos_per_tray,
            'no_of_trays' => $this->no_of_trays,
            'nos_per_box' => $this->nos_per_box,
            'no_of_box' => $this->no_of_box,
            // Wave A packaging — pouch count and left-over loose pieces.
            'no_of_pouches' => $this->no_of_pouches,
            'nos_per_pouch' => $this->nos_per_pouch,
            'loose_pieces' => $this->loose_pieces,
            // Configurable-production provenance: which standard and
            // packaging drove this run, where the effective values came
            // from, and which formula set produced its figures. Approval
            // cannot show "default vs effective" without these.
            'production_standard_id' => $this->production_standard_id,
            'production_configuration_id' => $this->production_configuration_id,
            'packaging_mode' => $this->packaging_mode,
            'cycle_time_source' => $this->cycle_time_source,
            'cavities_source' => $this->cavities_source,
            'override_reason' => $this->override_reason,
            'calculation_version' => $this->calculation_version,
            'material_consumptions' => ShiftMaterialConsumptionResource::collection($this->whenLoaded('materialConsumptions')),
            'scraps' => ShiftScrapResource::collection($this->whenLoaded('scraps')),
            // Expected-output engine inputs. standard_* are Start Batch
            // snapshots from the item master (never editable after start);
            // the actuals are shop-floor entries.
            'standard_cycle_time' => $this->standard_cycle_time,
            'actual_cycle_time' => $this->actual_cycle_time,
            'standard_cavities' => $this->standard_cavities,
            'active_cavities' => $this->active_cavities,
            'running_hours' => $this->running_hours,
            'qc_rejection_kg' => $this->qc_rejection_kg,
            // Computed, never stored — shaping only, the math lives in the
            // service (module pattern). Null until the batch completes.
            // `variance` answers the norm-based material question; `metrics`
            // answers the cycle-time/efficiency + reconciliation question —
            // two different blocks by design.
            'variance' => app(ShiftProductionEntryService::class)->consumptionVariance($this->resource),
            'metrics' => app(ShiftProductionEntryService::class)->productionMetrics($this->resource),
            'sync_error' => $this->when(
                $this->status === ShiftProductionEntryStatus::Failed && $this->relationLoaded('tallySyncEntries'),
                fn () => $this->tallySyncEntries->first()?->error_message,
            ),
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'plant_manager_signed_by' => UserResource::make($this->whenLoaded('plantManagerSignedBy')),
            'plant_manager_signed_at' => $this->plant_manager_signed_at?->toIso8601String(),
            'accountant_signed_by' => UserResource::make($this->whenLoaded('accountantSignedBy')),
            'accountant_signed_at' => $this->accountant_signed_at?->toIso8601String(),
            'approved_by' => UserResource::make($this->whenLoaded('approvedBy')),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'operator' => EmployeeResource::make($this->whenLoaded('operator')),
            // Free text — the helper isn't necessarily an Employee master.
            'helper_name' => $this->helper_name,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
