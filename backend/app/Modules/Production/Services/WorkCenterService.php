<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\WorkCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkCenterService
{
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
}
