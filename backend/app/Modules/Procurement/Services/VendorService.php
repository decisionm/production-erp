<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Models\Vendor;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VendorService
{
    use ManagesConfigurationLifecycle;

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

    protected function configurationLabel(): string
    {
        return 'vendor';
    }

    /**
     * WHAT REFERENCES A VENDOR — two columns, both RESTRICT.
     *
     * Written from the schema, not from memory, because this list IS the
     * guard: anything omitted here is something the refusal will not name.
     *
     * RESTRICT — the database would refuse the delete too; declared so the
     * refusal counts the rows and names them in business words instead of
     * surfacing a foreign-key error:
     *   purchase_orders.vendor_id      (2026_07_18_160519, restrictOnDelete)
     *   subcontract_orders.vendor_id   (2026_07_19_071003, restrictOnDelete)
     *
     * NOT a dependency, and worth writing down because it reads like one:
     * goods_receipt_notes has NO vendor_id. A GRN reaches its vendor through
     * purchase_order_id, so purchase_orders above already covers every
     * received delivery. Adding a GRN check here would double-count.
     *
     * A vendor carries no Tally identity the ERP owns: tally_ledger_name is a
     * NAME Accounts types in, never a guid the ERP resolves, and no voucher is
     * keyed on it — so archiving a vendor mutates nothing in Tally
     * (DEC-20260817-002 rule 4).
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('purchase_orders', 'vendor_id')
                ->label('purchase order'),
            DependencyCheck::table('subcontract_orders', 'vendor_id')
                ->label('subcontract order'),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }
}
