<?php

namespace App\Modules\TallySync\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * TallySyncService::enqueuePurchaseOrder() REFUSED to stage the voucher —
 * a Tally NAME it needs is not recorded in this ERP, and DEC-20260812-002
 * says refuse rather than guess: "the ledger NAMES are never invented".
 * The reasons are structured so the caller can RECORD them on the order
 * (PurchaseOrderService::recordTallyStaging — the listener's job) instead
 * of letting them escape out of send(): a purchase order is sent to its
 * vendor whether or not the ERP can also stage it for Tally.
 *
 * Reason codes (the whole set — WS-C ↔ WS-A contract):
 *
 *   purchase_orders_disabled   the flag (tally-sync.purchase_orders_enabled) is off
 *   party_unmapped             the vendor has no tally_ledger_name
 *   item_unmapped              a line's item is not Tally-sourced (no tally_stock_item_guid)
 *   purchase_ledger_unmapped   TallyLedgerRole::Purchase has no mapping
 *   godown_unresolved          no single Tally godown to allocate the order to
 *   no_lines                   the order has no lines
 *
 * FC-06 on the reasons: they are shown to whoever reads the purchase order,
 * so a `detail` never carries a rate, an amount, the vendor's name or its
 * GSTIN — an item id + name, a vendor id, a role name, and nothing else.
 */
class PurchaseOrderNotPostable extends RuntimeException implements DomainException
{
    /**
     * @param  list<array{code: string, detail: string}>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(
            'Purchase order not staged for Tally: '.implode('; ', array_map(
                fn (array $reason) => "{$reason['code']} — {$reason['detail']}",
                $reasons,
            )),
        );
    }

    /**
     * @param  list<array{code: string, detail: string}>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new self($reasons);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_map(fn (array $reason) => $reason['code'], $this->reasons));
    }
}
