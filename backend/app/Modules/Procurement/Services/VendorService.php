<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VendorService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Vendor::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): Vendor
    {
        return Vendor::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->update($data);

        return $vendor;
    }
}
