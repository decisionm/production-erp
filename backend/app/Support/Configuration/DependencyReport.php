<?php

namespace App\Support\Configuration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The answer to "may this configuration record be deleted?", as DATA.
 *
 * The 422 carries blocking() so the SPA renders "used by 12 stock
 * movements and 2 production batches" from a list rather than by parsing
 * prose; sentence() is that same list said once, for the message a human
 * reads. Counts are INTEGERS — the UI shows them.
 *
 * unprovable() is the fail-closed half (DEC-20260817-002 §5): checks that
 * could not prove the record was never used. They carry no count — there
 * is no number to invent — and they block exactly like a positive count.
 *
 * cascadeGaps() is the half NO module can get wrong, because it is not
 * declared: every report asks the SCHEMA (SchemaCascades) which foreign
 * keys would cascade a delete of this row, and any cascading child that the
 * module's checks do not account for is a GAP that blocks. A hand-written
 * dependency list will be incomplete one day; the database the delete runs
 * against will not be, so the two are compared before anything is
 * destroyed. This lives here, in the report, rather than in delete(),
 * precisely so the advisory answer and the enforced one cannot disagree —
 * the button is never offered for a delete the mechanism would refuse.
 *
 * isClear() is true only when ALL THREE lists are empty.
 */
class DependencyReport
{
    /**
     * @param  list<array{code: string, label: string, count: int|null}>  $entries
     * @param  list<array{table: string, column: string, reason: string, message: string}>  $cascadeGaps
     */
    private function __construct(
        private readonly array $entries,
        private readonly array $cascadeGaps = [],
    ) {}

    /**
     * Run every declared check against one record — and check the
     * declaration itself against the schema.
     *
     * @param  list<DependencyCheck>  $checks
     */
    public static function for(Model $model, array $checks): self
    {
        $entries = [];

        foreach ($checks as $check) {
            $count = $check->count($model);

            if ($count === null || $count > 0) {
                $entries[] = [
                    'code' => $check->code(),
                    'label' => $check->rawLabel(),
                    'count' => $count,
                ];
            }
        }

        return new self($entries, self::cascadeGapsFor($model, $checks));
    }

    /**
     * Cascading children of this record's table that the declaration does
     * not account for. Each one would be destroyed, silently, by a delete
     * this class would otherwise have called clear.
     *
     * PER FOREIGN-KEY COLUMN, not per table, and the difference is a real
     * hole rather than pedantry: `masterbatch_dosings` cascades into `items`
     * through TWO columns, and a check that counts only
     * `masterbatch_item_id` proves nothing about an item referenced solely
     * as `product_item_id` — the count comes back zero and the dosing row is
     * destroyed. Every cascading column must be counted by something.
     * (A genuinely composite foreign key is asked for all of its columns,
     * which is over-strict and therefore fails closed: a check on one column
     * of a composite already over-counts, so requiring the rest only ever
     * refuses more.)
     *
     * @param  list<DependencyCheck>  $checks
     * @return list<array{table: string, column: string, reason: string, message: string}>
     */
    private static function cascadeGapsFor(Model $model, array $checks): array
    {
        $connection = $model->getConnection();
        $cascades = SchemaCascades::referencing($connection, $model->getTable());

        if ($cascades === null) {
            return [[
                'table' => '*',
                'column' => '*',
                'reason' => 'schema_unreadable',
                'message' => sprintf(
                    "the schema's cascades cannot be read on a %s connection, so no delete can be proven safe",
                    $connection->getDriverName(),
                ),
            ]];
        }

        $covered = [];

        foreach ($checks as $check) {
            foreach ($check->coveredCascades() as $table => $columns) {
                foreach ($columns as $column) {
                    $covered[$table][mb_strtolower($column)][] = $check;
                }
            }
        }

        $gaps = [];

        foreach ($cascades as $cascade) {
            $table = $cascade['table'];
            $softDeletes = DependencyCheck::tableSoftDeletes($connection->getName(), $table);

            foreach ($cascade['columns'] as $column) {
                $matching = $covered[$table][mb_strtolower($column)] ?? [];

                if ($matching === []) {
                    $gaps[] = [
                        'table' => $table,
                        'column' => $column,
                        'reason' => 'undeclared',
                        'message' => "the schema cascades {$table}.{$column} and no check declares it",
                    ];

                    continue;
                }

                // A soft-deleted child is still a physical row, so the
                // cascade takes it too; a check that skips trashed rows does
                // not cover it.
                $countsTrashed = array_filter($matching, fn (DependencyCheck $check): bool => $check->countsTrashed());

                if ($softDeletes && $countsTrashed === []) {
                    $gaps[] = [
                        'table' => $table,
                        'column' => $column,
                        'reason' => 'archived_rows_uncounted',
                        'message' => "the schema cascades {$table}.{$column} and the check that declares it does not count archived rows",
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * The schema's own answer: cascading children nothing declares. Blocks.
     *
     * @return list<array{table: string, column: string, reason: string, message: string}>
     */
    public function cascadeGaps(): array
    {
        return $this->cascadeGaps;
    }

    /**
     * What references the record, with integer counts, labels pluralised
     * to those counts. This is the 422's `blocking`.
     *
     * @return list<array{code: string, label: string, count: int}>
     */
    public function blocking(): array
    {
        $blocking = [];

        foreach ($this->entries as $entry) {
            if ($entry['count'] === null) {
                continue;
            }

            $blocking[] = [
                'code' => $entry['code'],
                'label' => Str::plural($entry['label'], $entry['count']),
                'count' => $entry['count'],
            ];
        }

        return $blocking;
    }

    /**
     * Checks that could not prove the record was never used. No count: the
     * whole point is that the number is unknown, and a missing figure is
     * reported missing, never interpolated.
     *
     * @return list<array{code: string, label: string}>
     */
    public function unprovable(): array
    {
        $unprovable = [];

        foreach ($this->entries as $entry) {
            if ($entry['count'] === null) {
                $unprovable[] = ['code' => $entry['code'], 'label' => $entry['label']];
            }
        }

        return $unprovable;
    }

    /** Nothing references it, everything could be checked, and the declaration covers every cascade. */
    public function isClear(): bool
    {
        return $this->entries === [] && $this->cascadeGaps === [];
    }

    /**
     * The reason clause of the refusal — "used by 12 stock movements and 2
     * production batches", plus (or instead) "past use of X cannot be
     * verified" when a check could not answer.
     */
    public function sentence(): string
    {
        $clauses = [];

        $used = array_map(
            fn (array $entry): string => "{$entry['count']} {$entry['label']}",
            $this->blocking(),
        );

        if ($used !== []) {
            $clauses[] = 'used by '.self::join($used);
        }

        $unprovable = array_map(
            fn (array $entry): string => $entry['label'],
            $this->unprovable(),
        );

        if ($unprovable !== []) {
            $clauses[] = 'past use of '.self::join($unprovable).' cannot be verified';
        }

        $gaps = array_map(
            fn (array $gap): string => $gap['message'],
            $this->cascadeGaps,
        );

        if ($gaps !== []) {
            $clauses[] = self::join($gaps);
        }

        return implode(', and ', $clauses);
    }

    /** @param list<string> $parts */
    private static function join(array $parts): string
    {
        if (count($parts) < 2) {
            return (string) ($parts[0] ?? '');
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
