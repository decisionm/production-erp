<?php

namespace App\Modules\Production\Services;

use Illuminate\Support\Facades\DB;

/**
 * HOW MANY COMPLETED RUNS NAME THIS ROW IN THEIR FROZEN SNAPSHOT — the one
 * dependency check in the product-definition set with NO backstop of any
 * kind behind it.
 *
 * Start Batch freezes the resolved configuration and standard into
 * `shift_production_entries.config_snapshot` (ShiftProductionEntryService)
 * so a later edit can never rewrite what a run was measured against. That is
 * a JSON key, not a foreign key: no cascade, no constraint, nothing in
 * `SchemaCascades` and nothing in the database would notice a delete taking
 * the row it names. If this count is wrong, a referenced standard or
 * configuration is destroyed while every snapshot still names it — and every
 * test passes, because the count came back zero.
 *
 * SO THE PREDICATE IS DRIVER-EXPLICIT rather than trusted to compile the
 * same way twice. `where('config_snapshot->key', $id)` compiles to
 * `json_extract(...) = ?` on sqlite and
 * `json_unquote(json_extract(...)) = ?` on MySQL, and the two sides of that
 * comparison are not the same types on the two drivers. Both sides are
 * forced to TEXT here, on both drivers, so an id matches an id and nothing
 * depends on a numeric coercion nobody tested. This repo already does
 * exactly this for its other JSON predicate — see
 * ShiftProductionEntryService's `match ($driver)` around
 * `config_snapshot->quality_returns`, and SchemaCascades, which implements
 * both drivers for the stated reason that only one of them is exercised
 * locally.
 */
final class ConfigSnapshotReference
{
    /** Completed runs whose frozen snapshot carries `$key` = `$id`. */
    public static function count(string $key, int|string|null $id): int
    {
        if ($id === null) {
            return 0;
        }

        $connection = DB::connection();
        $path = '$.'.$key;

        $expression = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'json_unquote(json_extract(config_snapshot, ?))',
            default => 'cast(json_extract(config_snapshot, ?) as text)',
        };

        return (int) $connection->table('shift_production_entries')
            ->whereRaw("{$expression} = ?", [$path, (string) $id])
            ->count();
    }
}
