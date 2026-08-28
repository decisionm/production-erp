import { describe, expect, it } from 'vitest';
import { bagScansOf } from '@/features/material-flow/components/HandoverPanel';
import type { StoreIssueBagScan } from '@/features/material-flow/types';

/**
 * THE ABSENT SCAN LIST (local rehearsal crash, 28-Aug).
 *
 * The queue page renders a handover straight from the LIST endpoint, and the
 * list endpoint does not eager-load bag scans — the resource's
 * `whenLoaded('bagScans')` then omits the key entirely. A freshly issued
 * material request therefore arrives with `bag_scans` ABSENT, not empty, and
 * the panel crashed reading `.length` off undefined.
 *
 * The contract pinned here: an absent list is "no scans to show", identical
 * to an empty one. The panel reads the list only through bagScansOf.
 */

describe('bagScansOf', () => {
    it('treats an omitted bag_scans as no scans', () => {
        expect(bagScansOf({})).toEqual([]);
    });

    it('passes a present list through untouched', () => {
        const scans = [{ id: 1 }, { id: 2 }] as StoreIssueBagScan[];

        expect(bagScansOf({ bag_scans: scans })).toBe(scans);
    });
});
