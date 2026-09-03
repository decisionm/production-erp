<?php

namespace App\Modules\Assistant\Exceptions;

use RuntimeException;

/**
 * Anything Ask ERP could not do for the user, with the HTTP status the
 * controller answers with. The message is shown as written.
 */
class AskErpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
