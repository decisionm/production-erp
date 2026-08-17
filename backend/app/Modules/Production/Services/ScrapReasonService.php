<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ScrapReason;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ScrapReasonService
{
    use ManagesConfigurationLifecycle;

    /**
     * @param  ?bool  $activeOnly  true = active only (what a completion or
     *                             handover screen may offer), false =
     *                             withdrawn only, null = the whole master.
     */
    public function paginate(int $perPage = 20, ?bool $activeOnly = null): LengthAwarePaginator
    {
        return ScrapReason::query()
            ->when($activeOnly !== null, fn ($q) => $q->where('is_active', $activeOnly))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): ScrapReason
    {
        return ScrapReason::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(ScrapReason $scrapReason, array $data): ScrapReason
    {
        $scrapReason->update($data);

        return $scrapReason;
    }

    protected function configurationLabel(): string
    {
        return 'scrap reason';
    }

    /**
     * WHAT REFERENCES A SCRAP REASON — three columns, two of which blank
     * themselves.
     *
     * SET NULL — the delete SUCCEEDS and the child's column is quietly
     * emptied. No database backstop, and no schema backstop either
     * (SchemaCascades reads only DELETE_RULE='CASCADE'), so these two
     * declarations are the whole guard. A completed batch that recorded WHY
     * material was scrapped would simply stop saying why:
     *   shift_production_entries.scrap_reason_id
     *   shift_scraps.scrap_reason_id
     *
     * RESTRICT — the database would refuse too; declared so the refusal
     * names the rows instead of surfacing a foreign-key error:
     *   work_order_scraps.scrap_reason_id
     *
     * No cascading child, so the schema backstop has nothing to add here —
     * which is exactly why the list above is written from the schema rather
     * than from memory.
     *
     * A scrap reason carries no Tally identity (no tally_* column on
     * `scrap_reasons`) and names no voucher: the scrapped QUANTITY reaches
     * Tally on the production voucher, the reason never does. Nothing here
     * reads or writes Tally.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('shift_production_entries', 'scrap_reason_id')
                ->label('production batch'),
            DependencyCheck::table('shift_scraps', 'scrap_reason_id')
                ->label('scrap line'),
            DependencyCheck::table('work_order_scraps', 'scrap_reason_id')
                ->label('work order scrap line'),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return ConfigurationDeleteTier::authorisation();
    }
}
