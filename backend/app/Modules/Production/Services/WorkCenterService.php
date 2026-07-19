<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\WorkCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkCenterService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return WorkCenter::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): WorkCenter
    {
        return WorkCenter::create([
            'is_active' => true,
            ...$data,
        ]);
    }
}
