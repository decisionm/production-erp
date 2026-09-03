<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Http\Requests\ListGlAccountsRequest;
use App\Modules\Finance\Models\GLAccount;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GLAccountService
{
    /** Code order when no sort is asked for — what this list always was. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = GLAccount::query();
        ListSort::apply($query, $sort, ListGlAccountsRequest::SORTABLE, 'code');

        return $query->paginate($perPage);
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
