<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Mold;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MoldService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Mold::query()
            ->orderBy('code')
            ->paginate($perPage);
    }

    public function create(array $data): Mold
    {
        return Mold::create([
            'status' => 'active',
            ...$data,
        ]);
    }

    public function update(Mold $mold, array $data): Mold
    {
        $mold->update($data);

        return $mold;
    }
}
