<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Enums\MoldStatus;
use App\Modules\Production\Models\Mold;
use App\Support\Configuration\ActiveFlag;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MoldService
{
    use ManagesConfigurationLifecycle;

    /**
     * @param  ?bool  $activeOnly  true = in service only (what a picker may
     *                             consume), false = everything NOT in
     *                             service (retired AND under repair — see
     *                             configurationActiveColumn(): those are two
     *                             different states and neither is "active"),
     *                             null = the whole master.
     */
    public function paginate(int $perPage = 20, ?bool $activeOnly = null): LengthAwarePaginator
    {
        return Mold::query()
            ->when($activeOnly === true, fn ($q) => $q->where('status', MoldStatus::Active->value))
            ->when($activeOnly === false, fn ($q) => $q->where('status', '!=', MoldStatus::Active->value))
            ->orderBy('code')
            ->paginate($perPage);
    }

    public function create(array $data): Mold
    {
        return Mold::create([
            'status' => 'active',
            ...$data,
        ]);
    }

    public function update(Mold $mold, array $data): Mold
    {
        $mold->update($data);

        return $mold;
    }

    protected function configurationLabel(): string
    {
        return 'mould';
    }

    /**
     * A mould's state is a THREE-case enum, not a boolean, so the lifecycle
     * is told which case means in-service and which one archiving writes.
     * `under_repair` is deliberately neither: it is not active (so Activate
     * stays offered) and not retired (so Archive stays offered), and
     * deriving either from the other would strand a mould in a state it
     * could never leave. Declaring this column as a boolean would write
     * `false` into the status column — ActiveFlag refuses that out loud.
     *
     * What a mould under repair may be SELECTED for on the floor is a
     * different question, owned by ActiveSelectionTest and by an open owner
     * question; nothing here answers it.
     */
    protected function configurationActiveColumn(): ActiveFlag|string|null
    {
        return ActiveFlag::status('status', active: MoldStatus::Active, retired: MoldStatus::Retired);
    }

    /**
     * WHAT REFERENCES A MOULD — and every single reference is `ON DELETE
     * SET NULL`, which makes this the most exposed declaration of the five
     * floor masters and is why it is spelled column by column.
     *
     * SET NULL is NOT a backstop. The delete succeeds; the database simply
     * blanks the child's column. And SchemaCascades reads only
     * DELETE_RULE='CASCADE', so the schema backstop that catches a
     * forgotten cascading child says nothing at all here. If a column below
     * were missing from this list the report would come back clear, the row
     * would be destroyed for real, and a mould change log would silently
     * stop saying which mould went in — no error, no trace, on the one
     * question the log exists to answer.
     *
     *   production_configurations.mold_id      SET NULL, child SOFT-DELETES
     *   mold_change_logs.changed_from_mold_id  SET NULL
     *   mold_change_logs.changed_to_mold_id    SET NULL
     *
     * ->includeTrashed() on the configurations check is written BY HAND and
     * is load-bearing. `production_configurations` is the one child in this
     * whole map that soft-deletes, and a soft-deleted configuration is
     * still a physical row whose `mold_id` a delete would blank. Under
     * WorkCenter the same table is reached by a CASCADE column, so
     * ->cascadeSide() covers the trashed rows automatically; here the
     * column is SET NULL, nothing implies it, and forgetting it would lose
     * exactly the withdrawn configuration a past shift's prefill has to
     * stay explainable by.
     *
     * The two mould-change columns are ONE check, OR-ed: a mould that came
     * out is referenced as surely as one that went in.
     *
     * A mould carries no Tally identity of its own (no tally_* column on
     * `molds`) and appears as no voucher's syncable, so there is no Tally
     * reference to declare and none to preserve. Nothing here reads or
     * writes Tally.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('production_configurations', 'mold_id')
                ->label('production configuration')->includeTrashed(),
            DependencyCheck::table('mold_change_logs', ['changed_from_mold_id', 'changed_to_mold_id'])
                ->label('mould change log'),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return ConfigurationDeleteTier::authorisation();
    }
}
