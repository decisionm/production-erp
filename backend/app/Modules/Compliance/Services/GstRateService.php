<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Models\GstRate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GstRateService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return GstRate::query()
            ->orderBy('hsn_sac_code')
            ->paginate($perPage);
    }

    public function create(array $data): GstRate
    {
        return GstRate::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(GstRate $rate, array $data): GstRate
    {
        $rate->update($data);

        return $rate;
    }
}
