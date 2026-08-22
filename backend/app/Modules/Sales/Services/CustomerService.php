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
     * AND ONE OF THE FIVE COUNTS THE PRESENT WHERE THE RULE ASKS ABOUT THE
     * PAST. DEC-20260817-002 §5: "'ever used' means historical references ...
     * not merely current foreign keys", and where past use cannot be safely
     * proven the system REFUSES. Four of these columns are write-once in
     * practice, so their current value IS their history:
     *
     *   sales_orders / invoices     documents; no update path sets customer_id
     *   quotations.customer_id      derived from the opportunity at creation
     *                               (QuotationService) and never re-set
     *   leads.converted_customer_id written once, at conversion
     *
     * `opportunities.customer_id` is not. Four facts, each checkable:
     *   * UpdateOpportunityRequest allows `customer_id` on update;
     *   * OpportunityService::update() is a plain `$opportunity->update()`;
     *   * Opportunity uses no LogsActivity trait and keeps no previous value;
     *   * the route is index/store/update only — an opportunity row is never
     *     destroyed, and nothing cascades into the table (lead_id and
     *     assigned_to are nullOnDelete, customer_id is restrictOnDelete).
     *
     * Put together: every opportunity that has EVER existed is still a row, but
     * which customer each one pointed at BEFORE is recorded nowhere. So a count
     * of zero today does not mean zero ever — an opportunity reassigned away
     * from this customer leaves no trace at all — and this is the exact case
     * §5 names. The reassignment check below returns the fail-closed verdict
     * (null) whenever any opportunity exists, and 0 only when the table is
     * empty, which is the one state in which "never assigned to anyone" is
     * provable rather than assumed.
     *
     * It is deliberately NOT a guess in the other direction either. The
     * honest narrow answer is available and cheap, so nothing here reasons
     * from `updated_at` or from what "probably" happened: a second-precision
     * timestamp comparison would fail OPEN on a same-second edit, and failing
     * open is the one thing §5 forbids.
     *
     * THE FIX THAT WOULD REMOVE THIS: record the reassignment. Once an
     * opportunity's customer changes are auditable, this check becomes a real
     * count over that history and unused customers become deletable again.
     * That is a factory-visible change to the CRM, so it is not made here.
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
            DependencyCheck::callable(
                // Null = "cannot prove this customer was never on an
                // opportunity", which blocks exactly like a positive count.
                // Zero only when no opportunity has ever existed. Reached
                // through the record's own connection, like every ::table()
                // check above, rather than through CRM's Eloquent model —
                // modules do not touch each other's models (CLAUDE.md).
                fn (Customer $customer): ?int => $customer->getConnection()->table('opportunities')->exists() ? null : 0,
                'opportunity_reassignment',
            )->label('an opportunity later reassigned to another customer'),
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
