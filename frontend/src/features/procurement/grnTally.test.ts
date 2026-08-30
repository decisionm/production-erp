import { describe, expect, it } from 'vitest';
import { grnTallyStateLine } from './grnTally';
import type { TallyLink } from '@/features/sales/types';

const link = (status: string): TallyLink =>
    ({ entry_id: 9, voucher_type: 'Receipt Note', status, voucher_number: 'GRN-7', synced_at: null, flags: {}, link: '/tally-sync?entry=9' }) as unknown as TallyLink;

describe('grnTallyStateLine', () => {
    it('a live queue entry outranks everything — its status is the live fact', () => {
        const line = grnTallyStateLine({ tally: link('pending'), tally_staging: { state: 'refused', reasons: [] } });
        expect(line.kind).toBe('link');
        expect(line.link?.entry_id).toBe(9);
    });

    it('refused staging prints every reason, in the shared vocabulary', () => {
        const line = grnTallyStateLine({
            tally: null,
            tally_staging: {
                state: 'refused',
                reasons: [
                    { code: 'item_unmapped', detail: "#12 'Blue Drum'" },
                    { code: 'party_unmapped' },
                    { code: 'allowed_company_unconfigured' },
                ],
            },
        });
        expect(line.kind).toBe('refused');
        expect(line.color).toBe('orange');
        expect(line.text).toBe(
            "Not sent to Tally — item #12 'Blue Drum' has no Tally identity; vendor has no Tally ledger name; no allowed Tally company is configured",
        );
    });

    it('disabled staging names the decision, not a generic off-switch', () => {
        const line = grnTallyStateLine({ tally: null, tally_staging: { state: 'disabled', reasons: [] } });
        expect(line.text).toBe('Not sent to Tally — the factory does not use Tally Receipt Notes (DEC-20260830-001)');
    });

    it('enqueued without a readable link says the entry exists and where it went', () => {
        const line = grnTallyStateLine({ tally: null, tally_staging: { state: 'enqueued', entry_id: 42 } });
        expect(line.kind).toBe('enqueued');
        expect(line.text).toContain('entry #42');
    });

    it('a receipt with neither link nor record is honest about predating staging — never a dash', () => {
        const line = grnTallyStateLine({ tally: null, tally_staging: null });
        expect(line.text).toBe('Not sent to Tally — recorded before Tally staging existed');
    });

    it('an unknown refusal code is carried with its detail rather than dropped', () => {
        const line = grnTallyStateLine({
            tally: null,
            tally_staging: { state: 'refused', reasons: [{ code: 'brand_new_code', detail: 'something new' }] },
        });
        expect(line.text).toContain('brand_new_code: something new');
    });
});
