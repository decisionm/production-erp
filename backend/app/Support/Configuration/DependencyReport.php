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
 * isClear() is true only when BOTH lists are empty.
 */
class DependencyReport
{
    /**
     * @param  list<array{code: string, label: string, count: int|null}>  $entries
     */
    private function __construct(private readonly array $entries) {}

    /**
     * Run every declared check against one record.
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

        return new self($entries);
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

    /** Nothing references it and everything could be checked. */
    public function isClear(): bool
    {
        return $this->entries === [];
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
