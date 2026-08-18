<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Procurement\Models\PurchaseOrder;
use RuntimeException;

/**
 * A purchase-order lifecycle action refused by the state machine (Phase 6):
 * a 422 on the wire (DomainException) with a stable `code` so the SPA can
 * branch without parsing the sentence. One constructor per refusal so the
 * words live here, once, and PurchaseOrderService::abilities() — the same
 * predicate the resource's `can` prints — is what decides.
 */
class PurchaseOrderLifecycleException extends RuntimeException implements DomainException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    /** Amend is a Draft's action only; a sent order is what the vendor holds. */
    public static function amendNotDraft(PurchaseOrder $order): self
    {
        return new self(
            "Purchase order {$order->documentNumber()} is {$order->status->value}: amend only in Draft — short-close or cancel it.",
            'amend_not_draft',
        );
    }

    /** Close is for an order the vendor holds (Sent | PartiallyReceived). */
    public static function closeNotOpen(PurchaseOrder $order): self
    {
        $hint = match ($order->status->value) {
            'draft' => 'send or cancel it',
            'closed' => 'it is already closed',
            'cancelled' => 'it is cancelled',
            default => 'it cannot be closed',
        };

        return new self(
            "Purchase order {$order->documentNumber()} is {$order->status->value}: {$hint}.",
            'close_not_open',
        );
    }

    /** Cancel is for an order nothing has arrived against (Draft | Sent, zero receipts). */
    public static function cancelNotOpen(PurchaseOrder $order, int $receipts): self
    {
        $why = $receipts > 0
            ? "{$receipts} goods receipt(s) already stand against it — short-close it instead"
            : "it is {$order->status->value}";

        return new self(
            "Purchase order {$order->documentNumber()} cannot be cancelled: {$why}.",
            'cancel_not_open',
        );
    }

    /** The ERP never rewrites Tally's book (DEC-20260809-003 spirit; DEC-20260812-002). */
    public static function tallyMirror(PurchaseOrder $order): self
    {
        return new self(
            "Purchase order {$order->documentNumber()} is a Tally-originated order: change it in Tally.",
            'tally_mirror',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
