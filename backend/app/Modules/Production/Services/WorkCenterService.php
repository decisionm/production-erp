<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\WorkCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkCenterService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return WorkCenter::query()
            // Business sequence first (Machine 10 after Machine 9), rows
            // without one last, name as the tie-break.
            ->orderByRaw('display_sequence IS NULL')
            ->orderBy('display_sequence')
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

    public function update(WorkCenter $workCenter, array $data): WorkCenter
    {
        $workCenter->update($data);

        return $workCenter;
    }
}
