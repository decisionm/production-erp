<?php

namespace App\Support\Configuration\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST <master>/{id}/archive` and `POST <master>/{id}/activate`
 * — one FormRequest for every configuration master, because the body is the
 * same everywhere and twelve identical copies is twelve chances to drift.
 *
 * `reason` is OPTIONAL and, today, is not persisted: there is no reason
 * column on any master and `ConfigurationLifecycle` says so in its own
 * docblock. It is validated and accepted anyway because the contract's
 * routes carry it and because the alternative — silently 422-ing a client
 * that sends what the contract describes — is worse than accepting a value
 * the audit trail does not yet keep.
 *
 * Authorisation is the route group's `module:<key>` middleware (create /
 * edit / activate / deactivate follow existing module RBAC, DEC-20260817-002
 * §3); the hard-delete tier is enforced separately, inside the lifecycle.
 */
class ConfigurationReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
