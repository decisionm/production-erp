<?php

namespace App\Modules\Sales\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * A sales-order lifecycle action refused by the order's own state: a 422 on
 * the wire (DomainException) with a stable `code` so the SPA can branch
 * without parsing the sentence — the same shape Procurement's
 * PurchaseOrderLifecycleException already puts on the wire.
 *
 * One named constructor per refusal, so the words live here once and
 * SalesOrder::isEditable() — the SAME predicate the resource's `can_edit`
 * prints — is what decides.
 */
class SalesOrderLifecycleException extends RuntimeException implements DomainException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    /**
     * The expected date and notes are the desk's own fields on an order the
     * factory has not started working through yet. Once something has left
     * against it (partially_delivered), or it is finished or dead
     * (completed / cancelled), the promise it carries is history and is not
     * rewritten.
     */
    public static function notEditable(SalesOrder $order): self
    {
        return new self(
            "Sales order {$order->documentNumber()} is {$order->status->value}: the expected date and notes can be changed only while it is draft or confirmed.",
            'not_editable',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
