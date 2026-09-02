import { describe, expect, it } from 'vitest';
import { TABLE_STICKY, noMatchLine, pageRange, pageRangeLine, rangeLine, serverPagination } from './tableProps';

describe('rangeLine', () => {
    it('states the range within the total', () => {
        expect(rangeLine(143, [21, 40], 'requests')).toBe('21–40 of 143 requests');
    });

    it('drops the range when the page holds everything', () => {
        expect(rangeLine(12, [1, 12], 'requests')).toBe('12 requests');
    });

    it('says 0 plainly', () => {
        expect(rangeLine(0, [0, 0], 'requests')).toBe('0 requests');
    });
});

describe('serverPagination', () => {
    it('is off until the server has answered', () => {
        expect(serverPagination(undefined, () => undefined, 'rows')).toBe(false);
    });

    it('mirrors the server’s meta and offers a size changer', () => {
        const config = serverPagination({ current_page: 2, per_page: 50, total: 143, last_page: 3 }, () => undefined, 'rows');

        expect(config).toMatchObject({ current: 2, pageSize: 50, total: 143, showSizeChanger: true });
    });
});

describe('TABLE_STICKY', () => {
    it('freezes the header below the 64px app bar, never under it', () => {
        expect(TABLE_STICKY).toEqual({ offsetHeader: 64 });
    });
});

describe('pageRange and pageRangeLine', () => {
    it('reads the page’s rows off the server’s meta', () => {
        expect(pageRange({ current_page: 3, per_page: 20, total: 143, last_page: 8 })).toEqual([41, 60]);
        expect(pageRange({ current_page: 8, per_page: 20, total: 143, last_page: 8 })).toEqual([141, 143]);
        expect(pageRange({ current_page: 1, per_page: 20, total: 0, last_page: 1 })).toEqual([0, 0]);
    });

    it('is silent until the server has answered, then states the range', () => {
        expect(pageRangeLine(undefined, 'requests')).toBeNull();
        expect(pageRangeLine({ current_page: 2, per_page: 20, total: 43, last_page: 3 }, 'requests')).toBe('21–40 of 43 requests');
        expect(pageRangeLine({ current_page: 1, per_page: 20, total: 0, last_page: 1 }, 'requests')).toBe('0 requests');
    });
});

describe('noMatchLine', () => {
    it('repeats the term so a typo is visible where it was made', () => {
        expect(noMatchLine('requests', 'resn')).toBe('No requests match “resn”.');
    });
});
