<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\WorkCenter;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkCenterService
{
    use ManagesConfigurationLifecycle;

    /**
     * @param  ?bool  $activeOnly  true = active only, false = inactive only,
     *                             null = both. Production selectors pass
     *                             true; the admin screen offers the filter.
     */
    public function paginate(int $perPage = 20, ?bool $activeOnly = null): LengthAwarePaginator
    {
        return $this->ordered(WorkCenter::query())
            ->when($activeOnly !== null, fn ($q) => $q->where('is_active', $activeOnly))
            ->paginate($perPage);
    }

    /**
     * Natural machine order: Machine 1 … Machine 9, Machine 10 — never
     * Machine 1, Machine 10, Machine 2.
     *
     * display_sequence carries it when set. When it is not, ordering by
     * name is alphabetical and puts "Machine 10" second, so the digits in
     * the code are the fallback: LENGTH then value sorts MC-01…MC-10
     * correctly without needing a database-specific natural-sort function
     * (this has to work on both sqlite and MySQL).
     */
    private function ordered($query)
    {
        return $query
            ->orderByRaw('display_sequence IS NULL')
            ->orderBy('display_sequence')
            ->orderByRaw('LENGTH(code)')
            ->orderBy('code')
            ->orderBy('name');
    }

    public function create(array $data): WorkCenter
    {
        return WorkCenter::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(WorkCenter $workCenter, array $data): WorkCenter
    {
        $workCenter->update($data);

        return $workCenter;
    }

    protected function configurationLabel(): string
    {
        return 'machine';
    }

    /**
     * EVERYTHING THAT REFERENCES A MACHINE, read off the schema of
     * 2026-08-19 rather than off a memory of it, and grouped by what the
     * database would do on a real DELETE — because that is what decides
     * whether this list is a convenience or the only guard there is.
     *
     * CASCADE — no database backstop; this check is the only thing between
     * a delete and destroyed history. Marked ->cascadeSide(), which also
     * counts soft-deleted children (production_configurations soft-deletes,
     * and a withdrawn configuration is still a physical row the cascade
     * takes):
     *   production_configurations.work_center_id
     *   production_downtime_events.work_center_id
     *
     * RESTRICT — the database would refuse anyway; declared so the user is
     * told WHAT is in the way instead of meeting a foreign-key error:
     *   shift_production_entries · machine_downtime_logs · mold_change_logs
     *   day_bin_movements · routing_operations
     *
     * SET NULL — the quiet one, and the reason this list is written column
     * by column. `ON DELETE SET NULL` is not a backstop: the delete
     * SUCCEEDS and the child's column is silently blanked, and
     * SchemaCascades only reads DELETE_RULE='CASCADE', so nothing in the
     * mechanism would have caught a missing declaration here either. A bag
     * that sat on a machine's day bin, or a material request raised for it,
     * would simply stop saying which machine:
     *   material_bags.day_bin_work_center_id
     *   material_requests.work_center_id   (added 19-Aug, after the audit)
     *
     * NOT declared, deliberately: activity_log rows about this machine.
     * Every master here records its own audit trail, so declaring the trail
     * as a dependency would make every audited master permanently
     * undeletable and delete the contract instead of implementing it.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('production_configurations', 'work_center_id')
                ->label('production configuration')->cascadeSide(),
            DependencyCheck::table('production_downtime_events', 'work_center_id')
                ->label('downtime event')->cascadeSide(),
            DependencyCheck::table('shift_production_entries', 'work_center_id')
                ->label('production batch'),
            DependencyCheck::table('machine_downtime_logs', 'work_center_id')
                ->label('machine downtime log'),
            DependencyCheck::table('mold_change_logs', 'work_center_id')
                ->label('mould change log'),
            DependencyCheck::table('day_bin_movements', 'work_center_id')
                ->label('day bin movement'),
            DependencyCheck::table('routing_operations', 'work_center_id')
                ->label('routing operation'),
            DependencyCheck::table('material_bags', 'day_bin_work_center_id')
                ->label('material bag'),
            DependencyCheck::table('material_requests', 'work_center_id')
                ->label('material request'),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return ConfigurationDeleteTier::authorisation();
    }
}
