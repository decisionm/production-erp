import { PrinterOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Row, Space, Tag, Typography } from 'antd';
import JsBarcode from 'jsbarcode';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
import type { FinishedCarton } from '@/features/production/types';
import { COMPANY_NAME } from '@/lib/company';

interface FinishedCartonLabelsProps {
    cartons: FinishedCarton[];
}

function fmtPieces(value: string): string {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parseFloat(parsed.toFixed(4)).toLocaleString('en-IN') : value;
}

function fmtKg(value: string): string {
    const parsed = Number(value);
    return Number.isFinite(parsed)
        ? parsed.toLocaleString('en-IN', { maximumFractionDigits: 3 })
        : value;
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

/**
 * The human-readable lines under the barcode (DEC-20260807-002). Weight is
 * NET only — gross needs the empty carton's tare, which the factory has not
 * stated (pending Q15), and a figure the data cannot support is omitted, not
 * estimated. Customer/PO appears only when the batch really is against a
 * sales order — no such linkage exists in the schema today, so the line
 * simply never renders yet.
 */
function labelDetails(carton: FinishedCarton): string[] {
    const sku = carton.item?.sku ?? 'Item';
    const lines = [
        `${sku} — ${carton.item?.name ?? 'Finished goods'}`,
        `${fmtPieces(carton.pieces)} pcs${carton.is_partial ? ' · PARTIAL BOX' : ''}`,
    ];
    if (carton.batch?.nos_per_box != null) {
        lines.push(`Nos per box: ${fmtPieces(carton.batch.nos_per_box)}`);
    }
    if (carton.net_weight_kg != null) {
        lines.push(`Net weight: ${fmtKg(carton.net_weight_kg)} kg`);
    }
    lines.push(
        `Batch ${carton.batch?.batch_number ?? '—'} · ${carton.batch?.production_date ?? '—'}`,
        `${carton.batch?.machine ?? '—'} · ${carton.batch?.shift ?? '—'} shift`,
    );
    if (carton.sales_order) {
        const so = [
            carton.sales_order.customer,
            carton.sales_order.order_no ? `PO ${carton.sales_order.order_no}` : null,
        ]
            .filter(Boolean)
            .join(' · ');
        if (so) lines.push(so);
    }
    return lines;
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
                    <div class="company">${escapeHtml(COMPANY_NAME)}</div>
                    ${barcodeSvg(carton.carton_no)}
                    ${details.map((detail) => `<div>${escapeHtml(detail)}</div>`).join('')}
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
                    .carton-label .company {
                        font-size: 14px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        margin-bottom: 2mm;
                    }
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
 * label per physical box: the company name on top, then the code, then the
 * pack facts (pieces, nos per box, net weight) and the batch spine, so a box
 * on a pallet months later still names its batch, machine and shift. Codes
 * are permanent: reprinting produces the identical label.
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
                                    label={COMPANY_NAME}
                                    printDetails={details}
                                    printButtonLabel="Print / Reprint"
                                />
                                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8, fontSize: 12 }}>
                                    {details.slice(1, 4).join(' · ')}
                                </Typography.Text>
                            </Card>
                        </Col>
                    );
                })}
            </Row>
        </Space>
    );
}
