<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Http\Requests\ListGstRatesRequest;
use App\Modules\Compliance\Models\GstRate;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GstRateService
{
    /** HSN/SAC order when no sort is asked for — what this list always was. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = GstRate::query();
        ListSort::apply($query, $sort, ListGstRatesRequest::SORTABLE, 'hsn_sac_code');

        return $query->paginate($perPage);
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
