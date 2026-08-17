<?php

namespace App\Modules\Core\Exports;

use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;

/**
 * Every ExportKind the Center offers, by key. A singleton
 * (ExportServiceProvider) filled from config('exports.kinds') — the ONE
 * place a kind is listed — plus register() for a test that wants a stub.
 *
 * The permission predicate lives here ONCE (permits) and is what both the
 * catalogue and ExportRequest::authorize judge by, so a kind a reader
 * cannot see is also a kind they cannot run, and vice versa.
 */
final class ExportRegistry
{
    /** @var array<string, ExportKind> keyed by kind key, in registration order */
    private array $kinds = [];

    public function register(ExportKind $kind): void
    {
        $key = $kind->key();

        if (isset($this->kinds[$key])) {
            throw new LogicException(sprintf(
                'Export kind "%s" is registered twice (%s and %s).',
                $key,
                $this->kinds[$key]::class,
                $kind::class,
            ));
        }

        $this->kinds[$key] = $kind;
    }

    /** @return list<ExportKind> */
    public function all(): array
    {
        return array_values($this->kinds);
    }

    public function find(string $key): ?ExportKind
    {
        return $this->kinds[$key] ?? null;
    }

    /**
     * May this reader see — and run — this kind? Any of permissionAny().
     * A reader that cannot answer hasAnyPermission (no user, a non-User
     * Authenticatable) may not.
     */
    public function permits(?Authenticatable $reader, ExportKind $kind): bool
    {
        if ($reader === null || ! method_exists($reader, 'hasAnyPermission')) {
            return false;
        }

        return (bool) $reader->hasAnyPermission($kind->permissionAny());
    }

    /**
     * The kinds this reader may run, in registration order — each with its
     * status (a BLOCKED kind IS listed, with its reason, so the Center can
     * show the slot honestly disabled), its row cap, and a filter schema
     * derived from its rules (FilterSchema) for the client's form.
     *
     * @return list<array{key: string, label: string, module: string, status: string, blocked_reason: ?string, row_cap: int, filters: list<array<string, mixed>>}>
     */
    public function catalogue(?Authenticatable $reader): array
    {
        $rows = [];

        foreach ($this->kinds as $kind) {
            if (! $this->permits($reader, $kind)) {
                continue;
            }

            $rows[] = [
                'key' => $kind->key(),
                'label' => $kind->label(),
                'module' => $kind->module(),
                'status' => $kind->status(),
                'blocked_reason' => $kind->status() === ExportKind::STATUS_BLOCKED ? $kind->blockedReason() : null,
                'row_cap' => $kind->rowCap(),
                'filters' => FilterSchema::describe($kind->filterRules()),
            ];
        }

        return $rows;
    }
}
