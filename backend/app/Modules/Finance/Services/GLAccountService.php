<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\GLAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GLAccountService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return GLAccount::query()
            ->orderBy('code')
            ->paginate($perPage);
    }

    public function create(array $data): GLAccount
    {
        return GLAccount::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(GLAccount $account, array $data): GLAccount
    {
        $account->update($data);

        return $account;
    }
}
