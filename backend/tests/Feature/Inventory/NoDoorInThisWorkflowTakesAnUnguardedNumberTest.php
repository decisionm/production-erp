<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;

/**
 * A NEW NUMERIC FIELD ON THESE DOORS FAILS HERE, NOT IN PRODUCTION.
 *
 * The story this pins is worth the file. `PlainDecimal` was applied to two
 * fields of `StorePurchaseOrderRequest` and the SIBLING THREE LINES BELOW —
 * `lines.*.schedules.*.quantity` — was missed, reached `bcadd`, and answered
 * `1e+21` with a 500 from an ordinary antd InputNumber on the live Purchase
 * Orders page. Its amend twin silently ACCEPTED the same value as a 200.
 * The predicate had drifted five times by then and a test naming DOORS rather
 * than fields is what let the sixth through.
 *
 * So this reads the rule arrays themselves. A field added to any request below
 * without `PlainDecimal`, `integer` or `decimal:` fails immediately, and no
 * probe has to guess the spelling that breaks it.
 *
 * SCOPE IS DELIBERATE AND NARROW. A sweep of the whole application found 114
 * unguarded `numeric` fields across CRM, Finance, HRMS, Payroll, Quality,
 * Sales, Maintenance and Production. That is a real, pre-existing defect class
 * and it is recorded in RELEASE-READINESS.md with the full inventory — but
 * putting a stricter rule on payroll and quality doors nobody has exercised,
 * on the eve of a deploy, would risk refusing work the factory does today.
 * These are the doors this branch's workflow actually owns.
 */
class NoDoorInThisWorkflowTakesAnUnguardedNumberTest extends TestCase
{
    /** The material-flow and procurement chain: PO -> GRN -> lot -> request -> issue -> return. */
    private const DOORS = [
        'Modules/Inventory/Http/Requests/StoreStoreIssueRequest.php',
        'Modules/Inventory/Http/Requests/StoreStoreIssueReturnRequest.php',
        'Modules/Inventory/Http/Requests/StoreStoreIssueBagScanRequest.php',
        'Modules/Inventory/Http/Requests/StoreMaterialRequestRequest.php',
        'Modules/Inventory/Http/Requests/StoreMaterialLotRequest.php',
        'Modules/Inventory/Http/Requests/StoreMaterialCostVersionRequest.php',
        'Modules/Inventory/Http/Requests/StoreStockReceiptRequest.php',
        'Modules/Inventory/Http/Requests/StoreStockIssueRequest.php',
        'Modules/Inventory/Http/Requests/StoreStockTransferRequest.php',
        'Modules/Procurement/Http/Requests/StorePurchaseOrderRequest.php',
        'Modules/Procurement/Http/Requests/AmendPurchaseOrderRequest.php',
        'Modules/Procurement/Http/Requests/StorePurchaseRequisitionRequest.php',
        'Modules/Procurement/Http/Requests/StoreGoodsReceiptRequest.php',
    ];

    public function test_every_numeric_field_on_this_workflow_names_the_shape_it_accepts(): void
    {
        $unguarded = [];

        foreach (self::DOORS as $relative) {
            $path = app_path($relative);
            $this->assertFileExists($path, "the door moved: {$relative}");

            $source = file_get_contents($path);

            preg_match_all("/'([A-Za-z0-9_.*]+)'\s*=>\s*\[([^\]]*)\]/", $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $field, $rules]) {
                if (! str_contains($rules, "'numeric'")) {
                    continue;
                }

                // Any of the three is a real guard: PlainDecimal, an integer
                // rule, or Laravel's own `decimal:` which rejects the same
                // exotic spellings.
                if (str_contains($rules, 'PlainDecimal')
                    || str_contains($rules, "'integer'")
                    || str_contains($rules, 'decimal:')) {
                    continue;
                }

                $unguarded[] = basename($relative).' :: '.$field;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These fields validate with `numeric` alone. `numeric` accepts 1e3, 0x1A, INF and NAN; '
                .'bcmath and the decimal casts accept none of them, so the door answers a 500 or '
                ."stores a number nobody typed. Add `new PlainDecimal`:\n  ".implode("\n  ", $unguarded),
        );
    }
}
