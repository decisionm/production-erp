import type { EntryQualityReturn } from '@/features/production/types';

/**
 * The one label this feature ever shows for a batch Quality has sent back —
 * the Quality queue's row tag and the Shift Floor's history tag read the
 * SAME text off the SAME key (ShiftProductionEntryResource's
 * `quality_return`, EntryQualityReturn in production/types.ts), because they
 * are the same fact.
 *
 * Null for a batch never returned — the caller renders nothing, not an empty
 * tag. Once returned, the count is named ONLY when it is more than one: a
 * first return reads "Returned by Quality"; a second reads
 * "Returned by Quality x2", and so on. A bare "x1" is a number nobody asked
 * for, and a "x0" — `times` is optional on the wire even though the server
 * always sends a positive int — is worse than saying nothing about the
 * count, so both fall back to the plain label rather than print it.
 */
export function returnedTagText(qualityReturn: EntryQualityReturn | null | undefined): string | null {
    if (!qualityReturn) return null;

    const times = qualityReturn.times ?? 0;

    return times > 1 ? `Returned by Quality x${times}` : 'Returned by Quality';
}
