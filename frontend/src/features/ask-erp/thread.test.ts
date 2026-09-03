import { describe, expect, it } from 'vitest';
import { NEAR_BOTTOM, isNearBottom } from './thread';

describe('following the newest turn', () => {
    it('follows while the reader is at the floor of the thread', () => {
        expect(isNearBottom({ scrollTop: 900, scrollHeight: 1400, clientHeight: 500 })).toBe(true);
    });

    it('counts a few pixels short of the floor as still there', () => {
        expect(isNearBottom({ scrollTop: 900 - NEAR_BOTTOM, scrollHeight: 1400, clientHeight: 500 })).toBe(true);
    });

    it('lets go once the reader has scrolled up to read an older answer', () => {
        expect(isNearBottom({ scrollTop: 200, scrollHeight: 1400, clientHeight: 500 })).toBe(false);
    });

    it('is true for a thread too short to scroll', () => {
        expect(isNearBottom({ scrollTop: 0, scrollHeight: 400, clientHeight: 500 })).toBe(true);
    });
});
