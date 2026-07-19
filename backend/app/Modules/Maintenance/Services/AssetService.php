<?php

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Models\Asset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AssetService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Asset::query()
            ->orderBy('name')
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
