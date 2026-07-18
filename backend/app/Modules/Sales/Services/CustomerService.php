<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Customer::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): Customer
    {
        return Customer::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer;
    }
}
