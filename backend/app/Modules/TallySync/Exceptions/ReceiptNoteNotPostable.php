<?php

namespace App\Modules\TallySync\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * TallySyncService::enqueueGoodsReceiptNote() REFUSED to stage a Tally
 * 'Receipt Note' voucher.
 *
 * TWO families of refusal share this exception, and the reasons list keeps
 * them apart:
 *
 * THE FLAG. The factory does not use Tally Receipt Notes (DEC-20260830-001),
 * so tally-sync.receipt_notes_enabled is OFF as the DECIDED state — no
 * longer a fail-closed reading of an open question. The event listener
 * (TallySyncEventServiceProvider) checks this config itself and never calls
 * enqueueGoodsReceiptNote() while it is off, so this guard is not that
 * listener's path — it is the SECOND lock: the service method refuses on
 * its own, so a future or direct caller (a new controller action, a console
 * command, anything that is not today's one gated listener) cannot create a
 * Receipt Note queue row while the flag is off either. pending()
 * withholding an already-queued row is the THIRD lock, for rows that exist
 * despite this one — see its own docblock.
 *
 * THE IDENTITIES. Even with the flag on, a Tally NAME the voucher needs may
 * not be recorded in this ERP — and DEC-20260812-002's rule for the PO path
 * holds here identically: refuse rather than guess. The 28-Aug rehearsal
 * proved both halves live: a Receipt Note failed at the agent on an item
 * name Tally did not carry, and another reached an obsolete Tally company —
 * each a failure the cloud could have named BEFORE queueing. Reason codes
 * (mirroring PurchaseOrderNotPostable, shared with the GRN's staging
 * record):
 *
 *   receipt_notes_disabled        the flag (tally-sync.receipt_notes_enabled) is off
 *   allowed_company_unconfigured  the flag is on but tally-sync.receipt_notes_allowed_company is blank
 *   party_unmapped                the order's vendor has no tally_ledger_name
 *   item_unmapped                 a line's item is not Tally-sourced (no tally_stock_item_guid), or is a local fixture
 *   godown_unresolved             no godown Tally knows can stand in for the receiving warehouse
 *   no_lines                      the receipt has no lines
 *
 * FC-06 on the reasons: they are shown to whoever reads the goods receipt,
 * so a `detail` never carries a rate, an amount, the vendor's name or its
 * GSTIN — an item id + name, a vendor id, a warehouse name, and nothing
 * else.
 */
class ReceiptNoteNotPostable extends RuntimeException implements DomainException
{
    /**
     * @param  list<array{code: string, detail: string}>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(
            'Receipt Note not staged for Tally: '.implode('; ', array_map(
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

    public static function disabled(): self
    {
        return new self([[
            'code' => 'receipt_notes_disabled',
            'detail' => 'Receipt Note posting to Tally is disabled (tally-sync.receipt_notes_enabled = false — '
                .'the factory does not use Tally Receipt Notes; DEC-20260830-001).',
        ]]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_map(fn (array $reason) => $reason['code'], $this->reasons));
    }
}
