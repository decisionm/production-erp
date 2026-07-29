import { PrinterOutlined } from '@ant-design/icons';
import { Button, Card, Col, Descriptions, Empty, Row, Space, Tag, Typography } from 'antd';
import JsBarcode from 'jsbarcode';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
import type { MaterialBag, MaterialLot } from '@/features/production/types';

interface MaterialBagLabelsProps {
    lots: MaterialLot[];
    /** Existing lots are reprints; newly-created lots use the friendlier first-print wording. */
    reprint?: boolean;
    /** Open one physical bag while retaining its true sequence within the lot. */
    bagId?: number;
}

interface BagLabel {
    bag: MaterialBag;
    lot: MaterialLot;
    sequence: number;
}

const statusColour: Record<MaterialBag['status'], string> = {
    in_store: 'success',
    in_day_bin: 'processing',
    consumed: 'default',
    returned: 'warning',
};

const statusLabel: Record<MaterialBag['status'], string> = {
    in_store: 'In store',
    in_day_bin: 'In day bin',
    consumed: 'Consumed',
    returned: 'Returned',
};

function fmtKg(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = Number(value);
    return Number.isFinite(parsed) ? `${parseFloat(parsed.toFixed(4))} kg` : '—';
}

function fmtWhen(value: string | null | undefined): string {
    if (!value) return '—';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
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

function flattenLabels(lots: MaterialLot[]): BagLabel[] {
    return lots.flatMap((lot) =>
        (lot.bags ?? []).map((bag, index) => ({
            bag,
            lot,
            sequence: index + 1,
        })),
    );
}

function labelDetails({ bag, lot, sequence }: BagLabel): string[] {
    const sku = lot.item?.sku ?? 'Material';
    const lotName = lot.supplier_lot_no ?? `Lot #${lot.id}`;
    const received = lot.received_date ?? '—';
    const created = bag.created_at ?? lot.created_at;
    return [
        `${sku} — ${lot.item?.name ?? 'Unknown material'}`,
        `${lotName} · Bag ${sequence} of ${lot.bag_count}`,
        `Original ${fmtKg(bag.original_kg)} · Received ${received}`,
        `Registered ${fmtWhen(created)}`,
    ];
}

function printAll(labels: BagLabel[]) {
    if (labels.length === 0) return;
    const printWindow = window.open('', '_blank', 'width=760,height=720');
    if (!printWindow) return;

    const bodies = labels
        .map((label) => {
            const details = labelDetails(label);
            return `
                <section class="bag-label">
                    <div class="title">${escapeHtml(details[0])}</div>
                    ${barcodeSvg(label.bag.barcode)}
                    ${details.slice(1).map((detail) => `<div>${escapeHtml(detail)}</div>`).join('')}
                </section>
            `;
        })
        .join('');

    printWindow.document.write(`
        <!doctype html>
        <html>
            <head>
                <title>Material bag labels</title>
                <style>
                    @page { margin: 6mm; }
                    body { margin: 0; font-family: Arial, sans-serif; }
                    .bag-label {
                        box-sizing: border-box;
                        break-after: page;
                        min-height: 48mm;
                        padding: 5mm;
                        text-align: center;
                    }
                    .bag-label:last-child { break-after: auto; }
                    .bag-label .title { font-size: 15px; font-weight: 700; margin-bottom: 2mm; }
                    .bag-label div { font-size: 12px; margin-top: 1mm; }
                </style>
            </head>
            <body>${bodies}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

export default function MaterialBagLabels({ lots, reprint = false, bagId }: MaterialBagLabelsProps) {
    const labels = flattenLabels(lots).filter((label) => bagId === undefined || label.bag.id === bagId);

    if (labels.length === 0) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No bag labels were created for this receipt." />;
    }

    return (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
            <Space style={{ width: '100%', justifyContent: 'space-between' }} wrap>
                <Typography.Text>
                    {labels.length} physical bag{labels.length === 1 ? '' : 's'} · one unique barcode per bag
                </Typography.Text>
                <Button type="primary" icon={<PrinterOutlined />} onClick={() => printAll(labels)}>
                    {reprint ? 'Print all again' : 'Print all bag labels'}
                </Button>
            </Space>

            <Row gutter={[12, 12]}>
                {labels.map((label) => {
                    const details = labelDetails(label);
                    return (
                        <Col xs={24} md={12} xl={8} key={label.bag.id}>
                            <Card
                                size="small"
                                title={`Bag ${label.sequence} of ${label.lot.bag_count}`}
                                extra={<Tag color={statusColour[label.bag.status]}>{statusLabel[label.bag.status]}</Tag>}
                            >
                                <BarcodeDisplay
                                    code={label.bag.barcode}
                                    label={details[0]}
                                    printDetails={details.slice(1)}
                                    printButtonLabel={reprint ? 'Print / Reprint' : 'Print label'}
                                />
                                <Descriptions size="small" column={1} style={{ marginTop: 12 }}>
                                    <Descriptions.Item label="Supplier lot">
                                        {label.lot.supplier_lot_no ?? `Lot #${label.lot.id}`}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="Original">{fmtKg(label.bag.original_kg)}</Descriptions.Item>
                                    <Descriptions.Item label="Remaining">{fmtKg(label.bag.remaining_kg)}</Descriptions.Item>
                                    <Descriptions.Item label="Registered">
                                        {fmtWhen(label.bag.created_at ?? label.lot.created_at)}
                                    </Descriptions.Item>
                                </Descriptions>
                            </Card>
                        </Col>
                    );
                })}
            </Row>
        </Space>
    );
}
