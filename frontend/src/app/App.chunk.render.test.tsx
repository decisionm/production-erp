import { renderToString } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { RouteChunkFallback } from './App';

describe('the route-chunk recovery state', () => {
    it('explains the failure and offers a reload instead of a blank page', () => {
        const html = renderToString(<RouteChunkFallback />);

        expect(html).toContain('This page could not be loaded');
        expect(html).toContain('updated while this tab was open');
        expect(html).toContain('Reload page');
        expect(html).toContain('<button');
    });
});
