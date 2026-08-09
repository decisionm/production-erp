<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShiftService
{
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
    public function paginate(int $perPage = 20, ?bool $activeOnly = null): LengthAwarePaginator
    {
        return Shift::query()
            ->when($activeOnly !== null, fn ($q) => $q->where('is_active', $activeOnly))
            ->orderBy('start_time')
            ->paginate($perPage);
    }

    public function create(array $data): Shift
    {
        return Shift::create([
            'is_active' => true,
            ...$data,
        ]);
    }
}
