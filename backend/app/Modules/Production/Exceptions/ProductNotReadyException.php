<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * Raised when Start Batch is attempted for a product whose masters are
 * incomplete (ProductReadinessService). Carries the full blocking list, not
 * just a sentence: the supervisor needs to see every missing field at once,
 * and whoever fixes the master needs the field names.
 */
class ProductNotReadyException extends RuntimeException implements DomainException
{
    /** @param list<array{code: string, label: string, detail: string}> $blocking */
    public function __construct(
        string $message,
        private readonly array $blocking,
    ) {
        parent::__construct($message);
    }

    /** @param array{summary: ?string, blocking: list<array{code: string, label: string, detail: string}>} $readiness */
    public static function make(array $readiness): self
    {
        return new self(
            $readiness['summary'] ?? 'This product is not production-ready.',
            $readiness['blocking'],
        );
    }

    public function errorCode(): string
    {
        return 'product_not_ready';
    }

    /**
     * Extra 422 body keys — see bootstrap/app.php's DomainException render.
     *
     * @return array{blocking: list<array{code: string, label: string, detail: string}>}
     */
    public function payload(): array
    {
        return ['blocking' => $this->blocking];
    }
}
