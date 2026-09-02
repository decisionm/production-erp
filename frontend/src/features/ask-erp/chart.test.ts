import { describe, expect, it } from 'vitest';
import { chartPoints, chartScale } from './chart';

describe('chartPoints', () => {
    it('reads x and y from the chart spec, numbers coerced', () => {
        const points = chartPoints({
            columns: ['status', 'n'],
            rows: [
                { status: 'open', n: '3' },
                { status: 'closed', n: 9 },
            ],
            truncated: false,
            chart: { type: 'bar', x: 'status', y: 'n' },
        });
        expect(points).toEqual([
            { label: 'open', value: 3 },
            { label: 'closed', value: 9 },
        ]);
    });

    it('is empty without a chart spec', () => {
        expect(chartPoints({ columns: ['n'], rows: [{ n: 1 }], truncated: false, chart: null })).toEqual([]);
    });
});

describe('chartScale', () => {
    it('rounds the max up to a friendly number and gives four ticks', () => {
        expect(
            chartScale([
                { label: 'a', value: 37 },
                { label: 'b', value: 12 },
            ])
        ).toEqual({ max: 40, ticks: [0, 10, 20, 30, 40] });
        expect(chartScale([{ label: 'a', value: 0.6 }])).toEqual({ max: 1, ticks: [0, 0.25, 0.5, 0.75, 1] });
        expect(chartScale([{ label: 'a', value: 1234 }])).toEqual({ max: 2000, ticks: [0, 500, 1000, 1500, 2000] });
        expect(chartScale([])).toEqual({ max: 1, ticks: [0, 0.25, 0.5, 0.75, 1] });
    });
});
