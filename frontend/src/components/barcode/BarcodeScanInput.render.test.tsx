import { renderToString } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import BarcodeScanInput from './BarcodeScanInput';

describe('BarcodeScanInput', () => {
    it('renders keyboard-readable typing and camera controls', () => {
        const html = renderToString(
            <BarcodeScanInput
                autoFocus={false}
                placeholder="Scan an asset barcode…"
                onScan={vi.fn()}
            />,
        );

        expect(html).toContain('aria-label="Scan an asset barcode…"');
        expect(html).toContain('aria-label="Scan with camera"');
        expect(html).toContain('title="Scan with camera"');
    });
});
