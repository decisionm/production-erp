<?php

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Http\Requests\ListMaintenanceSchedulesRequest;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MaintenanceScheduleService
{
    public function __construct(private readonly MaintenanceWorkOrderService $workOrders) {}

    /** Soonest due first unless a sort is asked for (ListMaintenanceSchedulesRequest::SORTABLE); id desc tiebreaks. */
    public function paginate(?int $assetId, int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = MaintenanceSchedule::query()
            ->when($assetId, fn ($query) => $query->where('asset_id', $assetId))
            ->with('asset');

        return ListSort::apply($query, $sort, ListMaintenanceSchedulesRequest::SORTABLE, 'next_due_date')
            ->paginate($perPage);
    }

    public function create(array $data): MaintenanceSchedule
    {
        return MaintenanceSchedule::create([
            'is_active' => true,
            ...$data,
        ])->load('asset');
    }

    /**
     * Generates one preventive work order per overdue active schedule and
     * advances next_due_date past today. Deliberately API-triggered rather
     * than assuming a persistent cron/queue worker is available on every
     * deployment (see TECHNICAL-DOCS.md §8) — call this from wherever
     * scheduling actually exists for a given instance (Laravel's
     * scheduler, an external cron hitting this endpoint, or a manual
     * click). If a schedule is overdue by multiple periods, this still
     * only creates one catch-up work order, not one per missed period.
     *
     * @return array<int, MaintenanceWorkOrder>
     */
    public function generateDueWorkOrders(): array
    {
        return DB::transaction(function () {
            $dueSchedules = MaintenanceSchedule::query()
                ->where('is_active', true)
                ->where('next_due_date', '<=', today())
                ->get();

            $created = [];

            foreach ($dueSchedules as $schedule) {
                $created[] = $this->workOrders->createForSchedule($schedule);

                $nextDueDate = $schedule->next_due_date->copy();
                do {
                    $nextDueDate->addDays($schedule->frequency_days);
                } while ($nextDueDate->lte(today()));

                $schedule->update(['next_due_date' => $nextDueDate->toDateString()]);
            }

            return $created;
        });
    }
}
