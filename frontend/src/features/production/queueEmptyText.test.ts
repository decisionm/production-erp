import { describe, expect, it } from 'vitest';
import { productionRequestHistoryEmptyText, queueEmptyText } from '@/features/production/queueEmptyText';

const refusal = (message: string, errors?: Record<string, string[]>) => ({
    response: { data: { message, ...(errors ? { errors } : {}) } },
});

describe('queueEmptyText', () => {
    it('says the queue is empty when the read succeeded', () => {
        expect(queueEmptyText(false, null)).toBe('Nothing is queued for the floor.');
    });

    it('prints the refusal the server gave, not a flat failure line', () => {
        // The real one, met by walking a fresh factory: the planning walk
        // refuses until somebody names the finished-goods warehouse, and that
        // sentence is the only thing that tells the reader what to do.
        const message = 'No finished-goods warehouse could be worked out for this factory. '
            + 'Name one in Production settings (finished-goods warehouse).';

        expect(queueEmptyText(true, refusal(message, { warehouse_id: [message] }))).toBe(message);
    });

    it('prefers the field error, the way every other refusal on this screen reads it', () => {
        expect(queueEmptyText(true, refusal('generic', { warehouse_id: ['name the store'] })))
            .toBe('name the store');
    });

    it('falls back only when the failure said nothing', () => {
        expect(queueEmptyText(true, new Error('Network Error'))).toBe('The queue could not be read.');
        expect(queueEmptyText(true, undefined)).toBe('The queue could not be read.');
    });
});

describe('productionRequestHistoryEmptyText', () => {
    it('does NOT say the queue is empty — a filtered look-back with no rows is not an idle factory', () => {
        expect(productionRequestHistoryEmptyText(false, null)).toBe('No requests in the chosen statuses.');
        expect(productionRequestHistoryEmptyText(false, null)).not.toBe(queueEmptyText(false, null));
    });

    it('prints the refusal the server gave, same as the queue read does', () => {
        expect(productionRequestHistoryEmptyText(true, refusal('field error', { status: ['field error'] })))
            .toBe('field error');
    });

    it('falls back to its own sentence, not the queue read\'s, when the failure said nothing', () => {
        expect(productionRequestHistoryEmptyText(true, undefined)).toBe('The requests could not be read.');
    });
});
