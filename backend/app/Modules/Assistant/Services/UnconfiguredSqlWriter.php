<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;

/**
 * What ASK_ERP_DRIVER resolves to when it names a provider that does not
 * exist. It refuses — but it refuses when someone ASKS a question, not when
 * the container builds the controller.
 *
 * The distinction is the whole point of the class. SqlWriter is a
 * constructor dependency of AskErpService, which is a constructor dependency
 * of AskErpController, so a binding that throws during resolution throws
 * before AskErpController::ask() has entered its try block. The reader would
 * get a 500 and a stack trace where the code promises a 503 and a sentence.
 * Deferring the throw to write() puts it back inside the catch that already
 * knows how to turn it into an answer.
 */
final class UnconfiguredSqlWriter implements SqlWriter
{
    public function __construct(private readonly string $driver) {}

    public function write(SqlRequest $request): SqlDraft
    {
        throw new AskErpException('Ask ERP is not configured on this server.', 503);
    }

    /** The offending value, for a log line or a test that wants to say which. */
    public function driver(): string
    {
        return $this->driver;
    }
}
