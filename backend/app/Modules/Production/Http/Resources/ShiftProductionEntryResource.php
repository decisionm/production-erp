<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\HRMS\Http\Resources\EmployeeResource;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\BagCostAllocationService;
use App\Modules\Production\Services\CompletionDefaultsService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Services\TallySyncLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ShiftProductionEntryResource extends JsonResource
{
    /**
     * The page's TallyLinks, keyed by entry id, resolved ONCE for the whole
     * collection (see collection() below) — null on a resource made on its
     * own, which looks its single link up itself. Not a wire field.
     *
     * @var array<int, array<string, mixed>|null>|null
     */
    private ?array $pageTallyLinks = null;

    /**
     * A page of entries resolves its Tally links in a constant number of
     * queries — TallySyncLinkService::forMany / forEntryIds over the whole
     * page — instead of one lookup per row, then hands each row its own
     * answer. Everything else about the collection is the framework's.
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        return tap(parent::collection($resource), function (AnonymousResourceCollection $collection): void {
            if (! $collection->collection instanceof Collection) {
                return;
            }

            $rows = $collection->collection->filter(fn ($row) => $row instanceof self);
            $links = self::tallyLinksFor($rows->map(fn (self $row) => $row->resource));
            $rows->each(function (self $row) use ($links): void {
                $row->pageTallyLinks = $links;
            });
        });
    }

    public function toArray(Request $request): array
    {
        // Priced ONCE and shared by the two keys that need it below.
        // materialCost() runs a stock_movements query per entry, so letting
        // `material_cost` and `batch_cost` each compute their own would
        // double the query count of the busiest read in the module — a
        // 20-row approval page is the normal case, not the worst one.
        $materialCost = app(ShiftProductionEntryService::class)->materialCost($this->resource);

        // ONE GATE FOR EVERY RATE ON THIS PAYLOAD. Per-material purchase
        // rates are Owner/Accounts data (FC-06); the module-coarse
        // finance.view/manage pair is the permission they already hold and
        // the floor does not — the MaterialLotResource rule, applied to both
        // cost blocks below from a single evaluation so they can never
        // disagree about who a rate is for.
        $showsRates = (bool) $request->user()?->canAny(['finance.view', 'finance.manage']);

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
            // WHICH packaging row the run started against, frozen at Start.
            // Two same-mode packings can coexist on one standard (Phase 5,
            // D1 — a 490/box tray and a 520/box tray), so the mode below no
            // longer names one row; the completion drawer seeds its packing
            // line from this id first and falls back to the mode only for a
            // batch started before the id was frozen.
            'production_standard_packaging_id' => $this->production_standard_packaging_id,
            'packaging_mode' => $this->packaging_mode,
            // The Tally identity this batch's finished goods post as
            // (DEC-20260810-003) — frozen at completion when the selected
            // packaging carries its own item; null means the product's item,
            // which every consumer already shows. The View modal and results
            // print THIS name beside the product when the two differ.
            'finished_item' => $this->finished_item_id === null ? null : [
                'id' => (int) $this->finished_item_id,
                'name' => (string) $this->finishedItem?->name,
            ],
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
            // WHAT ONE BOTTLE OF THIS RUN WEIGHS, read out of the same snapshot
            // and surfaced for the same reason the colour above is.
            //
            // This is the weight the SERVER uses. resolvedUnitWeightGrams()
            // takes config_snapshot['unit_weight_grams'] first and the item
            // master only as a fallback, and every kilogram stored on the entry
            // — produced, rejection — is computed from it.
            //
            // Until now no client could read it back, so the completion drawer
            // previewed its kilograms from the item master alone. Those agree
            // whenever no configuration overrode the weight, and diverge
            // silently the moment one does: the screen shows one figure and the
            // server stores another, with nothing saying which is which. The
            // factory found the same class of defect the hard way on 5 August,
            // when two different weights sat on one panel under a line claiming
            // they were the same.
            //
            // Null is a real answer — this run froze no weight, so the item
            // master's is the truth and the screen should use exactly that.
            'unit_weight_grams' => $this->config_snapshot['unit_weight_grams'] ?? null,
            'cycle_time_source' => $this->cycle_time_source,
            'cavities_source' => $this->cavities_source,
            'override_reason' => $this->override_reason,
            'calculation_version' => $this->calculation_version,
            'material_consumptions' => ShiftMaterialConsumptionResource::collection($this->whenLoaded('materialConsumptions')),
            'scraps' => ShiftScrapResource::collection($this->whenLoaded('scraps')),
            // HOW THE BATCH WAS PACKED — the packing_lines the completion
            // was validated against, stored line for line (Phase 5, §4.16
            // closed) and replaced by an amendment. Empty for a completion
            // that typed none, and for every batch completed before the
            // table existed. Same whenLoaded rule as the lines above.
            'packing_lines' => ShiftProductionEntryPackingLineResource::collection($this->whenLoaded('packingLines')),
            // WHAT THE COMPLETION DRAWER IS PRE-FILLED WITH (Phase 5.5,
            // WS-B): the five pack counts through the ONE reader
            // (PackQuantityResolver::forEntry — typed entry columns, else
            // the run's frozen packaging row, else the item master), each
            // labelled with the rung that answered. Before completion these
            // are the frozen configuration's counts; after it, what was
            // recorded, so the amend drawer opens on the record. Always
            // present; a figure no rung holds is null, never invented.
            'packing_defaults' => app(CompletionDefaultsService::class)->packingDefaults($this->resource),
            // WHAT THE RUN'S CONFIGURATION WAS STILL MISSING, in the one
            // vocabulary every configuration surface repeats
            // (ProductVariantService::MISSING_VOCABULARY, in that order) —
            // judged for THIS run's frozen standard and packaging, not the
            // standard's union. `source` says whether it was frozen at
            // Start ('snapshot') or computed now from the frozen ids
            // ('live'); Completed Today's "config incomplete" tag reads it.
            'configuration_gaps' => app(CompletionDefaultsService::class)->configurationGaps($this->resource),
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

            /*
             * THE THREE SOURCES, NAMED SEPARATELY.
             *
             * `standard_cavities` above is a misnomer this block exists to
             * undo: it is filled configuration-first, so a screen labelling it
             * "std" tells a supervisor they are reading the Excel workbook when
             * they are reading a machine exception. That is precisely how the
             * 60 ml Round Amber product displayed 4 cavities for days while
             * both the workbook and the Configuration screen said 5.
             *
             * The column keeps its name and value — renaming it would rewrite
             * history on every past batch. What is added is the truth beside
             * it: what the WORKBOOK said, what the MACHINE said, and what the
             * run actually used. A screen can now show all three and never
             * again present one as the other. Nulls are emitted rather than
             * omitted so a reader can tell "no machine value" from "not loaded".
             */
            'figure_sources' => [
                'product_standard' => [
                    'cavities' => $this->productionStandard?->cavities,
                    'cycle_time' => $this->productionStandard?->cycle_time,
                    'source_reference' => $this->productionStandard?->source_reference,
                    'label' => 'Product Standard (Excel workbook)',
                ],
                'machine_configuration' => [
                    'cavities' => $this->productionConfiguration?->default_cavities,
                    'cycle_time' => $this->productionConfiguration?->default_cycle_time,
                    'approved_by_person' => $this->productionConfiguration?->approved_by !== null,
                    'label' => 'Machine Configuration (approved exception)',
                ],
                'active' => [
                    'cavities' => $this->active_cavities,
                    'cycle_time' => $this->actual_cycle_time ?? $this->standard_cycle_time,
                    'cavities_source' => $this->cavities_source,
                    'cycle_time_source' => $this->cycle_time_source,
                    'label' => 'Active on this run',
                ],
            ],
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
            //
            // THE PER-LINE RATES ARE FINANCE'S (FC-06), same stance as
            // batch_cost below: the total and the consumption lines (which
            // material, which store, how many kg) are for everyone on the
            // floor; each line's `unit_cost` — and its `cost`, which is that
            // rate one division away — are passed only for finance.view/
            // manage and are ABSENT, not null, for anyone else. Absent
            // matters here for the reason MaterialLotResource's class note
            // gives: a null unit cost is a real answer on this payload ("this
            // issue was unpriced"), so nulling it for a production login
            // would be telling them the resin cost nothing.
            'material_cost' => $showsRates ? $materialCost : $this->withoutLineRates($materialCost),
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
                withDetail: $showsRates,
                materialCost: $materialCost,
            ),
            'sync_error' => $this->when(
                $this->status === ShiftProductionEntryStatus::Failed && $this->relationLoaded('tallySyncEntries'),
                fn () => $this->tallySyncEntries->first()?->error_message,
            ),
            // WHERE THIS BATCH STANDS IN TALLY — a TallyLink (status ·
            // voucher_number · synced_at · flags · deep link; never the
            // payload) for the voucher that CARRIES the entry, or null when
            // none exists yet. Under shift granularity that is the Stock
            // Journal the entry was stamped onto (tally_sync_entry_id — the
            // voucher's syncable is the Shift, not this entry); under batch
            // granularity, and for history from before the flip, it is the
            // entry's own voucher through the morph. The shift voucher wins
            // when both exist: it is the one whose lines the entry is in
            // now. Cross-module through TallySync's TallySyncLinkService,
            // one read per page (see collection()) — so approval state and
            // Tally state can sit in one column on Completed Today.
            'tally' => $this->tallyLink(),
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

    /**
     * This entry's TallyLink: the page's precomputed answer when this
     * resource was made as part of a collection, else looked up for the one
     * entry (two small reads — a single-entry response is store/complete/
     * approve, never a list).
     *
     * @return array<string, mixed>|null
     */
    private function tallyLink(): ?array
    {
        if ($this->pageTallyLinks !== null) {
            return $this->pageTallyLinks[(int) $this->id] ?? null;
        }

        return self::tallyLinksFor(new Collection([$this->resource]))[(int) $this->id] ?? null;
    }

    /**
     * The TallyLinks for a set of entries, keyed by entry id, in a constant
     * number of queries: one over tally_sync_entries by the shift-voucher
     * ids the entries carry (tally_sync_entry_id), one over the entries'
     * own morph. Entries with no voucher of either kind map to null.
     *
     * @param  Collection<int, ShiftProductionEntry>  $entries
     * @return array<int, array<string, mixed>|null>
     */
    private static function tallyLinksFor(Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $links = app(TallySyncLinkService::class);

        $shiftVoucherIds = $entries->pluck('tally_sync_entry_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $byShiftVoucher = $shiftVoucherIds === [] ? [] : $links->forEntryIds($shiftVoucherIds);
        $byEntry = $links->forMany((new ShiftProductionEntry)->getMorphClass(), $entries->pluck('id')->all());

        $out = [];
        foreach ($entries as $entry) {
            $shiftVoucherId = $entry->tally_sync_entry_id === null ? null : (int) $entry->tally_sync_entry_id;
            $out[(int) $entry->id] = ($shiftVoucherId !== null ? ($byShiftVoucher[$shiftVoucherId] ?? null) : null)
                ?? $byEntry[(int) $entry->id]
                ?? null;
        }

        return $out;
    }

    /**
     * `material_cost` as the floor sees it: every line minus its `unit_cost`
     * and `cost` keys (removed, never nulled), the aggregate `total_cost`
     * kept. Shaping only — the full array is still what batch_cost is
     * priced from, so this must never be fed back into the service.
     *
     * @param  array{lines: list<array<string, mixed>>, total_cost: ?string}|null  $materialCost
     * @return array{lines: list<array<string, mixed>>, total_cost: ?string}|null
     */
    private function withoutLineRates(?array $materialCost): ?array
    {
        if ($materialCost === null) {
            return null;
        }

        return [
            ...$materialCost,
            'lines' => array_map(
                fn (array $line) => Arr::except($line, ['unit_cost', 'cost']),
                $materialCost['lines'],
            ),
        ];
    }
}
