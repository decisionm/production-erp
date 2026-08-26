<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\AvailabilityService;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * WHEN THE FACTORY COULD HAVE IT — the ETA behind the planning dashboard.
 *
 * COMPUTED ON READ AND NEVER PERSISTED (S11). There is no ETA column
 * anywhere in this build, deliberately: a stored date is already wrong the
 * moment somebody reorders the queue, and a wrong date that LOOKS
 * authoritative is worse than no date at all. Nothing in this class writes.
 *
 * IT INVENTS NOTHING. Every figure comes from the factory's own standards
 * through BatchEstimationService (the approved machine configuration, then
 * the product standard, then the item master — the same precedence the
 * Start Batch preview uses, so the dashboard and the floor cannot quote two
 * different cycle times) and from the ACTIVE shift rows' real clocks. When
 * a product has no usable standard the answer is CANNOT ESTIMATE with a
 * reason — never an interpolated date, never a caveat-date (S12, and the
 * standing factory rule that a missing figure is reported missing).
 *
 * AND THE REFUSAL CASCADES. The queue is worked in priority order on the
 * same machines, so a product the factory cannot estimate does not merely
 * lose its own date: everything BEHIND it in the queue loses one too,
 * because nobody can say how long the unestimable job will hold the line.
 * Reporting confident dates for the tail while the head is unknown is the
 * single most misleading thing this screen could do.
 *
 * FACTORY TIME, NEVER BARE now(). The walk starts at the next SHIFT
 * BOUNDARY in config('tally-sync.factory_timezone'); app.timezone is UTC on
 * the live system and a bare now() would put the boundary five and a half
 * hours out — the same IST trap Shift::productionDateFor()'s no-arg default
 * hides, which is why the shift start times here are compared against an
 * explicitly localised clock instead.
 */
class FulfilmentPlanningService
{
    /** The line is itself unestimable — no usable standard for its product. */
    public const REASON_NO_STANDARD = 'no_production_standard';

    /** Something ahead of it in the queue is unestimable, so its own date is unknowable (S12). */
    public const REASON_ITEMS_AHEAD = 'items_ahead_without_standard';

    /** The factory has no active shift with a usable clock — nothing can be planned at all. */
    public const REASON_NO_SHIFT_HOURS = 'no_active_shift_hours';

    public function __construct(
        private readonly BatchEstimationService $estimation,
        private readonly ProductionStandardResolver $standards,
        // FREE STOCK per item — Inventory's read, through Inventory's own
        // service.
        private readonly AvailabilityService $availability,
    ) {}

    /** @var array<string, ?int> capacity per (item, slot length), once per plan() */
    private array $capacityByHours = [];

    /**
     * The whole dashboard in one read: every open request with its ETA or
     * its refusal, the basis those numbers were computed on, and what the
     * floor should be working on today.
     *
     * @return array{
     *     data: list<array<string, mixed>>,
     *     basis: array{shifts_per_day: int, parallel_lines: int, shift_hours: ?string, timezone: string, source: string},
     *     today_targets: list<array<string, mixed>>,
     * }
     */
    public function plan(): array
    {
        $timezone = (string) config('tally-sync.factory_timezone');
        $shifts = $this->activeShiftsWithClocks();
        $shiftsPerDay = $shifts->count();
        $shiftHours = $this->shiftHours($shifts);
        // The REAL boundaries the walk moves through — each slot carries its
        // own start, its own length and whether it crosses midnight. The
        // averaged shift_hours above stays a published basis figure and the
        // display capacity; the calendar walk below never uses it
        // (Codex P1 ×2, PR #33: a 14:00 two-shift job is in hand at six the
        // NEXT morning, and a six-hour shift is not padded to the average).
        $slots = $this->slots($shifts);

        // PARALLEL LINES — how many machines the factory runs a queued
        // product on at once. Configuration, not a guess: it defaults to 1
        // so an unconfigured deployment plans conservatively (one line) and
        // quotes a date the factory can beat, rather than one it will miss.
        $parallelLines = max(1, (int) config('production.planning.parallel_lines', 1));

        $basis = [
            'shifts_per_day' => $shiftsPerDay,
            'parallel_lines' => $parallelLines,
            // Hours in ONE shift. Derived from the SUM of the real active
            // shift rows divided by how many there are — never one row's
            // clock assumed to hold for all three, which is how a 10-hour
            // day shift silently turned a 24-hour factory into a 30-hour
            // one. shift_hours × shifts_per_day is the factory's day.
            'shift_hours' => $shiftHours,
            'timezone' => $timezone,
            'source' => $shiftsPerDay > 0 ? 'active_shifts' : 'no_active_shifts',
        ];

        $requests = ProductionRequest::query()
            ->open()
            // THE WHOLE ITEM, not a column list: the estimate falls back to
            // the item master's cycle time, cavities and weight when no
            // standard resolves, and a trimmed select silently reads those
            // as missing — which is a cannot_estimate for a product the
            // factory can perfectly well make.
            ->with(['item', 'salesOrderLine.salesOrder.customer:id,name'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $boundary = $slots === [] ? null : $this->nextBoundary($slots, $timezone);
        $start = $boundary['date'] ?? null;
        $startIdx = (int) ($boundary['idx'] ?? 0);
        $free = $this->freeByItem($requests);

        $rows = [];
        $todayTargets = [];
        $shiftsBefore = 0;
        $queuedAhead = 0;
        $blocked = false;
        $todayOpen = $shiftsPerDay > 0;
        $capacityCache = [];

        foreach ($requests as $request) {
            $needed = (string) $request->quantity;
            $itemId = (int) $request->item_id;

            $capacity = null;
            $shiftsNeeded = null;
            $readyDate = null;
            $reason = null;

            if ($blocked) {
                // S12: something ahead of this one cannot be estimated, so
                // neither can this. No date, and no caveat-date either.
                $reason = self::REASON_ITEMS_AHEAD;
            } elseif ($shiftHours === null || $start === null) {
                $reason = self::REASON_NO_SHIFT_HOURS;
                $blocked = true;
            } else {
                $capacity = $capacityCache[$itemId] ??= $this->capacityPerShift($request->item, $shiftHours);

                if ($capacity === null || $capacity <= 0) {
                    // The line that is itself unestimable cannot carry
                    // 'items_ahead_without_standard' — nothing is ahead of
                    // it. It gets its own reason and cascades the brief's to
                    // everything behind it.
                    $capacity = null;
                    $reason = self::REASON_NO_STANDARD;
                    $blocked = true;
                } else {
                    [$shiftsNeeded, $readyDate] = $this->walkSlots(
                        $request->item,
                        $needed,
                        $slots,
                        $startIdx,
                        $shiftsBefore,
                        $start,
                        $parallelLines,
                    );

                    if ($shiftsNeeded === null) {
                        // A slot too short to make one piece refused the
                        // walk (Codex P2, PR #33): an estimation refusal
                        // like any other — said out loud, and cascading —
                        // never a silent dateless row.
                        $capacity = null;
                        $reason = self::REASON_NO_STANDARD;
                        $blocked = true;
                    }
                }
            }

            $rows[] = [
                'line_id' => (int) $request->sales_order_line_id,
                'item' => $this->itemPayload($request->item),
                'customer' => $this->customerPayload($request),
                'needed' => $needed,
                'free' => $free[$itemId] ?? '0.0000',
                // How many open requests sit in front of it — the honest
                // "why is my order not first" figure, and one that stays
                // knowable even when nothing behind an unestimable product
                // can be dated.
                'queued_ahead' => $queuedAhead,
                'capacity_per_shift' => $capacity,
                'shifts_needed' => $shiftsNeeded,
                'estimated_ready_date' => $readyDate,
                'cannot_estimate' => $reason !== null,
                'reason' => $reason,
            ];

            $queuedAhead++;

            // TODAY'S WORK is a priority read, not a capacity claim: a job
            // whose first shift falls inside today's shifts is on the
            // floor's list whether or not its own finish date is knowable.
            // Only the slots actually LEFT today count as today (Codex P2,
            // PR #33): from the 22:00 boundary there is one, not three.
            if ($todayOpen && $shiftsBefore < count($slots) - $startIdx) {
                $todayTargets[] = [
                    'request_id' => (int) $request->id,
                    'item' => $this->itemPayload($request->item),
                    'quantity' => $needed,
                    'priority' => (int) $request->priority,
                ];
            }

            if ($shiftsNeeded === null) {
                // An unestimable job holds the line for an unknown number of
                // shifts, so the walk stops advancing — and today's list
                // stops here too, because nobody can say whether the jobs
                // behind it start today or next week.
                $todayOpen = false;

                continue;
            }

            $shiftsBefore += $shiftsNeeded;
        }

        return ['data' => $rows, 'basis' => $basis, 'today_targets' => $todayTargets];
    }

    // ---- internals --------------------------------------------------------

    /**
     * What one shift of this product is worth, through the SAME
     * targetPieces() the Start Batch preview and the completion metrics use
     * — cycles floored before cavities multiply, so the dashboard cannot
     * quote a shift the floor could not run.
     *
     * NO MACHINE CONFIGURATION IS PASSED, and that is deliberate rather than
     * an omission of the configuration>standard>item precedence: a
     * configuration belongs to a machine-product pair, and planning has not
     * chosen a machine. Feeding one in would quote a specific machine's
     * cycle time for work that may run anywhere. The precedence itself stays
     * BatchEstimationService's — this only declines to assert the top tier.
     */
    private function capacityPerShift(?Item $item, string $shiftHours): ?int
    {
        if ($item === null) {
            return null;
        }

        $estimate = $this->estimation->estimate(
            item: $item,
            shift: null,
            plannedHours: $shiftHours,
            standard: $this->standards->resolve($item->id),
        );

        return $estimate['expected_pieces'];
    }

    /**
     * The active shifts that have a usable clock, earliest start first.
     *
     * A shift with no start or end time is SKIPPED, never folded in as zero
     * hours: zero would quietly shrink the factory's day, and the whole
     * point of this class is that a missing figure is reported missing.
     *
     * @return Collection<int, Shift>
     */
    private function activeShiftsWithClocks(): Collection
    {
        return Shift::query()
            ->where('is_active', true)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Hours in one shift — the sum of the real active shift rows' clocks
     * divided by how many there are.
     *
     * @param  Collection<int, Shift>  $shifts
     */
    private function shiftHours(Collection $shifts): ?string
    {
        $total = '0.0000';
        $counted = 0;

        foreach ($shifts as $shift) {
            $hours = $this->estimation->shiftLengthHours($shift);

            if ($hours === null) {
                continue;
            }

            $total = bcadd($total, $hours, 4);
            $counted++;
        }

        if ($counted === 0 || bccomp($total, '0', 4) !== 1) {
            return null;
        }

        return bcdiv($total, (string) $counted, 4);
    }

    /**
     * The day's shift slots in start order — each with its OWN length and
     * whether its end falls past midnight. A shift whose clock cannot be
     * read is skipped, never counted as zero hours.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return list<array{start: string, hours: string, overnight: bool}>
     */
    private function slots(Collection $shifts): array
    {
        $slots = [];

        foreach ($shifts as $shift) {
            $hours = $this->estimation->shiftLengthHours($shift);

            if ($hours === null) {
                continue;
            }

            $slots[] = [
                'start' => (string) $shift->start_time,
                'hours' => $hours,
                // TIME strings compare correctly as strings — the
                // Shift::productionDateFor() convention. End at or before
                // start means the shift finishes on the NEXT calendar day.
                'overnight' => (string) $shift->end_time <= (string) $shift->start_time,
            ];
        }

        return $slots;
    }

    /**
     * The next slot that actually starts, in FACTORY time — the datetime AND
     * which slot of the day it is, because the calendar walk needs to know
     * where in the day's sequence it is standing. Past every start today
     * means the earliest start tomorrow.
     *
     * @param  list<array{start: string, hours: string, overnight: bool}>  $slots
     * @return array{date: CarbonImmutable, idx: int}
     */
    private function nextBoundary(array $slots, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $nowTime = $now->format('H:i:s');

        foreach ($slots as $idx => $slot) {
            if ($slot['start'] > $nowTime) {
                return ['date' => $now->setTimeFromTimeString($slot['start']), 'idx' => $idx];
            }
        }

        return ['date' => $now->addDay()->setTimeFromTimeString($slots[0]['start']), 'idx' => 0];
    }

    /**
     * WALK THE QUEUE THROUGH THE REAL BOUNDARIES (Codex P1 ×2, PR #33).
     *
     * Each consumed slot contributes ITS OWN length's capacity — a six-hour
     * shift is never padded to the day's average — and the finish date is
     * the calendar day the LAST slot actually ends on: an overnight slot
     * ends tomorrow, however few shifts were counted. Decimal arithmetic
     * end to end; the remaining figure strictly decreases because a slot
     * whose capacity is not at least one piece refuses the estimate.
     *
     * @param  list<array{start: string, hours: string, overnight: bool}>  $slots
     * @return array{0: ?int, 1: ?string} [shifts needed, ready date] — nulls when unestimable
     */
    private function walkSlots(
        ?Item $item,
        string $needed,
        array $slots,
        int $startIdx,
        int $offset,
        CarbonImmutable $start,
        int $parallelLines,
    ): array {
        $perDay = count($slots);
        $cursor = $offset;
        $shiftsNeeded = 0;
        $remaining = bcadd($needed, '0', 4);

        while (bccomp($remaining, '0', 4) === 1) {
            $slot = $slots[($startIdx + $cursor) % $perDay];
            $capacity = $this->capacityForHours($item, $slot['hours']);

            if ($capacity === null || $capacity < 1) {
                return [null, null];
            }

            $remaining = bcsub($remaining, (string) ($capacity * $parallelLines), 4);
            $shiftsNeeded++;
            $cursor++;
        }

        $last = $startIdx + $cursor - 1;
        $finish = $slots[$last % $perDay];

        return [
            $shiftsNeeded,
            $start->addDays(intdiv($last, $perDay) + ($finish['overnight'] ? 1 : 0))->toDateString(),
        ];
    }

    /**
     * capacityPerShift(), cached per (item, slot length) — the walk asks for
     * the same one or two durations hundreds of times.
     */
    private function capacityForHours(?Item $item, string $hours): ?int
    {
        if ($item === null) {
            return null;
        }

        $key = $item->id.'|'.$hours;

        if (! array_key_exists($key, $this->capacityByHours)) {
            $this->capacityByHours[$key] = $this->capacityPerShift($item, $hours);
        }

        return $this->capacityByHours[$key];
    }

    /**
     * Free stock per item, for every product in the queue — one read, not
     * one per row.
     *
     * @param  Collection<int, ProductionRequest>  $requests
     * @return array<int, string>
     */
    private function freeByItem(Collection $requests): array
    {
        $itemIds = $requests->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $free = [];

        foreach ($this->availability->forItems($itemIds) as $row) {
            $free[$row['item_id']] = $row['free'];
        }

        return $free;
    }

    /** @return array{id: int, sku: ?string, name: ?string}|null */
    private function itemPayload(?Item $item): ?array
    {
        return $item === null ? null : [
            'id' => (int) $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
        ];
    }

    /** @return array{id: int, name: ?string}|null */
    private function customerPayload(ProductionRequest $request): ?array
    {
        $customer = $request->salesOrderLine?->salesOrder?->customer;

        return $customer === null ? null : [
            'id' => (int) $customer->id,
            'name' => $customer->name,
        ];
    }
}
