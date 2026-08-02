import { PrinterOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Row, Space, Tag, Typography } from 'antd';
import JsBarcode from 'jsbarcode';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
import type { FinishedCarton } from '@/features/production/types';

interface FinishedCartonLabelsProps {
    cartons: FinishedCarton[];
}

function fmtPieces(value: string): string {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parseFloat(parsed.toFixed(4)).toLocaleString('en-IN') : value;
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function barcodeSvg(code: string): string {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    try {
        JsBarcode(svg, code, {
            format: 'CODE128',
            width: 2,
            height: 70,
            fontSize: 16,
            margin: 10,
        });
        return svg.outerHTML;
    } catch {
        return `<div style="font-size:18px;font-weight:700;">${escapeHtml(code)}</div>`;
    }
}

function labelDetails(carton: FinishedCarton): string[] {
    const sku = carton.item?.sku ?? 'Item';
    return [
        `${sku} — ${carton.item?.name ?? 'Finished goods'}`,
        `${fmtPieces(carton.pieces)} pcs${carton.is_partial ? ' · PARTIAL BOX' : ''}`,
        `Batch ${carton.batch?.batch_number ?? '—'} · ${carton.batch?.production_date ?? '—'}`,
        `${carton.batch?.machine ?? '—'} · ${carton.batch?.shift ?? '—'} shift`,
    ];
}

function printAll(cartons: FinishedCarton[]) {
    if (cartons.length === 0) return;
    const printWindow = window.open('', '_blank', 'width=760,height=720');
    if (!printWindow) return;

    const bodies = cartons
        .map((carton) => {
            const details = labelDetails(carton);
            return `
                <section class="carton-label">
                    <div class="title">${escapeHtml(details[0])}</div>
                    ${barcodeSvg(carton.carton_no)}
                    ${details.slice(1).map((detail) => `<div>${escapeHtml(detail)}</div>`).join('')}
                </section>
            `;
        })
        .join('');

    printWindow.document.write(`
        <!doctype html>
        <html>
            <head>
                <title>Finished carton labels</title>
                <style>
                    @page { margin: 6mm; }
                    body { margin: 0; font-family: Arial, sans-serif; }
                    .carton-label {
                        box-sizing: border-box;
                        break-after: page;
                        min-height: 48mm;
                        padding: 5mm;
                        text-align: center;
                    }
                    .carton-label:last-child { break-after: auto; }
                    .carton-label .title { font-size: 15px; font-weight: 700; margin-bottom: 2mm; }
                    .carton-label div { font-size: 12px; margin-top: 1mm; }
                </style>
            </head>
            <body>${bodies}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

/**
 * The printable barcode labels for a completed batch's cartons — one CODE128
 * label per physical box, the batch spine printed under the code so a box on
 * a pallet months later still names its batch, machine and shift. Codes are
 * permanent: reprinting produces the identical label.
 */
export default function FinishedCartonLabels({ cartons }: FinishedCartonLabelsProps) {
    if (cartons.length === 0) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No cartons exist for this batch." />;
    }

    return (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
            <Space style={{ width: '100%', justifyContent: 'space-between' }} wrap>
                <Typography.Text>
                    {cartons.length} carton{cartons.length === 1 ? '' : 's'} · one permanent barcode per box
                </Typography.Text>
                <Button type="primary" icon={<PrinterOutlined />} onClick={() => printAll(cartons)}>
                    Print all carton labels
                </Button>
            </Space>

            <Row gutter={[12, 12]}>
                {cartons.map((carton) => {
                    const details = labelDetails(carton);
                    return (
                        <Col xs={24} md={12} key={carton.id}>
                            <Card
                                size="small"
                                title={carton.carton_no}
                                extra={
                                    <Space size={4}>
                                        {carton.is_partial && <Tag color="warning">Partial</Tag>}
                                        <Tag color={carton.status === 'dispatched' ? 'default' : 'success'}>
                                            {carton.status === 'dispatched' ? 'Dispatched' : 'In stock'}
                                        </Tag>
                                    </Space>
                                }
                            >
                                <BarcodeDisplay
                                    code={carton.carton_no}
                                    label={details[0]}
                                    printDetails={details.slice(1)}
                                    printButtonLabel="Print / Reprint"
                                />
                                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8, fontSize: 12 }}>
                                    {details[1]} · {details[2]}
                                </Typography.Text>
                            </Card>
                        </Col>
                    );
                })}
            </Row>
        </Space>
    );
}
