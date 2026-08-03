<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\HRMS\Http\Resources\EmployeeResource;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Services\BagCostAllocationService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftProductionEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Priced ONCE and shared by the two keys that need it below.
        // materialCost() runs a stock_movements query per entry, so letting
        // `material_cost` and `batch_cost` each compute their own would
        // double the query count of the busiest read in the module — a
        // 20-row approval page is the normal case, not the worst one.
        $materialCost = app(ShiftProductionEntryService::class)->materialCost($this->resource);

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
            // NET of any quality rejection — the quality gate rewrites this
            // column so that every consumer (this screen, the reports, the
            // Tally voucher's produced line) carries the same reduced figure
            // without having to know the gate exists. The supervisor's
            // original count is preserved beside it.
            'quantity_produced' => $this->quantity_produced,
            'quantity_produced_kg' => $this->quantity_produced_kg,
            'gross_quantity_produced' => $this->gross_quantity_produced,
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
            // WHICH COLOUR THIS RUN ACTUALLY MADE — read back out of the
            // snapshot startBatch froze it into, so a later item-master edit
            // can never restate it.
            //
            // Surfaced because colour picks the masterbatch, and until now it
            // was write-only: the supervisor's answer went into
            // config_snapshot at Start and no client could ever read it back.
            // The completion drawer needs it to ask the preview for the
            // masterbatch of the colour THIS run is recorded as making,
            // rather than falling back to the item master's colour — which
            // for most bottle items is blank, and for a mislabelled one is
            // simply a different colour's material.
            //
            // Null is a real answer ("nobody stated a colour"), never "".
            'colour' => $this->config_snapshot['colour'] ?? null,
            'cycle_time_source' => $this->cycle_time_source,
            'cavities_source' => $this->cavities_source,
            'override_reason' => $this->override_reason,
            'calculation_version' => $this->calculation_version,
            'material_consumptions' => ShiftMaterialConsumptionResource::collection($this->whenLoaded('materialConsumptions')),
            'scraps' => ShiftScrapResource::collection($this->whenLoaded('scraps')),
            // Downtime logged against this batch — planned at Start plus
            // the completion-time lines whose minutes net out of running
            // hours in metrics.downtime_minutes_total below.
            'downtime_events' => ProductionDowntimeEventResource::collection($this->whenLoaded('downtimeEvents')),
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
            // The quality gate: the counts, the basis on which rejected
            // pieces became kilograms, and whether the scrap receipt
            // happened. Always present (all nulls before the check) so a
            // client can say "awaiting quality" without telling a missing
            // key apart from a null one. `checked_by` is a relation, so it
            // rides the same whenLoaded rule as the other signatures.
            'quality' => [
                ...app(ShiftProductionEntryService::class)->qualityCheck($this->resource),
                'checked_by' => UserResource::make($this->whenLoaded('qualityCheckedBy')),
            ],
            // WHY THIS BATCH CAME BACK, and what has been changed on it since
            // it was completed. Quality's return reason is the only
            // instruction the supervisor gets, so it has to reach the screen
            // that shows them the batch — and the PM and accountant sign off
            // on figures that were amended, which they are entitled to see.
            // Always present (empty lists before anything happens), same rule
            // as `quality` above.
            'correction' => app(ShiftProductionEntryService::class)->correctionHistory($this->resource),
            // What the consumed material actually cost — each line at the
            // unit cost its own issue movement recorded, plus a total that
            // is null (never a partial figure) when any line is unpriced.
            // Null until the batch completes, like variance/metrics above.
            'material_cost' => $materialCost,
            // WHAT THIS BATCH COST, from the bags its resin actually came
            // out of — resin priced off the machine's scanned load layers at
            // each bag's own purchase rate, everything else priced exactly
            // as material_cost prices it, plus the per-accepted-piece figure.
            //
            // Always present (nulls plus a `reason` in words before the
            // batch completes), the same rule `quality` and `correction`
            // follow above — a client must be able to say "not costed yet"
            // without telling a missing key apart from a null one.
            //
            // THE DETAIL IS FINANCE'S. Totals, cost-per-piece and the
            // accounting-allocation sentence (`basis`) are for everyone on
            // the floor; the per-material rates behind them are passed only
            // for finance.view/manage, and the keys are ABSENT rather than
            // null for anyone else, so no rate is ever one devtools panel
            // away from a production login. The permission gate is the
            // module-coarse one this codebase already uses (there is no
            // per-field precedent, and inventing one here would be inventing
            // a permission the seeder would strip).
            //
            // THERE ARE NO BAG BARCODES OR SUPPLIER LOTS IN IT AT ANY LEVEL
            // any more. The owner's correction (2-Aug) ended the bag-to-batch
            // claim — see BagCostAllocationService.
            'batch_cost' => app(BagCostAllocationService::class)->summary(
                $this->resource,
                withDetail: (bool) $request->user()?->canAny(['finance.view', 'finance.manage']),
                materialCost: $materialCost,
            ),
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
            // The cancellation audit. Emitted unconditionally rather than only
            // when set: a screen asking "was this withdrawn?" needs a null it
            // can trust, not a missing key it has to guess about.
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => UserResource::make($this->whenLoaded('cancelledBy')),
            'cancellation_reason' => $this->cancellation_reason,
            'operator' => EmployeeResource::make($this->whenLoaded('operator')),
            // Free text — the helper isn't necessarily an Employee master.
            'helper_name' => $this->helper_name,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
