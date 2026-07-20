<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShiftService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Shift::query()
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
