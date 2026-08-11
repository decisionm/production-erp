<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\FinishedCarton;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carton identity generation and lookup.
 *
 * THE COUNT IS THE PACKED (GROSS) COUNT: cartons are physical objects packed
 * on the floor before QC nets anything — a QC rejection reduces approved
 * finished goods, not the number of boxes that exist. Full cartons carry the
 * run's pieces-per-box; a loose remainder becomes one partial carton
 * labelled as such.
 *
 * IDEMPOTENT BY CONSTRUCTION: carton numbers are {batch}-C{seq} and unique;
 * regenerating fills gaps only (there are none in practice) and never mints
 * a second identity for the same physical box. Reprint is a plain read.
 */
class FinishedCartonService
{
    /**
     * The one sentence the day-bin attribution must never be shown without
     * (DEC-20260810-001, and FC-01 behind it). It states the CALCULATED
     * claim — the common day bin held loads from these lots during that
     * production date and shift — and refuses the physical one: no bag and
     * no lot ever belongs to a batch. Same discipline, same reason, as
     * BagCostAllocationService::ALLOCATION_SENTENCE.
     */
    public const ATTRIBUTION_SENTENCE = 'The common day bin held loads from these lots during this production date and shift — a calculated attribution, costed at the resin pool\'s weighted average, never physical bag-to-batch identity';

    public function __construct(
        // The batch's costing rate for the internal tier — called, never
        // reimplemented; its weighted-average arithmetic lives in one place.
        private readonly BagCostAllocationService $costs,
        // The lot-rate seam (FC-06: rates surface ONLY on the internal
        // tier). Production's one door to Inventory's rate contract.
        private readonly BagLotRateResolver $rates,
    ) {}

    public function generateFor(ShiftProductionEntry $entry, ?int $userId): Collection
    {
        if ($entry->batch_status !== BatchStatus::Completed) {
            throw ValidationException::withMessages([
                'entry' => 'this batch is still running — cartons are labelled once the batch is completed and counted',
            ]);
        }

        return DB::transaction(function () use ($entry, $userId) {
            $existing = $entry->cartons()->count();
            if ($existing > 0) {
                return $this->listFor($entry);
            }

            // Packed = gross where a quality check has already netted the
            // entry; otherwise quantity_produced IS the packed count.
            $packed = (string) ($entry->gross_quantity_produced ?? $entry->quantity_produced ?? '0');
            $perBox = (string) ($entry->nos_per_box ?? ($entry->config_snapshot['nos_per_box'] ?? '0'));

            if (bccomp($packed, '0', 4) !== 1) {
                throw ValidationException::withMessages([
                    'entry' => 'this batch recorded no packed pieces — there is nothing to label',
                ]);
            }

            $batchNo = $entry->batch_number ?? "SPE-{$entry->id}";
            $sequence = 0;
            $rows = [];

            if (bccomp($perBox, '0', 4) === 1) {
                $fullBoxes = (int) bcdiv($packed, $perBox, 0);
                for ($i = 0; $i < $fullBoxes; $i++) {
                    $rows[] = [
                        'carton_no' => sprintf('%s-C%02d', $batchNo, ++$sequence),
                        'pieces' => $perBox,
                        'is_partial' => false,
                    ];
                }
                $remainder = bcsub($packed, bcmul((string) $fullBoxes, $perBox, 4), 4);
                if (bccomp($remainder, '0', 4) === 1) {
                    $rows[] = [
                        'carton_no' => sprintf('%s-C%02d', $batchNo, ++$sequence),
                        'pieces' => $remainder,
                        'is_partial' => true,
                    ];
                }
            } else {
                // No pieces-per-box on the run: one identity for the whole
                // packed count, honestly partial (an unknown box size must
                // not invent full boxes).
                $rows[] = [
                    'carton_no' => sprintf('%s-C%02d', $batchNo, ++$sequence),
                    'pieces' => $packed,
                    'is_partial' => true,
                ];
            }

            foreach ($rows as $row) {
                $entry->cartons()->create([
                    ...$row,
                    // The RESOLVED identity (DEC-20260810-003) — the label and
                    // the trace must name the same Tally item the voucher
                    // posts. Generation is completion-gated (the guard above),
                    // so finished_item_id is already frozen by the time any
                    // carton exists; pre-feature batches resolve to item_id.
                    'item_id' => $entry->effectiveItemId(),
                    'status' => FinishedCarton::STATUS_IN_STOCK,
                    'created_by' => $userId,
                ]);
            }

            return $this->listFor($entry);
        });
    }

    /**
     * The batch's cartons, loaded for the label: each carton carries its item
     * and the (one, shared) entry with machine and shift, so the resource can
     * print the batch spine and compute the net weight without a query per
     * box. This is also the reprint read — identities never change.
     */
    public function listFor(ShiftProductionEntry $entry): Collection
    {
        $entry->loadMissing(['workCenter', 'shift']);

        return $entry->cartons()->with('item')->orderBy('id')->get()
            ->each(fn (FinishedCarton $carton) => $carton->setRelation('entry', $entry));
    }

    /** The traceability read: one scanned carton back to its batch. */
    public function lookup(string $cartonNo): FinishedCarton
    {
        $carton = FinishedCarton::query()
            ->with(['item', 'entry.workCenter', 'entry.shift', 'delivery'])
            ->where('carton_no', $cartonNo)
            ->first();

        if ($carton === null) {
            throw ValidationException::withMessages([
                'carton_no' => "No carton carries the code {$cartonNo}.",
            ]);
        }

        return $carton;
    }

    /**
     * THE INTERNAL TIER behind a carton scan (DEC-20260810-001): completion
     * datetime, shift, day-bin lot attribution and the batch's costing rate.
     * Served only through the carton-trace permission gate — Owner, Plant
     * Manager and Accounts logins, an owner-worded widening of FC-06's rate
     * scope for exactly this surface and no other. Nothing here may leak
     * into the public lookup above, the label read, or a log line.
     *
     * @return array{
     *     carton: FinishedCarton,
     *     completion: array{completed_at: ?string, completed_on: ?string, shift: ?string},
     *     day_bin_attribution: array<string, mixed>,
     *     costing: array<string, mixed>,
     * }
     */
    public function internalTrace(string $cartonNo): array
    {
        $carton = $this->lookup($cartonNo);
        $entry = $carton->entry;

        $completedAt = $entry->batchCompletedAt();

        return [
            'carton' => $carton,
            'completion' => [
                'completed_at' => $completedAt?->toIso8601String(),
                // The factory's own calendar day for that instant — the app
                // clock is UTC and a bare date would misfile every batch
                // completed before 05:30 IST onto the previous day.
                'completed_on' => $completedAt
                    ?->setTimezone(config('tally-sync.factory_timezone'))
                    ->toDateString(),
                'shift' => $this->shiftFor($entry)?->name,
            ],
            'day_bin_attribution' => $this->dayBinAttribution($entry),
            // withDetail: the pool rates behind the totals. This gate is the
            // rate-bearing tier by the owner's word; everywhere else keeps
            // the finance.view gate ShiftProductionEntryResource applies.
            'costing' => $this->costs->summary($entry, withDetail: true),
        ];
    }

    /**
     * WHICH LOTS THE COMMON DAY BIN WAS LOADED FROM during this batch's
     * production date and shift — the CALCULATED attribution, and only that
     * (FC-01: a resin bag belongs to no machine and no batch, so this never
     * claims which lot a batch burnt; it reports what the shared bin was
     * fed while the batch's shift ran, priced per lot for provenance while
     * the batch itself is costed at the pool's weighted average).
     *
     * The window is the shift's own wall-clock span on the entry's
     * production date, read in the factory timezone (never a UTC calendar
     * day): [start_time, end_time), end-exclusive so a load stamped at the
     * exact handover instant belongs to the incoming shift. An overnight
     * shift ends on the following calendar day — same convention as
     * Shift::productionDateFor and ShiftVoucherReleaseGate.
     *
     * Loads recorded without a bag (the ledger permits them) cannot name a
     * lot; their kilograms are reported under unattributed_loaded_kg rather
     * than dropped — kg with no identity are still kg the bin was fed.
     *
     * @return array<string, mixed>
     */
    private function dayBinAttribution(ShiftProductionEntry $entry): array
    {
        $base = [
            'basis' => self::ATTRIBUTION_SENTENCE,
            'reason' => null,
            'window' => null,
            'lots' => [],
            'unattributed_loaded_kg' => '0.0000',
        ];

        $shift = $this->shiftFor($entry);
        $date = $entry->production_date?->toDateString();

        if ($shift === null || $date === null) {
            return [
                ...$base,
                'reason' => 'this batch no longer resolves a shift and production date, so the day-bin window cannot be reconstructed',
            ];
        }

        $timezone = config('tally-sync.factory_timezone');
        $from = CarbonImmutable::parse("{$date} {$shift->start_time}", $timezone);
        $until = CarbonImmutable::parse("{$date} {$shift->end_time}", $timezone);
        if ($shift->start_time > $shift->end_time) {
            $until = $until->addDay();
        }

        $loads = DayBinMovement::query()
            ->where('type', DayBinMovementType::Load->value)
            ->where('recorded_at', '>=', $from->utc())
            ->where('recorded_at', '<', $until->utc())
            ->with([
                'item' => fn ($relation) => $relation->withTrashed(),
                // Read-only cross-module relations (the documented
                // DayBinMovement/MaterialLot precedent) — lot and GRN
                // writes stay behind Inventory and Procurement.
                'materialBag.lot.grn',
                'materialBag.lot.costVersions',
            ])
            ->orderBy('recorded_at')
            ->get();

        $byLot = [];
        $unattributed = '0.0000';

        foreach ($loads as $load) {
            $lot = $load->materialBag?->lot;

            if ($lot === null) {
                $unattributed = bcadd($unattributed, (string) $load->quantity_kg, 4);

                continue;
            }

            $byLot[$lot->id] ??= [
                'lot' => $lot,
                'material' => $load->item?->name,
                'loaded_kg' => '0.0000',
            ];
            $byLot[$lot->id]['loaded_kg'] = bcadd($byLot[$lot->id]['loaded_kg'], (string) $load->quantity_kg, 4);
        }

        return [
            ...$base,
            'window' => [
                'production_date' => $date,
                'shift' => $shift->name,
                'timezone' => $timezone,
                'from' => $from->toIso8601String(),
                'until' => $until->toIso8601String(),
            ],
            'lots' => array_values(array_map(
                fn (array $row) => $this->lotAttribution($row['lot'], $row['material'], $row['loaded_kg']),
                $byLot,
            )),
            'unattributed_loaded_kg' => $unattributed,
        ];
    }

    /**
     * One lot's provenance line: identity, GRN reference, inward date and
     * the lot's own rate (with its source, so a reader knows whether an
     * invoice has revised it since receipt). The rate appears HERE and on
     * no other carton surface — FC-06, widened for this tier only.
     *
     * @return array<string, mixed>
     */
    private function lotAttribution(MaterialLot $lot, ?string $material, string $loadedKg): array
    {
        [$rate, $rateSource] = $this->rates->rateFor($lot);

        return [
            'material' => $material,
            'supplier_lot_no' => $lot->supplier_lot_no,
            'grn_reference' => $lot->grn?->reference,
            'inward_date' => $lot->received_date?->toDateString(),
            'rate_per_kg' => $rate,
            'rate_source' => $rateSource,
            'loaded_kg' => $loadedKg,
        ];
    }

    /**
     * The entry's shift, surviving a later soft delete: a retired shift
     * master must not erase a batch's trace, so the trashed row is read
     * when the live relation comes back empty.
     */
    private function shiftFor(ShiftProductionEntry $entry): ?Shift
    {
        return $entry->shift
            ?? ($entry->shift_id !== null ? Shift::withTrashed()->find($entry->shift_id) : null);
    }
}
