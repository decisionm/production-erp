<?php

namespace Tests\Concerns;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\DayBinMovement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Seed the machine-scoped day-bin ledger the way its own writers do.
 *
 * The three HTTP doors these helpers replace — POST day-bin/load,
 * day-bin/return and day-bin/count — were retired in Phase 7.5 (WS-C).
 * None had a UI caller: day-bin/load was the machine-stamped load path
 * DEC-20260807-006 retired, and DEC-20260807-007 records that the bin is
 * never weighed, so no count will ever be taken. DEC-20260817-001 then
 * removed the Day Bin from the factory's logical inventory locations.
 *
 * The WRITERS stayed, exactly as `day-bin/load`'s own writer stayed when
 * the Bin Bay page went (commit 57c3726) — `day_bin_movements` keeps every
 * historical row and the readers listed in
 * docs/engineering/AUDIT-MATERIAL-FLOW-2026-08-17.md §3 keep serving it.
 * These helpers are how a test now stands up that history, and every guard
 * the endpoints enforced is enforced here too: the refusals live in
 * TraceabilityService and DayBinLedgerService, never in the removed
 * FormRequests (whose `authorize()` was `true` — the permission sat on the
 * route, and the live scan door `day-bin/load-bag` still carries it).
 */
trait RecordsDayBinHistory
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function loadDayBin(array $payload, ?int $userId = null): DayBinMovement
    {
        return app(TraceabilityService::class)->loadBagToDayBin($payload, $userId ?? Auth::id());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function returnDayBin(array $payload, ?int $userId = null): DayBinMovement
    {
        return app(TraceabilityService::class)->returnFromDayBin($payload, $userId ?? Auth::id());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function countDayBin(array $payload, ?int $userId = null): DayBinMovement
    {
        return app(TraceabilityService::class)->recordCount($payload, $userId ?? Auth::id());
    }

    /**
     * Run a day-bin write that must be REFUSED and hand back the refusal's
     * message, so a test can assert the factory's own wording rather than
     * merely that something threw.
     */
    protected function refusedDayBinWrite(callable $write): string
    {
        try {
            $write();
        } catch (\Throwable $refusal) {
            // A refusal, not a crash: the write must be turned away by a
            // guard the codebase owns (a DomainException, a validation
            // failure, or an identifier that resolves to nothing), never by
            // an incidental error that merely happens to stop it.
            $this->assertTrue(
                $refusal instanceof DomainException
                    || $refusal instanceof ValidationException
                    || $refusal instanceof ModelNotFoundException,
                'A day-bin write must be refused deliberately, not crash — got '.$refusal::class.'.',
            );

            return $refusal->getMessage();
        }

        $this->fail('Expected the day-bin write to be refused, but it succeeded.');
    }
}
