<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The CEC's DATA (MASTER-PLAN P5.7-02) — a THIN COMPOSITION of the two
 * things the factory already computes for a production date, and nothing
 * else:
 *
 *   1. the Shift KPI Summary — ShiftSummaryService::report, per shift and
 *      for the whole day, carried VERBATIM under `summary` (the supervisor's
 *      typed target/power, the actual/rejection kg, the logs — whatever that
 *      report says, in the keys it says it);
 *   2. the completed entries of the entries index — the Completed Today read
 *      (ShiftProductionEntryService::paginate: production_date · shift_id ·
 *      batch_status=completed · per_page 100, every page walked), each row
 *      read THROUGH ShiftProductionEntryResource exactly as the index wires
 *      it, and grouped by machine.
 *
 * NO NEW ARITHMETIC. Every batch figure is read off the index row's
 * `metrics` / `quality` / `status` / `tally` (expected pieces, actual pieces,
 * good kg, rejection kg, efficiency, packs, netted downtime minutes) — never
 * recomputed here. The only arithmetic in this class is a PLAIN SUM of those
 * figures per machine, per shift and for the day, and each sums block says
 * so (`basis`) and says which figures it could not include (`skipped_nulls`)
 * rather than passing a missing figure off as zero. A ratio (efficiency) is
 * never summed: the shift's efficiency is the summary's own
 * efficiency_percent, against the supervisor's target.
 *
 * THE FORMAT IS BLOCKED — SOURCE DOCUMENT REQUIRED. No CEC sample exists in
 * the repo and a factory document is never invented: this endpoint answers
 * the DATA so a screen can preview it and so the golden harness
 * (tests/Feature/Production/CecGoldenTest.php) has something to hold to
 * the owner's sample the day it lands; the export KIND (CecExport) stays
 * blocked until then. The response says so itself (`format`) and names its
 * two sources (`figures_from`).
 */
class CecReportService
{
    public const FORMAT = 'BLOCKED — SOURCE DOCUMENT REQUIRED';

    public const FIGURES_FROM = ['shift_summary', 'shift_production_entries'];

    public const SUMS_BASIS = 'plain sums of the batch figures above — a null figure is skipped and counted under skipped_nulls; efficiency_pct is a ratio and is not summed (the shift\'s efficiency is the summary\'s efficiency_percent); nothing here is recomputed';

    /**
     * The batch figures the sums add up, and the scale each is added at —
     * bcadd on the decimal strings the metrics carry, a plain integer sum
     * for packs (no_of_box is an integer column).
     *
     * @var array<string, int|null>
     */
    private const SUMMED = [
        'expected_pieces' => 4,
        'actual_pieces' => 4,
        'good_production_kg' => 4,
        'rejection_kg' => 4,
        'packs' => null,
        'downtime_minutes_total' => 2,
    ];

    /** The Completed Today page size — every page is walked. */
    private const PER_PAGE = 100;

    public function __construct(
        private readonly ShiftSummaryService $summaries,
        private readonly ShiftProductionEntryService $entries,
    ) {}

    /**
     * The CEC data for a date: one shift, or (shift_id null) every shift
     * with anything recorded on the date — the same set the shift_summary
     * export walks (ShiftSummaryService::shiftsWithRecordsOn, in the picker's
     * order) — plus the DAY block (the whole-day summary and the sums over
     * every shift's batches). `day` is null on a single-shift read: that
     * scope is the one shift, and its totals are the shift's own.
     *
     * @return array<string, mixed>
     */
    public function report(string $date, ?int $shiftId): array
    {
        $shifts = $shiftId === null
            ? $this->summaries->shiftsWithRecordsOn($date)
            : new Collection(array_filter([Shift::withTrashed()->find($shiftId)]));

        $blocks = $shifts->map(fn (Shift $shift) => $this->shiftBlock($shift, $date))->values()->all();

        return [
            'production_date' => $date,
            'shift_id' => $shiftId,
            'scope' => $shiftId === null ? 'day' : 'shift',
            'format' => self::FORMAT,
            'figures_from' => self::FIGURES_FROM,
            'shifts' => $blocks,
            'day' => $shiftId === null
                ? [
                    'summary' => $this->summaries->report(null, $date),
                    'sums' => $this->sums(collect($blocks)->flatMap(fn (array $block) => collect($block['machines'])->flatMap(fn (array $machine) => $machine['batches']))->all()),
                ]
                : null,
        ];
    }

    /**
     * One shift: its identity, its Shift Summary report verbatim, its
     * completed batches grouped by machine (each machine with its own sums),
     * and the sums over the shift's batches.
     *
     * @return array<string, mixed>
     */
    private function shiftBlock(Shift $shift, string $date): array
    {
        $machines = $this->machines($date, $shift->id);

        return [
            'shift' => [
                'id' => $shift->id,
                'name' => $shift->name,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
            ],
            'summary' => $this->summaries->report($shift->id, $date),
            'machines' => $machines,
            'sums' => $this->sums(collect($machines)->flatMap(fn (array $machine) => $machine['batches'])->all()),
        ];
    }

    /**
     * The completed batches of the date/shift, every index page walked,
     * grouped by machine in the machine picker's order (display_sequence
     * first, nulls last, then the shortest code, code, name — the
     * WorkCenterService list order), each batch a row of the entries index
     * reduced to the CEC's figures.
     *
     * @return list<array<string, mixed>>
     */
    private function machines(string $date, int $shiftId): array
    {
        $request = $this->request();
        $groups = [];

        $page = 1;
        do {
            $paginator = $this->entries->paginate(
                perPage: self::PER_PAGE,
                productionDate: $date,
                shiftId: $shiftId,
                batchStatus: BatchStatus::Completed,
                page: $page,
            );

            /** @var Collection<int, ShiftProductionEntry> $models */
            $models = $paginator->getCollection()->values();
            // THROUGH the resource, as the index wires it — the metrics, the
            // quality block, the approval status and the page's Tally links
            // resolved in the collection's constant number of queries.
            $rows = ShiftProductionEntryResource::collection($models)->resolve($request);

            foreach ($models as $i => $entry) {
                $machineId = (int) $entry->work_center_id;
                $groups[$machineId] ??= [
                    'machine' => $this->machineIdentity($entry->workCenter, $machineId),
                    'batches' => [],
                ];
                $groups[$machineId]['batches'][] = $this->batch($rows[$i], $request);
            }

            $page++;
        } while ($page <= $paginator->lastPage());

        $ordered = collect($groups)
            ->sortBy(fn (array $group) => [
                $group['machine']['display_sequence'] === null ? 1 : 0,
                $group['machine']['display_sequence'] ?? 0,
                strlen((string) $group['machine']['code']),
                (string) $group['machine']['code'],
                (string) $group['machine']['name'],
                $group['machine']['id'],
            ])
            ->values();

        return $ordered->map(function (array $group) {
            // The index answers newest first; a machine's batches read in
            // the order they were filed (oldest first) on a day sheet.
            $batches = array_reverse($group['batches']);

            return [
                'machine' => $group['machine'],
                'batches' => $batches,
                'sums' => $this->sums($batches),
            ];
        })->all();
    }

    /**
     * @return array{id: int, code: ?string, name: ?string, display_sequence: ?int}
     */
    private function machineIdentity(?WorkCenter $machine, int $id): array
    {
        return [
            'id' => $id,
            'code' => $machine?->code,
            'name' => $machine?->name,
            'display_sequence' => $machine?->display_sequence,
        ];
    }

    /**
     * One index row → the CEC's batch figures, each read off the row's own
     * blocks: `metrics` (the completion metrics the Results card shows),
     * `status` (approval), `tally` (the TallyLink of the voucher carrying the
     * entry), the packing count column. Nothing is computed.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function batch(array $row, Request $request): array
    {
        $metrics = $row['metrics'] ?? [];
        $item = $this->wire($row['item'] ?? null, $request);

        return [
            'entry_id' => $row['id'],
            'batch_number' => $row['batch_number'],
            'item' => $item === null ? null : [
                'id' => $item['id'] ?? null,
                'sku' => $item['sku'] ?? null,
                'name' => $item['name'] ?? null,
            ],
            'expected_pieces' => $metrics['expected_pieces'] ?? null,
            'actual_pieces' => $metrics['actual_pieces'] ?? null,
            // The kg the Shift Summary sums as actual_production_kg
            // (quantity_produced_kg, net of the quality gate) and as
            // rejection_kg (the production-side quantity_rejection_kg); QC's
            // weighed figure beside it, never in place of it.
            'good_production_kg' => $metrics['good_production_kg'] ?? null,
            'rejection_kg' => $metrics['rejection_kg_production'] ?? null,
            'rejection_kg_qc' => $metrics['rejection_kg_qc'] ?? null,
            'efficiency_pct' => $metrics['efficiency_pct'] ?? null,
            'efficiency_band' => $metrics['efficiency_band'] ?? null,
            'expected_boxes' => $metrics['expected_boxes'] ?? null,
            'packs' => $row['no_of_box'] ?? null,
            'downtime_minutes_total' => $metrics['downtime_minutes_total'] ?? null,
            'calculation_version' => $metrics['calculation_version'] ?? null,
            'approval_status' => $row['status'] ?? null,
            'tally_status' => $row['tally']['status'] ?? null,
            'tally' => $row['tally'] ?? null,
        ];
    }

    /**
     * The plain sums over a list of batches — SUMMED's figures only, a null
     * skipped and counted, `null` (never an invented zero) when nothing
     * contributed — labelled with the basis sentence.
     *
     * @param  list<array<string, mixed>>  $batches
     * @return array<string, mixed>
     */
    private function sums(array $batches): array
    {
        $out = ['batches' => count($batches)];
        $skipped = [];

        foreach (self::SUMMED as $key => $scale) {
            $sum = null;
            foreach ($batches as $batch) {
                $value = $batch[$key] ?? null;
                if ($value === null) {
                    $skipped[$key] = ($skipped[$key] ?? 0) + 1;

                    continue;
                }
                $sum = $scale === null
                    ? ($sum ?? 0) + (int) $value
                    : bcadd($sum ?? bcadd('0', '0', $scale), (string) $value, $scale);
            }
            $out[$key] = $sum;
        }

        // Always a JSON object — `{}` when nothing was skipped, never `[]`:
        // one shape for one key, whoever reads it (the golden guide, TS).
        $out['skipped_nulls'] = (object) $skipped;
        $out['basis'] = self::SUMS_BASIS;

        return $out;
    }

    /**
     * A nested resource as the wire carries it (an ItemResource on the row
     * is still a resource after the row's own resolve()); a plain array or
     * null passes through.
     *
     * @return array<string, mixed>|null
     */
    private function wire(mixed $value, Request $request): ?array
    {
        if ($value instanceof JsonResource) {
            return $value->resource === null ? null : $value->resolve($request);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * The request the rows are judged against — the current one (its user
     * gates the FC-06 cost keys the resource carries, none of which the CEC
     * reads); a bare GET when there is none, as an artisan or queued caller.
     */
    private function request(): Request
    {
        return app()->bound('request') ? app('request') : Request::create('/', 'GET');
    }
}
