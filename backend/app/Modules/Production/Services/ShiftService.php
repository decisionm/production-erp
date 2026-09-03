<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Http\Requests\ListShiftsRequest;
use App\Modules\Production\Models\Shift;
use App\Modules\TallySync\Services\TallySyncLinkService;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use App\Support\Lists\ListSort;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class ShiftService
{
    use ManagesConfigurationLifecycle;

    /**
     * @param  ?bool  $activeOnly  true = active only (the operational
     *                             contract — what a picker, the dashboard
     *                             rail or any new-transaction surface may
     *                             consume), false = inactive only, null =
     *                             everything (admin and history views).
     *                             Live still carries the deactivated
     *                             Morning/Afternoon/Night rows the rename
     *                             era left behind; they must stay resolvable
     *                             for old records and must never surface on
     *                             an operational screen.
     */
    public function paginate(int $perPage = 20, ?bool $activeOnly = null, ?string $sort = null): LengthAwarePaginator
    {
        $query = Shift::query()
            ->when($activeOnly !== null, fn ($q) => $q->where('is_active', $activeOnly));

        // The master's own order is the clock's; a sort asked for replaces it.
        return ListSort::apply($query, $sort, ListShiftsRequest::SORTABLE, 'start_time')->paginate($perPage);
    }

    public function create(array $data): Shift
    {
        return Shift::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(Shift $shift, array $data): Shift
    {
        $shift->update($data);

        return $shift;
    }

    protected function configurationLabel(): string
    {
        return 'shift';
    }

    /**
     * WHAT REFERENCES A SHIFT — six foreign keys, one that quietly blanks
     * itself, and one reference that is not a foreign key at all.
     *
     * RESTRICT — the database would refuse as well; declared so the answer
     * names what is in the way rather than throwing an FK error:
     *   shift_production_entries · shift_summaries · machine_downtime_logs
     *   mold_change_logs · power_interruption_logs · shift_stock_counts
     *
     * SET NULL — no backstop of any kind (the delete succeeds and the
     * column is blanked; SchemaCascades reads only CASCADE):
     *   material_requests.shift_id   (added 19-Aug, after the audit)
     *
     * NOT A FOREIGN KEY, AND THE ONE WITH NOTHING BEHIND IT — THE TALLY
     * VOUCHER. Under shift voucher granularity (DEC-20260807-010) a day's
     * production posts as ONE Stock Journal per (production_date, shift),
     * and that voucher names the SHIFT as its syncable: `syncable_type` =
     * the Shift morph class, `syncable_id` = the shift's id, voucher number
     * SJ-{Ymd}-S{shift_id}. It is a polymorphic pair of plain columns —
     * no foreign key, so no database backstop, and no cascade, so no schema
     * backstop either. Nothing but this check stands between deleting a
     * shift and a posted Stock Journal in Tally's books whose ERP-side
     * identity no longer resolves.
     *
     * Both columns are matched, never `syncable_id` alone: no morph map is
     * enforced in this repo, so `syncable_type` holds a class name and an
     * id-only match would count some other document's voucher that happens
     * to share the number.
     *
     * This BLOCKS THE DELETE and does nothing else. Archiving a shift still
     * touches no voucher and no Tally field — DEC-20260817-002 §4: the
     * historical Tally identity and mapping are preserved exactly as
     * posted.
     *
     * NOT declared, deliberately: this shift's own activity_log trail —
     * declaring a master's audit trail as a dependency would make every
     * audited master permanently undeletable.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('shift_production_entries', 'shift_id')
                ->label('production batch'),
            DependencyCheck::table('shift_summaries', 'shift_id')
                ->label('shift summary'),
            DependencyCheck::table('machine_downtime_logs', 'shift_id')
                ->label('machine downtime log'),
            DependencyCheck::table('mold_change_logs', 'shift_id')
                ->label('mould change log'),
            DependencyCheck::table('power_interruption_logs', 'shift_id')
                ->label('power interruption log'),
            DependencyCheck::table('shift_stock_counts', 'shift_id')
                ->label('shift stock count'),
            DependencyCheck::table('material_requests', 'shift_id')
                ->label('material request'),
            DependencyCheck::callable(
                // Cross-module read through TallySync's own service, never
                // through its models (CLAUDE.md), and read-only: it counts,
                // it does not touch a voucher.
                fn (Model $shift): int => app(TallySyncLinkService::class)
                    ->countFor((new Shift)->getMorphClass(), (int) $shift->getKey()),
                'tally_shift_voucher',
            )->label('Tally shift voucher'),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return ConfigurationDeleteTier::authorisation();
    }
}
