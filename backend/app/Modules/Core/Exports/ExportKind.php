<?php

namespace App\Modules\Core\Exports;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One downloadable thing (MASTER-PLAN Phase 4.5 — the Download / Export
 * Center). Core owns the Center; each module owns its kinds and lists them
 * in config/exports.php.
 *
 * The rule a kind exists to enforce: an export is a SERVER-SIDE read of the
 * SAME query the module's list/report endpoint runs, with the SAME filters
 * (filterRules() delegates to the module's List*Request — never a second
 * grammar), gated for the SAME reader — never the rows a browser happens
 * to have rendered. FC-06 applies to the file exactly as to the screen: a
 * reader who cannot see a rate or a vendor on screen gets NO such column
 * in the file — columns() may depend on the reader, and that is where such
 * columns disappear (ABSENT, not blank).
 *
 * rows() builds each row THROUGH the module's Service and JsonResource
 * (e.g. SomeResource::make($model)->resolve($request)) so the gating is
 * inherited and cannot drift from the screen; a row is keyed like that
 * resource's output, and columns() maps CSV header → row key (dot paths
 * allowed).
 */
interface ExportKind
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    /** Stable snake_case key — the URL segment and the file-name stem ('tally_sync_entries'). */
    public function key(): string;

    /** What the Center shows on the card. */
    public function label(): string;

    /** The owning module's permission group ('production', 'sales', 'tally-sync', 'core', …). */
    public function module(): string;

    /**
     * The reader holds ANY of these → may see and run the kind
     * (['tally-sync.view', 'tally-sync.manage']).
     *
     * @return list<string>
     */
    public function permissionAny(): array;

    /**
     * Laravel validation rules for the POST body — the module's existing
     * List… / Report…Request rules, reused, never redeclared.
     *
     * @return array<string, mixed>
     */
    public function filterRules(): array;

    /** Over this many matching rows the server refuses (ExportCapExceededException). */
    public function rowCap(): int;

    /** STATUS_AVAILABLE or STATUS_BLOCKED. A blocked kind is still catalogued, with its reason. */
    public function status(): string;

    /** Why the kind is blocked (verbatim to the client); null when available. */
    public function blockedReason(): ?string;

    /**
     * CSV header → row key, in column order. MAY depend on the reader —
     * this is where FC-06 columns disappear for a reader without standing.
     *
     * @return array<string, string>
     */
    public function columns(?Authenticatable $reader): array;

    /**
     * Every matching row, in the list's order, one at a time (a generator
     * — the whole result is never held in memory). Each row is keyed like
     * the module's resource output.
     *
     * @param  array<string, mixed>  $filters  the validated body
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(array $filters, ?Authenticatable $reader): iterable;

    /**
     * How many rows rows() would yield — ONE count over the same query,
     * for the cap check.
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters, ?Authenticatable $reader): int;
}
