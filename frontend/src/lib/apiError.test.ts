import { describe, expect, it } from 'vitest';
import { apiErrorParts, apiErrorSummary, FALLBACK_API_ERROR, fieldLabel } from './apiError';

/** An axios-shaped rejection, which is the only shape the app ever catches. */
const axiosError = (status: number, data: unknown) => ({ response: { status, data } });

describe('fieldLabel', () => {
    it('keeps the words of a plain key', () => {
        expect(fieldLabel('name')).toBe('Name');
        expect(fieldLabel('nominal_weight_grams')).toBe('Nominal weight grams');
    });

    it('numbers an indexed line from one, the way the row is numbered on screen', () => {
        expect(fieldLabel('packagings.0.nos_per_box')).toBe('Packagings #1 → nos per box');
        expect(fieldLabel('lines.2.item_id')).toBe('Lines #3 → item id');
    });

    it('never returns an empty label', () => {
        expect(fieldLabel('')).toBe('');
        expect(fieldLabel('0')).toBe('#1');
    });
});

describe('apiErrorParts', () => {
    it('KEEPS the field key of every validation message — the defect this helper exists to fix', () => {
        const parts = apiErrorParts(
            axiosError(422, {
                message: 'The given data was invalid.',
                errors: {
                    standard_cavities: ['The cavities must not exceed the machine maximum.'],
                    'packagings.0.nos_per_box': ['Pieces per box is required.', 'Pieces per box must be a number.'],
                },
            }),
        );

        expect(parts.fields).toEqual([
            {
                field: 'standard_cavities',
                label: 'Standard cavities',
                messages: ['The cavities must not exceed the machine maximum.'],
            },
            {
                field: 'packagings.0.nos_per_box',
                label: 'Packagings #1 → nos per box',
                messages: ['Pieces per box is required.', 'Pieces per box must be a number.'],
            },
        ]);
    });

    it('preserves the server order of the fields', () => {
        const parts = apiErrorParts(axiosError(422, { errors: { b: ['second'], a: ['first'] } }));
        expect(parts.fields.map((f) => f.field)).toEqual(['b', 'a']);
    });

    it('accepts a bare string message under a field key', () => {
        const parts = apiErrorParts(axiosError(422, { errors: { name: 'Already taken.' } }));
        expect(parts.fields).toEqual([{ field: 'name', label: 'Name', messages: ['Already taken.'] }]);
    });

    it('carries the domain error code when the server sent one', () => {
        const parts = apiErrorParts(
            axiosError(422, { message: 'Cannot delete item "X".', code: 'configuration_in_use' }),
        );
        expect(parts.code).toBe('configuration_in_use');
        expect(parts.message).toBe('Cannot delete item "X".');
        expect(parts.fields).toEqual([]);
    });

    it('falls back to the same words the two hand-rolled handlers used', () => {
        expect(apiErrorParts(axiosError(500, {})).message).toBe(FALLBACK_API_ERROR);
        expect(apiErrorParts(new Error('network down')).message).toBe(FALLBACK_API_ERROR);
        expect(apiErrorParts(undefined).message).toBe(FALLBACK_API_ERROR);
        expect(apiErrorParts(undefined).code).toBeNull();
        expect(apiErrorParts(undefined).fields).toEqual([]);
    });
});

describe('apiErrorSummary', () => {
    it('names the field in the one-line form too', () => {
        const summary = apiErrorSummary(
            axiosError(422, {
                message: 'The given data was invalid.',
                errors: { max_cavities: ['Max cavities must be at least min cavities.'] },
            }),
        );
        expect(summary).toBe('Max cavities: Max cavities must be at least min cavities.');
    });

    it('joins several fields without losing either key', () => {
        const summary = apiErrorSummary(axiosError(422, { errors: { a_code: ['Taken.'], b_code: ['Taken too.'] } }));
        expect(summary).toBe('A code: Taken. · B code: Taken too.');
    });

    it('uses the message when there are no field errors', () => {
        expect(apiErrorSummary(axiosError(422, { message: 'Batch already approved.' }))).toBe('Batch already approved.');
    });
});
