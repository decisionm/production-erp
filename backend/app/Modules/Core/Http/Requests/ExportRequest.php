<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Exports\ExportKind;
use App\Modules\Core\Exports\ExportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /exports/{kind} — the ONE request class for every kind. The kind is
 * resolved from the route (404 when no such kind), the reader is judged
 * against the kind's permissionAny() (403 — the same predicate the
 * catalogue uses, ExportRegistry::permits), and the body is validated
 * against the kind's filterRules(), which are the module's own List…Request
 * rules — so an export can never accept a filter its list would refuse,
 * nor refuse one its list accepts.
 */
class ExportRequest extends FormRequest
{
    private ?ExportKind $kind = null;

    /** The kind named on the route. 404 (not 422, not 403) when it does not exist. */
    public function kind(): ExportKind
    {
        if ($this->kind !== null) {
            return $this->kind;
        }

        $key = (string) $this->route('kind');
        $kind = app(ExportRegistry::class)->find($key);

        if ($kind === null) {
            throw new NotFoundHttpException("No export kind '{$key}'.");
        }

        return $this->kind = $kind;
    }

    public function authorize(): bool
    {
        return app(ExportRegistry::class)->permits($this->user(), $this->kind());
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException("You don't have permission to access this feature.");
    }

    /**
     * What a hand-typed body most plausibly means, normalised the way the
     * module's List…Request normalises its query string — from the RULES,
     * not from a per-kind copy of that logic: a scalar where the rules say
     * `array` is wrapped ("failed" → ["failed"]), and a string where they
     * say `boolean` is read as one ("true" → true). Anything else is left
     * for validation to judge.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach ($this->kind()->filterRules() as $name => $ruleSet) {
            if (str_contains((string) $name, '.') || ! $this->has($name)) {
                continue;
            }

            $rules = is_string($ruleSet) ? explode('|', $ruleSet) : (array) $ruleSet;
            $value = $this->input($name);

            if (in_array('array', $rules, true) && $value !== null && ! is_array($value)) {
                $normalised[$name] = [$value];
            } elseif (in_array('boolean', $rules, true) && is_string($value)) {
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool !== null) {
                    $normalised[$name] = $bool;
                }
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function rules(): array
    {
        $kind = $this->kind();

        // A blocked kind has ONE answer — its reason (409). Its documented
        // filters are for the catalogue's form, not a gate a body must pass
        // before hearing that the kind is blocked.
        if ($kind->status() === ExportKind::STATUS_BLOCKED) {
            return [];
        }

        return $kind->filterRules();
    }
}
