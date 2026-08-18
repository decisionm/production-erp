<?php

namespace App\Modules\Core\Exports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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

    /**
     * A list request's rules minus the pagination controls: an export is the
     * WHOLE list, so `page` / `per_page` would be a form control that does
     * nothing. Everything else is validated exactly as the list validates it.
     *
     * @return array<string, mixed>
     */
    protected function listRules(FormRequest $request, array $drop = ['page', 'per_page']): array
    {
        return Arr::except($request->rules(), $drop);
    }

    /**
     * The resource as the wire carries it, for THIS reader: resolve() for
     * the reader's request, then every nested resource (a customer, the
     * lines, an item) resolved against the SAME request — what json_encode
     * does with the container's request on the list endpoint, done here with
     * the reader's, so a nested gated key is judged for the same person as
     * the top-level one and a dotted column key reads a plain array.
     *
     * @return array<string, mixed>
     */
    protected function wire(JsonResource $resource, Request $request): array
    {
        return $this->resolveDeep($resource->resolve($request), $request);
    }

    private function resolveDeep(mixed $value, Request $request): mixed
    {
        if ($value instanceof JsonResource) {
            return $this->resolveDeep($value->resolve($request), $request);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->resolveDeep($item, $request);
            }
        }

        return $value;
    }
}
