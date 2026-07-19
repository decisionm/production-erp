import { useQuery } from '@tanstack/react-query';
import { Alert, Button, InputNumber, Space, Table, Tabs, Tag, Typography } from 'antd';
import { useState } from 'react';
import { getGstr1Report, getInvoiceGstBreakdown } from '@/features/compliance/api';
import type { GstInvoiceLineBreakdown, Gstr1Row } from '@/features/compliance/types';

const gstr1Columns = [
    { title: 'Invoice', render: (_: unknown, row: Gstr1Row) => `INV #${row.invoice_id}` },
    { title: 'Date', dataIndex: 'invoice_date' },
    { title: 'Customer', dataIndex: 'customer_name' },
    { title: 'GSTIN', dataIndex: 'customer_gstin' },
    {
        title: 'Supply Type',
        dataIndex: 'supply_type',
        render: (type: string) => <Tag color={type === 'inter_state' ? 'purple' : 'blue'}>{type.replace('_', ' ')}</Tag>,
    },
    { title: 'Taxable Value', dataIndex: 'taxable_value' },
    { title: 'CGST', dataIndex: 'cgst' },
    { title: 'SGST', dataIndex: 'sgst' },
    { title: 'IGST', dataIndex: 'igst' },
    { title: 'Total Tax', dataIndex: 'total_tax' },
];

function Gstr1Tab() {
    const { data, isLoading } = useQuery({ queryKey: ['compliance', 'reports', 'gstr1'], queryFn: getGstr1Report });

    return (
        <div>
            {data && data.errors.length > 0 && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={`${data.errors.length} invoice(s) excluded from this return — fix before filing`}
                    description={
                        <ul style={{ margin: 0, paddingLeft: 20 }}>
                            {data.errors.map((e) => (
                                <li key={e.invoice_id}>INV #{e.invoice_id}: {e.message}</li>
                            ))}
                        </ul>
                    }
                />
            )}

            <Typography.Title level={5}>B2B (registered customers)</Typography.Title>
            <Table rowKey="invoice_id" loading={isLoading} dataSource={data?.b2b} pagination={false} columns={gstr1Columns} />

            <Typography.Title level={5} style={{ marginTop: 24 }}>B2C</Typography.Title>
            <Table rowKey="invoice_id" loading={isLoading} dataSource={data?.b2c} pagination={false} columns={gstr1Columns} />

            {data && (
                <Space direction="vertical" style={{ marginTop: 24 }}>
                    <Typography.Text strong>Totals</Typography.Text>
                    <Typography.Text>Taxable Value: {data.totals.taxable_value}</Typography.Text>
                    <Typography.Text>CGST: {data.totals.cgst}</Typography.Text>
                    <Typography.Text>SGST: {data.totals.sgst}</Typography.Text>
                    <Typography.Text>IGST: {data.totals.igst}</Typography.Text>
                    <Typography.Text strong>Total Tax: {data.totals.total_tax}</Typography.Text>
                </Space>
            )}
        </div>
    );
}

function InvoiceBreakdownTab() {
    const [invoiceId, setInvoiceId] = useState<number | null>(null);
    const [lookupId, setLookupId] = useState<number | null>(null);

    const { data, isLoading, isError, error } = useQuery({
        queryKey: ['compliance', 'invoice-gst-breakdown', lookupId],
        queryFn: () => getInvoiceGstBreakdown(lookupId as number),
        enabled: lookupId !== null,
        retry: false,
    });

    return (
        <div>
            <Space style={{ marginBottom: 16 }}>
                <InputNumber placeholder="Invoice ID" min={1} value={invoiceId} onChange={(v) => setInvoiceId(v)} />
                <Button type="primary" disabled={!invoiceId} onClick={() => setLookupId(invoiceId)}>
                    Look Up
                </Button>
            </Space>

            {isLoading && <Typography.Text>Loading…</Typography.Text>}
            {isError && (
                <Alert
                    type="error"
                    showIcon
                    message={(error as any)?.response?.data?.message ?? 'Could not compute GST breakdown'}
                />
            )}

            {data && (
                <>
                    <Space direction="vertical" style={{ marginBottom: 16 }}>
                        <Typography.Text>Seller GSTIN: {data.seller_gstin} ({data.seller_state_code})</Typography.Text>
                        <Typography.Text>
                            Customer GSTIN: {data.customer_gstin ?? '—'} ({data.customer_state_code})
                        </Typography.Text>
                        <Tag color={data.supply_type === 'inter_state' ? 'purple' : 'blue'}>
                            {data.supply_type.replace('_', ' ')}
                        </Tag>
                    </Space>

                    <Table<GstInvoiceLineBreakdown>
                        scroll={{ x: 'max-content' }}
                        rowKey="item_id"
                        dataSource={data.lines}
                        pagination={false}
                        columns={[
                            { title: 'HSN/SAC', dataIndex: 'hsn_sac_code' },
                            { title: 'Taxable Value', dataIndex: 'taxable_value' },
                            { title: 'Rate %', dataIndex: 'rate_percent' },
                            { title: 'CGST', dataIndex: 'cgst' },
                            { title: 'SGST', dataIndex: 'sgst' },
                            { title: 'IGST', dataIndex: 'igst' },
                            { title: 'Total', dataIndex: 'total' },
                        ]}
                    />

                    <Space direction="vertical" style={{ marginTop: 16 }}>
                        <Typography.Text strong>Grand Total: {data.totals.grand_total}</Typography.Text>
                    </Space>
                </>
            )}
        </div>
    );
}

export default function GstReportsPage() {
    return (
        <>
            <Typography.Title level={3}>GST Reports</Typography.Title>
            <Tabs
                items={[
                    { key: 'gstr1', label: 'GSTR-1', children: <Gstr1Tab /> },
                    { key: 'invoice-breakdown', label: 'Invoice Breakdown', children: <InvoiceBreakdownTab /> },
                ]}
            />
        </>
    );
}
