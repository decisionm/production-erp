import { describe, expect, it } from 'vitest';
import { inspectionPreview, resultTag } from '@/features/quality/words';

describe('resultTag', () => {
    it('gives sentence-case words, never the raw enum', () => {
        expect(resultTag('pass')).toEqual({ color: 'green', label: 'Pass' });
        expect(resultTag('fail')).toEqual({ color: 'red', label: 'Fail' });
        expect(resultTag('partial')).toEqual({ color: 'gold', label: 'Partial' });
    });

    it('still renders an unknown result readably', () => {
        expect(resultTag('quarantined')).toEqual({ color: 'default', label: 'Quarantined' });
    });
});

/**
 * THE ZERO-PASS DEFECT (28-Aug audit, item 16): the old form defaulted every
 * quantity to 0 and previewed a green "pass" over material nobody had
 * inspected — while the server would refuse the submit outright.
 */
describe('inspectionPreview', () => {
    it('gives NO verdict while the form is empty or all-zero', () => {
        expect(inspectionPreview({})).toEqual({ kind: 'incomplete' });
        expect(inspectionPreview({ inspected: 0, accepted: 0, rejected: 0 })).toEqual({ kind: 'incomplete' });
        expect(inspectionPreview({ inspected: null, accepted: 5, rejected: 0 })).toEqual({ kind: 'incomplete' });
        expect(inspectionPreview({ inspected: 5, accepted: null, rejected: 0 })).toEqual({ kind: 'incomplete' });
    });

    it('names the imbalance when accepted + rejected misses inspected', () => {
        expect(inspectionPreview({ inspected: 10, accepted: 6, rejected: 3 })).toEqual({ kind: 'unbalanced', difference: -1 });
        expect(inspectionPreview({ inspected: 10, accepted: 8, rejected: 3 })).toEqual({ kind: 'unbalanced', difference: 1 });
    });

    it("mirrors the server's derivation once balanced: rejected 0 → pass, accepted 0 → fail, else partial", () => {
        expect(inspectionPreview({ inspected: 10, accepted: 10, rejected: 0 })).toEqual({ kind: 'result', result: 'pass' });
        expect(inspectionPreview({ inspected: 10, accepted: 0, rejected: 10 })).toEqual({ kind: 'result', result: 'fail' });
        expect(inspectionPreview({ inspected: 10, accepted: 7, rejected: 3 })).toEqual({ kind: 'result', result: 'partial' });
    });

    it('tolerates decimal kg without a false imbalance', () => {
        expect(inspectionPreview({ inspected: 1250.5, accepted: 1000.25, rejected: 250.25 })).toEqual({ kind: 'result', result: 'partial' });
    });
});
