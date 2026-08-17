<?php

namespace App\Modules\Core\Exports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The defaults every ordinary kind shares: available, no blocked reason,
 * the configured row cap. A kind overrides what differs — the CEC slot
 * overrides status()/blockedReason(); a heavier report may override
 * rowCap().
 */
abstract class AbstractExportKind implements ExportKind
{
    public function rowCap(): int
    {
        return (int) config('exports.row_cap', 5000);
    }

    public function status(): string
    {
        return self::STATUS_AVAILABLE;
    }

    public function blockedReason(): ?string
    {
        return null;
    }

    /**
     * A request whose user() is the reader — what a JsonResource judges its
     * gating against (`$request->user()`), so a kind can build its rows
     * through the module's resource exactly as the list endpoint does:
     * SomeResource::make($model)->resolve($this->requestFor($reader)).
     * The SAME Authenticatable instance is passed through (not re-fetched),
     * so a token-bound identity — the sync agent's, say — stays what it was.
     */
    protected function requestFor(?Authenticatable $reader): Request
    {
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $reader);

        return $request;
    }
}
