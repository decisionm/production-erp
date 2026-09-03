import { describe, expect, it } from 'vitest';
import { resultToCsv } from './csv';

describe('resultToCsv', () => {
    it('quotes commas, quotes and newlines and keeps column order', () => {
        const csv = resultToCsv(
            ['name', 'n'],
            [
                { name: 'Acme, Ltd', n: 3 },
                { name: 'Say "hi"', n: null },
                { name: 'two\nlines', n: 1.5 },
            ]
        );
        expect(csv).toBe('name,n\r\n"Acme, Ltd",3\r\n"Say ""hi""",\r\n"two\nlines",1.5\r\n');
    });

    it('writes only the header for no rows', () => {
        expect(resultToCsv(['a'], [])).toBe('a\r\n');
    });
});
