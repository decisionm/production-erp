import { PrinterOutlined } from '@ant-design/icons';
import { Button, Space, Typography } from 'antd';
import JsBarcode from 'jsbarcode';
import { useEffect, useRef } from 'react';

interface BarcodeDisplayProps {
    code: string;
    label?: string;
}

export default function BarcodeDisplay({ code, label }: BarcodeDisplayProps) {
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
                <head><title>${code}</title></head>
                <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;">
                    ${svg.outerHTML}
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
            <svg ref={svgRef} />
            <Button icon={<PrinterOutlined />} onClick={handlePrint}>
                Print Label
            </Button>
        </Space>
    );
}
