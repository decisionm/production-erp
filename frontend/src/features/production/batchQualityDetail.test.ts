import { describe, expect, it } from 'vitest';
import { downtimeLines, runSummary, scrapLines, scrapSummary } from './batchQualityDetail';
import type { ShiftProductionEntry } from './types';

/** Only the fields these four readers touch; the entry type is far wider. */
function entry(overrides: Record<string, unknown>): ShiftProductionEntry {
    return overrides as unknown as ShiftProductionEntry;
}

describe('scrapSummary', () => {
    it('reads lumps, rejection and issued from the metrics block', () => {
        const rows = scrapSummary(entry({
            metrics: {
                lumps_kg: '12.5000',
                rejection_kg_production: '3.2000',
                issued_kg: '400.0000',
            },
        }));

        expect(rows.map((r) => [r.label, r.value])).toEqual([
            ['Lumps', '12.5 kg'],
            ['Rejection (production)', '3.2 kg'],
            ['Material issued', '400 kg'],
        ]);
    });

    /*
     * The approval desk deleted this figure and recorded the reason: issued −
     * good − rejection − lumps is ~0 by construction on a floor that weighs no
     * resin out to a machine, so it reads as a loss and is arithmetic. Pinned
     * here so it is not helpfully re-added to the quality desk later.
     */
    it('has no unaccounted row, which the approval desk removed as arithmetic', () => {
        const labels = scrapSummary(entry({
            metrics: { reconciliation_unaccounted_kg: '5.1000', unaccounted_band: 'investigate' },
        })).map((r) => r.label);

        expect(labels).not.toContain('Unaccounted');
    });

    it('falls back to the entry rejection when metrics has none', () => {
        const rows = scrapSummary(entry({ metrics: null, quantity_rejection_kg: '9.0000' }));

        expect(rows[1].value).toBe('9 kg');
    });

    it('dashes every figure rather than printing a zero it did not compute', () => {
        expect(scrapSummary(null).map((r) => r.value)).toEqual(['—', '—', '—']);
        expect(scrapSummary(entry({ metrics: null })).map((r) => r.value)).toEqual(['—', '—', '—']);
    });
});

describe('runSummary', () => {
    it('names who ran it, on what, and what it cost in stoppages', () => {
        const rows = runSummary(entry({
            operator: { id: 1, name: 'R. Kumar' },
            shift: { id: 2, name: 'Shift B' },
            colour: 'Amber',
            unit_weight_grams: '18.5',
            downtime_events: [{ id: 1, minutes: '20' }, { id: 2, minutes: '10' }],
        }));

        expect(rows.map((r) => [r.label, r.value])).toEqual([
            ['Operator', 'R. Kumar'],
            ['Shift', 'Shift B'],
            ['Colour', 'Amber'],
            ['Unit weight', '18.5 g'],
            ['Downtime', '30 min'],
        ]);
    });

    /*
     * ProductionMetrics carries no per-batch downtime total, so this is summed
     * from the events — which makes the absent/empty distinction load-bearing.
     * A payload that never loaded the stoppages must not read as a batch that
     * ran without stopping.
     */
    it('dashes downtime when the events were not loaded, and zeroes it when there were none', () => {
        const downtimeOf = (e: ShiftProductionEntry) =>
            runSummary(e).find((r) => r.label === 'Downtime')?.value;

        expect(downtimeOf(entry({}))).toBe('—');
        expect(downtimeOf(entry({ downtime_events: [] }))).toBe('0 min');
    });

    it('has no mould row, because a mould is recorded against a machine and not a batch', () => {
        expect(runSummary(entry({})).map((r) => r.label)).not.toContain('Mould');
    });

    it('dashes a missing operator rather than inventing one', () => {
        expect(runSummary(entry({ operator: null })).find((r) => r.label === 'Operator')?.value).toBe('—');
    });
});

describe('downtimeLines', () => {
    it('names each stoppage, so ninety minutes of one cause reads differently from nine', () => {
        const rows = downtimeLines(entry({
            downtime_events: [
                { id: 1, reason: { id: 1, code: 'PWR', description: 'Power cut' }, minutes: '90' },
                { id: 2, reason: { id: 2, code: 'JAM', description: 'Mould jam' }, minutes: '10' },
            ],
        }));

        expect(rows).toEqual([
            { label: 'Power cut', value: '90 min' },
            { label: 'Mould jam', value: '10 min' },
        ]);
    });

    it('falls back to the reason code, then to a position, rather than printing nothing', () => {
        const rows = downtimeLines(entry({
            downtime_events: [
                { id: 1, reason: { id: 1, code: 'PWR', description: null }, minutes: '5' },
                { id: 2, reason: null, minutes: '5' },
            ],
        }));

        expect(rows.map((r) => r.label)).toEqual(['PWR', 'Stoppage 2']);
    });

    it('is empty when the payload did not load the events, which is absent and not zero', () => {
        expect(downtimeLines(entry({}))).toEqual([]);
        expect(downtimeLines(null)).toEqual([]);
    });
});

describe('scrapLines', () => {
    it('labels a scrap by its recorded reason', () => {
        const rows = scrapLines(entry({
            scraps: [{ id: 1, type: 'lumps', quantity_kg: '12.5000', scrap_reason: { id: 3, name: 'Startup purge' } }],
        }));

        expect(rows).toEqual([{ label: 'Startup purge', value: '12.5 kg' }]);
    });

    it('falls back to the scrap type when no reason was recorded', () => {
        const rows = scrapLines(entry({
            scraps: [
                { id: 1, type: 'lumps', quantity_kg: '1.0000', scrap_reason: null },
                { id: 2, type: 'rejected_finished_good', quantity_kg: '2.0000', scrap_reason: null },
            ],
        }));

        expect(rows.map((r) => r.label)).toEqual(['Lumps', 'Rejected']);
    });

    it('is empty when scraps were not loaded', () => {
        expect(scrapLines(entry({}))).toEqual([]);
    });
});
