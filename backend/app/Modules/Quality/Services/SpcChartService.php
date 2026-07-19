<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\SpcCharacteristic;
use Illuminate\Support\Collection;

/**
 * Individuals & Moving Range (I-MR) control chart — the standard choice
 * when each sample is a single continuous measurement rather than a
 * subgroup (X-bar/R charts need subgroups and are deliberately out of
 * scope here). The D2/D3/D4 constants below are the textbook values for
 * a 2-point moving range — mathematical constants tied to that window
 * size, not a configurable business policy like a tax rate.
 *
 * Control limits (what the process actually does) are recomputed from
 * every available measurement each time the chart is requested, rather
 * than freezing a baseline after N points once established — a
 * deliberate simplification; baseline-freezing is a refinement that can
 * be added later if it's actually needed.
 *
 * Violation detection covers the two most standard, universally
 * recognized rules — a point beyond the control limits, and a run of 8+
 * consecutive points on one side of the center line — not the full
 * Western Electric/Nelson rule set (zone tests, trends, etc.), which is
 * real additional complexity beyond what "SPC charts" was scoped to.
 */
class SpcChartService
{
    private const D2 = 1.128;

    private const D3 = 0.0;

    private const D4 = 3.267;

    private const SIGMA_MULTIPLIER = 3.0;

    private const RUN_LENGTH = 8;

    public function chart(SpcCharacteristic $characteristic): array
    {
        $measurements = $characteristic->measurements()->orderBy('measured_at')->get();

        if ($measurements->count() < 2) {
            return [
                'characteristic_id' => $characteristic->id,
                'sufficient_data' => false,
                'points' => $measurements->map(fn ($m) => [
                    'id' => $m->id,
                    'measured_at' => $m->measured_at->toIso8601String(),
                    'value' => (float) $m->value,
                    'moving_range' => null,
                    'beyond_limits' => false,
                    'run_violation' => false,
                ])->values()->all(),
                'center_line' => null,
                'ucl' => null,
                'lcl' => null,
                'mr_center_line' => null,
                'mr_ucl' => null,
                'mr_lcl' => null,
            ];
        }

        $values = $measurements->pluck('value')->map(fn ($v) => (float) $v)->values();
        $n = $values->count();

        $mean = $values->avg();

        $movingRanges = [];
        for ($i = 1; $i < $n; $i++) {
            $movingRanges[] = abs($values[$i] - $values[$i - 1]);
        }
        $mrBar = array_sum($movingRanges) / count($movingRanges);

        $sigma = $mrBar / self::D2;
        $ucl = $mean + self::SIGMA_MULTIPLIER * $sigma;
        $lcl = $mean - self::SIGMA_MULTIPLIER * $sigma;

        $mrUcl = self::D4 * $mrBar;
        $mrLcl = self::D3 * $mrBar;

        $runViolations = $this->detectRunViolations($values, $mean, $n);

        $points = [];
        foreach ($measurements as $index => $measurement) {
            $value = (float) $measurement->value;
            $points[] = [
                'id' => $measurement->id,
                'measured_at' => $measurement->measured_at->toIso8601String(),
                'value' => $value,
                'moving_range' => $index === 0 ? null : round($movingRanges[$index - 1], 4),
                'beyond_limits' => $value > $ucl || $value < $lcl,
                'run_violation' => $runViolations[$index],
            ];
        }

        return [
            'characteristic_id' => $characteristic->id,
            'sufficient_data' => true,
            'points' => $points,
            'center_line' => round($mean, 4),
            'ucl' => round($ucl, 4),
            'lcl' => round($lcl, 4),
            'mr_center_line' => round($mrBar, 4),
            'mr_ucl' => round($mrUcl, 4),
            'mr_lcl' => round($mrLcl, 4),
        ];
    }

    /**
     * @param  Collection<int, float>  $values
     * @return array<int, bool>
     */
    private function detectRunViolations($values, float $mean, int $n): array
    {
        $sides = $values->map(fn ($v) => $v > $mean ? 1 : ($v < $mean ? -1 : 0))->values();
        $violations = array_fill(0, $n, false);
        $runStart = 0;

        for ($i = 1; $i <= $n; $i++) {
            $continuesRun = $i < $n && $sides[$i] !== 0 && $sides[$i] === $sides[$runStart];

            if (! $continuesRun) {
                $runLength = $i - $runStart;
                if ($sides[$runStart] !== 0 && $runLength >= self::RUN_LENGTH) {
                    for ($j = $runStart; $j < $i; $j++) {
                        $violations[$j] = true;
                    }
                }
                $runStart = $i;
            }
        }

        return $violations;
    }
}
