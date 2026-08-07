import { PrinterOutlined } from '@ant-design/icons';
import { Button, Space, Typography } from 'antd';
import JsBarcode from 'jsbarcode';
import { useEffect, useRef } from 'react';

interface BarcodeDisplayProps {
    code: string;
    label?: string;
    /** Extra human-readable context printed below the barcode. */
    printDetails?: string[];
    /** Callers that reopen an existing label can make the reprint action explicit. */
    printButtonLabel?: string;
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

export default function BarcodeDisplay({
    code,
    label,
    printDetails = [],
    printButtonLabel = 'Print Label',
}: BarcodeDisplayProps) {
    const svgRef = useRef<SVGSVGElement>(null);

    useEffect(() => {
        if (!svgRef.current || !code) return;
        try {
            JsBarcode(svgRef.current, code, {
                format: 'CODE128',
                width: 2,
                height: 70,
                fontSize: 16,
                margin: 10,
            });
        } catch {
            // JsBarcode throws on characters CODE128 can't encode (rare for
            // our SKU/batch/serial/asset codes, which are plain alnum + dashes)
            // — leave the SVG empty rather than crashing the page.
        }
    }, [code]);

    const handlePrint = () => {
        const svg = svgRef.current;
        if (!svg) return;
        const printWindow = window.open('', '_blank', 'width=420,height=300');
        if (!printWindow) return;
        printWindow.document.write(`
            <!doctype html>
            <html>
                <head><title>${escapeHtml(code)}</title></head>
                <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font-family:Arial,sans-serif;">
                    <div style="text-align:center;">
                        ${label ? `<div style="font-weight:700;margin-bottom:4px;">${escapeHtml(label)}</div>` : ''}
                        ${svg.outerHTML}
                        ${printDetails.map((detail) => `<div style="font-size:12px;margin-top:2px;">${escapeHtml(detail)}</div>`).join('')}
                    </div>
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    };

    return (
        <Space direction="vertical" align="center" style={{ width: '100%' }}>
            {label && <Typography.Text type="secondary">{label}</Typography.Text>}
            {/* JsBarcode sets a fixed px width (2px/module — a 20-char carton
                code is ~500px) plus a viewBox, so letting CSS cap it SCALES
                the barcode into whatever card holds it instead of letting it
                bleed across the modal. Print paths build their own SVG at
                native size and are unaffected. */}
            <svg ref={svgRef} style={{ maxWidth: '100%', height: 'auto' }} />
            <Button icon={<PrinterOutlined />} onClick={handlePrint}>
                {printButtonLabel}
            </Button>
        </Space>
    );
}
