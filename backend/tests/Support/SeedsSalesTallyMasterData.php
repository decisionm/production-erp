<?php

namespace Tests\Support;

use App\Modules\Compliance\Models\GstRate;
use App\Modules\Compliance\Models\GstRegistration;
use App\Modules\Compliance\Services\GstStateCodes;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Services\TallyLedgerMappingService;

/**
 * THE MASTER DATA A GST-CORRECT SALES VOUCHER NEEDS, for tests that use a Sales
 * voucher as their FIXTURE VEHICLE rather than as their subject.
 *
 * WHY THIS EXISTS. `SalesVoucherPayload` refuses to stage a Sales voucher unless
 * the company's GST registration, the customer's Tally ledger name and state,
 * an HSN and rate per item, the sales and tax ledger mappings, and a single
 * resolvable godown are ALL present — because without them the voucher it would
 * build carries no tax and debits the party the pre-tax total, which is the
 * defect this whole change exists to remove.
 *
 * A great many tests across the TallySync suite (query filters, the presenter,
 * the link service, the per-type lifecycles) issue an invoice merely to get a
 * row into the queue, and would otherwise now stage nothing. Calling this in
 * their setUp keeps their original meaning — they still test what they always
 * tested — without each one re-deciding what a correct Sales voucher needs.
 *
 * It is DEFENSIVE by design: it never overwrites a value a test set on purpose,
 * and it creates a Tally-linked warehouse only when the test has not created one
 * itself (two would make the godown ambiguous, and TallyGodownResolver would
 * correctly resolve nothing).
 *
 * The figures mirror the real factory: Puducherry (state 34), GSTIN
 * 34AAWCS7109K1ZQ, HSN 39233090 at 18%, ledgers named exactly as the factory's
 * own vouchers name them.
 */
trait SeedsSalesTallyMasterData
{
    protected function seedSalesTallyMasterData(): void
    {
        if (! GstRegistration::query()->where('is_primary', true)->exists()) {
            GstRegistration::create([
                'gstin' => '34AAWCS7109K1ZQ',
                'state_code' => '34',
                'state_name' => 'Puducherry',
                'is_primary' => true,
                'is_active' => true,
            ]);
        }

        // FILL THE GAPS, NEVER OVERWRITE. setMany() would clobber a mapping a
        // test set on purpose — and a test that maps its own ledger name is
        // usually testing exactly that name. Only roles with no mapping yet are
        // given the factory's real names.
        $ledgers = app(TallyLedgerMappingService::class);
        $defaults = [
            TallyLedgerRole::SalesLocal->value => 'Local Sales Taxable',
            TallyLedgerRole::SalesInterstate->value => 'Interstate Sales Taxable',
            TallyLedgerRole::Cgst->value => 'CGST',
            TallyLedgerRole::Sgst->value => 'SGST',
            TallyLedgerRole::Igst->value => 'IGST',
            TallyLedgerRole::RoundOff->value => 'Rounding Off',
        ];
        $missing = [];
        foreach ($defaults as $role => $name) {
            if (blank($ledgers->get(TallyLedgerRole::from($role)))) {
                $missing[$role] = $name;
            }
        }
        if ($missing !== []) {
            $ledgers->setMany($missing);
        }

        // Exactly one Tally-linked warehouse, and only if the test made none —
        // a second would make the godown genuinely ambiguous.
        if (! Warehouse::query()->whereNotNull('tally_guid')->exists()) {
            Warehouse::create(['code' => 'FG-TALLY', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'tally_guid' => 'gd-fg-test']);
        }

        $this->completeSalesTallyMastersOnExistingRows();
    }

    /**
     * Give every item an HSN with a rate behind it, and every customer a state
     * and a Tally ledger name — but only where the test left them blank.
     */
    /**
     * STAMP QUALITY'S DISPATCH SIGN-OFF ON A LINE, for tests whose subject is
     * something OTHER than the quality gate.
     *
     * Dispatch is gated on internal quality approval (DEC-20260831-006), so a
     * great many fixtures that dispatch stock in order to test something else —
     * the stock ledger, a Tally voucher, a carton's trace — would otherwise all
     * fail with the same 422. This says "assume Quality signed this off", which
     * is exactly what those tests were always assuming.
     *
     * It writes the stamp DIRECTLY rather than calling
     * DispatchQualityApprovalService, deliberately: the real service requires
     * the line to be fully HELD first, and most of these fixtures dispatch
     * without ever taking a hold. Tests whose subject IS the gate must use the
     * service (or the endpoint) so the preconditions are exercised —
     * DispatchQualityGateTest does.
     *
     * @param  string|null  $quantity  what Quality signed for; defaults to the whole ordered quantity
     */
    protected function approveQualityForDispatch(int|SalesOrderLine $line, ?string $quantity = null): SalesOrderLine
    {
        $row = $line instanceof SalesOrderLine
            ? SalesOrderLine::query()->findOrFail($line->getKey())
            : SalesOrderLine::query()->findOrFail($line);

        $row->forceFill([
            'quality_approved_at' => now(),
            'quality_approved_by' => null,
            'quality_approved_quantity' => $quantity ?? (string) $row->quantity,
            'quality_approval_note' => 'Seeded for a test whose subject is not the quality gate.',
        ])->save();

        return $row->fresh();
    }

    /** Every live line of an order, signed off — the common case in a fixture. */
    protected function approveQualityForOrder(int $salesOrderId): void
    {
        foreach (SalesOrderLine::query()->where('sales_order_id', $salesOrderId)->get() as $line) {
            $this->approveQualityForDispatch($line);
        }
    }

    protected function completeSalesTallyMastersOnExistingRows(): void
    {
        Item::query()->whereNull('hsn_sac_code')->update(['hsn_sac_code' => '39233090']);

        foreach (Item::query()->whereNotNull('hsn_sac_code')->pluck('hsn_sac_code')->unique() as $hsn) {
            GstRate::query()->firstOrCreate(
                ['hsn_sac_code' => $hsn],
                ['description' => 'Seeded for tests', 'rate_percent' => '18.00', 'is_active' => true],
            );
        }

        foreach (Customer::query()->get() as $customer) {
            $updates = [];
            if (blank($customer->state_code)) {
                // DERIVE FROM THE GSTIN WHEN THERE IS ONE. The first two
                // characters of a GSTIN ARE the state code, and this is the same
                // derivation the vendor import already uses. Overriding it with a
                // fixed code would put a fixture's 34xxxxx customer in Tamil Nadu
                // and silently flip its sale from local to interstate — changing
                // which tax ledgers the voucher names.
                $updates['state_code'] = GstStateCodes::fromGstin($customer->gstin) ?? '33';
            }
            if (blank($customer->gstin)) {
                // Only when there is none to derive from: 33 (Tamil Nadu) against
                // the company's 34 is an INTERSTATE sale, the majority shape in
                // the factory's own export (45 of 54).
                $updates['gstin'] = '33ABVFS0946B1Z5';
            }
            if ($updates !== []) {
                $customer->forceFill($updates)->save();
            }
            if (blank($customer->tally_ledger_name)) {
                $customer->forceFill(['tally_ledger_name' => $customer->name])->save();
            }
        }
    }
}
