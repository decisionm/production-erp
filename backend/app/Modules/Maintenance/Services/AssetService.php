<?php

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Http\Requests\ListAssetsRequest;
use App\Modules\Maintenance\Models\Asset;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AssetService
{
    /** Name order unless a sort is asked for (ListAssetsRequest::SORTABLE); id desc tiebreaks. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        return ListSort::apply(Asset::query(), $sort, ListAssetsRequest::SORTABLE, 'name')
            ->paginate($perPage);
    }

    public function create(array $data): Asset
    {
        return Asset::create([
            'status' => 'active',
            ...$data,
        ]);
    }

    public function update(Asset $asset, array $data): Asset
    {
        $asset->update($data);

        return $asset;
    }
}
