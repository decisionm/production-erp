<?php

namespace App\Support\Tally;

use Illuminate\Database\Eloquent\Model;

/**
 * Idempotently upsert a batch of self-referencing Tally master nodes (stock
 * groups, ledger groups, godowns) and (re)resolve their parent links by name.
 *
 * Deliberately resilient — this is the "the pull must never break" guarantee:
 *  - matched on tally_guid, so re-pulls update in place, never duplicate;
 *  - a two-pass resolve links parents by name, so parents arriving before OR
 *    after their children both work (Tally export order isn't guaranteed);
 *  - a missing parent just leaves parent_id null this cycle and links on the
 *    next pull once it exists — no hard failure;
 *  - Tally's reserved "Primary" root and blanks collapse to "no parent".
 *
 * Any model passed in must expose columns: tally_guid, name, tally_parent_name,
 * parent_id (and use SoftDeletes so a reappearing node is restored).
 */
class HierarchyUpsert
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array{guid: string, name: string, parent?: string|null}>  $rows
     * @param  (callable(array): array<string, mixed>)|null  $defaultsForCreate  extra attributes when inserting a new node
     * @return array{created: int, updated: int, total: int}
     */
    public static function sync(string $modelClass, array $rows, ?callable $defaultsForCreate = null): array
    {
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $parentName = self::normalizeParent($row['parent'] ?? null);

            /** @var Model|null $node */
            $node = $modelClass::withTrashed()->where('tally_guid', $row['guid'])->first();

            if ($node !== null) {
                $node->fill(['name' => $row['name'], 'tally_parent_name' => $parentName]);
                if ($node->trashed()) {
                    $node->restore();
                }
                $node->save();
                $updated++;

                continue;
            }

            $attributes = ['tally_guid' => $row['guid'], 'name' => $row['name'], 'tally_parent_name' => $parentName];
            if ($defaultsForCreate !== null) {
                // Union keeps the Tally-sourced keys above authoritative; defaults
                // only fill columns they don't already set (e.g. warehouses' code).
                $attributes += $defaultsForCreate($row);
            }

            $modelClass::create($attributes);
            $created++;
        }

        self::resolveParents($modelClass);

        return ['created' => $created, 'updated' => $updated, 'total' => count($rows)];
    }

    /** Tally's reserved root ("Primary") and blanks mean "no parent". */
    private static function normalizeParent(?string $parent): ?string
    {
        $parent = $parent !== null ? trim($parent) : null;

        return ($parent === null || $parent === '' || $parent === 'Primary') ? null : $parent;
    }

    /** Link every node to its parent by name — order-independent and re-runnable. */
    private static function resolveParents(string $modelClass): void
    {
        $byName = $modelClass::query()->get()->keyBy('name');

        $modelClass::query()->whereNotNull('tally_parent_name')->get()->each(function (Model $node) use ($byName): void {
            /** @var Model|null $parent */
            $parent = $byName->get($node->getAttribute('tally_parent_name'));
            $parentId = ($parent !== null && $parent->getKey() !== $node->getKey()) ? $parent->getKey() : null;

            if ($node->getAttribute('parent_id') !== $parentId) {
                $node->setAttribute('parent_id', $parentId)->save();
            }
        });
    }
}
