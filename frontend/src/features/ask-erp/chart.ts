import type { AskResult } from './types';

export interface ChartPoint {
    label: string;
    value: number;
}

/** The rows as (label, number) pairs along the server's chart spec; empty without one. */
export function chartPoints(result: AskResult): ChartPoint[] {
    if (!result.chart) return [];
    const { x, y } = result.chart;
    return result.rows.map((row) => ({ label: String(row[x] ?? ''), value: Number(row[y] ?? 0) || 0 }));
}

/** A friendly axis ceiling and four even ticks above zero. */
export function chartScale(points: ChartPoint[]): { max: number; ticks: number[] } {
    const raw = Math.max(0, ...points.map((p) => p.value));
    const max = raw <= 0 ? 1 : niceCeiling(raw);
    const step = max / 4;
    return { max, ticks: [0, step, step * 2, step * 3, max] };
}

function niceCeiling(value: number): number {
    if (value <= 1) return 1;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    const candidates = [1, 2, 2.5, 4, 5, 10].map((m) => m * magnitude);
    return candidates.find((c) => c >= value) ?? 10 * magnitude;
}
