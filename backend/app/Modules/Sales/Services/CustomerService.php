<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerService
{
    use ManagesConfigurationLifecycle;

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

    protected function configurationLabel(): string
    {
        return 'customer';
    }

    /**
     * WHAT REFERENCES A CUSTOMER — five columns, and they do NOT all behave
     * the same way. Written from the schema, because this list IS the guard.
     *
     * RESTRICT — the database would refuse the delete too; declared so the
     * refusal counts the rows and names them in business words rather than
     * surfacing a foreign-key error:
     *   sales_orders.customer_id    (2026_07_18_163622)
     *   invoices.customer_id        (2026_07_18_163626)
     *   opportunities.customer_id   (2026_07_18_170753)
     *   quotations.customer_id      (2026_07_18_170754)
     *
     * SET NULL — and this one is the reason the list is not just "the four
     * obvious ones". leads.converted_customer_id is nullOnDelete
     * (2026_07_18_170752), so the database would let the delete SUCCEED and
     * quietly blank the column. A lead that records WHICH customer it became
     * would simply stop saying so, with no error anywhere. There is no schema
     * backstop for that — SchemaCascades reads only DELETE_RULE='CASCADE' —
     * so this declaration is the whole guard:
     *   leads.converted_customer_id
     *
     * A customer carries no Tally identity the ERP owns. DEC-20260809-003
     * records that real sales are invoiced directly in Tally, so the ERP's
     * customer master is not the authoritative book and archiving one mutates
     * nothing there (DEC-20260817-002 rule 4).
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('sales_orders', 'customer_id')
                ->label('sales order'),
            DependencyCheck::table('invoices', 'customer_id')
                ->label('invoice'),
            DependencyCheck::table('opportunities', 'customer_id')
                ->label('opportunity'),
            DependencyCheck::table('quotations', 'customer_id')
                ->label('quotation'),
            DependencyCheck::table('leads', 'converted_customer_id')
                ->label('converted lead'),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }
}
