import { describe, expect, it } from 'vitest';
import { listReadFailureLine, listReadingLine, listStateKind } from './listState';

const axiosError = (message?: string, status = 500) => ({ response: { status, data: { message } } });

describe('listStateKind', () => {
    it('reports error even while a retry is pending — a failed read is failed, not still reading', () => {
        expect(listStateKind({ isPending: true, isError: true })).toBe('error');
    });

    it('reports pending before the first page arrives', () => {
        expect(listStateKind({ isPending: true, isError: false })).toBe('pending');
    });

    it('reports ready when the read settled without an error', () => {
        expect(listStateKind({ isPending: false, isError: false })).toBe('ready');
    });
});

describe('listReadFailureLine', () => {
    it('carries the server’s own sentence when it sent one', () => {
        expect(listReadFailureLine('purchase orders', axiosError('This action is unauthorized.', 403))).toBe(
            'Could not read purchase orders: This action is unauthorized.',
        );
    });

    it('admits the server said nothing rather than inventing a reason', () => {
        expect(listReadFailureLine('goods receipts', axiosError(undefined))).toBe(
            'Could not read goods receipts: the server did not say why',
        );
    });

    it('names the field a validation refusal is about, the way apiErrorSummary does', () => {
        const error = { response: { status: 422, data: { message: 'The given data was invalid.', errors: { status: ['Unknown status.'] } } } };
        expect(listReadFailureLine('vendors', error)).toBe('Could not read vendors: Status: Unknown status.');
    });
});

describe('listReadingLine', () => {
    it('names what is being read', () => {
        expect(listReadingLine('requisitions')).toBe('Reading requisitions…');
    });
});
