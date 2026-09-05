<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\Holiday;
use Illuminate\Support\Collection;

/**
 * THE DAYS THE FACTORY IS SHUT, and the one question the rest of the
 * module asks of them: is this date one?
 *
 * Kept deliberately small. A holiday is a date and a name; the year is the
 * date's own, so a calendar cannot be filed under a year it does not fall
 * in. Everything that judges a day asks `datesIn()` for a whole period at
 * once rather than a row at a time — a month of 56 people is 1,736 days,
 * and asking the database 1,736 times to answer 31 distinct questions is
 * how a review screen stops loading.
 */
class HolidayService
{
    /** @return Collection<int, Holiday> the year's holidays, earliest first */
    public function forYear(int $year): Collection
    {
        // whereYear, not a string BETWEEN: the date column stores a
        // datetime, so a 31-December holiday sorts ABOVE "2026-12-31" and a
        // string range would drop it.
        return Holiday::query()
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get();
    }

    /**
     * The holiday dates inside a period, as `Y-m-d` strings keyed for
     * lookup — one query, whatever the period's length.
     *
     * @return array<string, true>
     */
    public function datesIn(string $from, string $to): array
    {
        return Holiday::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [$date->toDateString() => true])
            ->all();
    }

    /**
     * Put a list of holidays in, replacing any row for the same DATE.
     *
     * A date either is a holiday or is not, so the date is the identity and
     * a second upload of the same day corrects its name rather than adding
     * a duplicate. Returns what changed, because an import that says
     * nothing about what it did is one nobody can check.
     *
     * @param  list<array{date: string, name: string}>  $holidays
     * @return array{added: int, renamed: int, unchanged: int}
     */
    public function replaceDates(array $holidays): array
    {
        $added = $renamed = $unchanged = 0;

        foreach ($holidays as $holiday) {
            $existing = Holiday::withTrashed()->whereDate('date', $holiday['date'])->first();

            if ($existing === null) {
                Holiday::create(['date' => $holiday['date'], 'name' => $holiday['name']]);
                $added++;

                continue;
            }

            $wasTrashed = $existing->trashed();
            if ($wasTrashed) {
                $existing->restore();
            }

            if ($existing->name !== $holiday['name'] || $wasTrashed) {
                $existing->update(['name' => $holiday['name']]);
                $renamed++;

                continue;
            }

            $unchanged++;
        }

        return ['added' => $added, 'renamed' => $renamed, 'unchanged' => $unchanged];
    }
}
