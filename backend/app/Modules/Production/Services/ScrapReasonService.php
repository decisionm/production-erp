<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ScrapReason;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ScrapReasonService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return ScrapReason::query()
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
}
