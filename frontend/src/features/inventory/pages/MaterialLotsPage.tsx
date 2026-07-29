import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Drawer, Empty, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import MaterialBagLabels from '@/features/inventory/components/MaterialBagLabels';
import { listAllItems } from '@/features/inventory/api';
import { listMaterialLots } from '@/features/production/api';
import type { MaterialLot } from '@/features/production/types';

function fmtKg(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = Number(value);
    return Number.isFinite(parsed) ? String(parseFloat(parsed.toFixed(4))) : '—';
}

export default function MaterialLotsPage() {
    const [itemId, setItemId] = useState<number | null>(null);
    const [page, setPage] = useState(1);
    const [labelSelection, setLabelSelection] = useState<{ lot: MaterialLot; bagId?: number } | null>(null);

    const { data: items } = useQuery({
        queryKey: ['inventory', 'items', 'all'],
        queryFn: listAllItems,
    });
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['inventory', 'material-lots', itemId, page],
        queryFn: () => listMaterialLots({ item_id: itemId ?? undefined, page }),
        retry: false,
    });

    if (isError && (error as any)?.response?.status === 404) {
        return (
            <>
                <Typography.Title level={3}>Material Receipts &amp; Bag Labels</Typography.Title>
                <Empty
                    description="Lot and bag traceability is not enabled for this deployment. No receipt or stock is changed here."
                />
            </>
        );
    }

    const itemOptions =
        items?.data
            .filter((item) => item.is_active)
            .map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];

    return (
        <>
            <Space direction="vertical" size={12} style={{ width: '100%' }}>
                <Space style={{ width: '100%', justifyContent: 'space-between' }} wrap>
                    <div>
                        <Typography.Title level={3} style={{ marginBottom: 4 }}>
                            Material Receipts &amp; Bag Labels
                        </Typography.Title>
                        <Typography.Text type="secondary">
                            Reopen any received supplier lot to print or reprint its physical bag labels.
                        </Typography.Text>
                    </div>
                    <Button onClick={() => refetch()}>Refresh</Button>
                </Space>

                <Alert
                    type="info"
                    showIcon
                    message="Receiving happens from Goods Receipts"
                    description="This page is the permanent label and remaining-kg register. Creating or printing a label here does not consume material and does not post anything to Tally."
                />
                {isError && (
                    <Alert
                        type="error"
                        showIcon
                        message="Could not load the material-lot register"
                        description={(error as any)?.response?.data?.message ?? 'Refresh after checking your inventory access.'}
                    />
                )}

                <Select
                    value={itemId ?? undefined}
                    onChange={(value) => {
                        setItemId(value ?? null);
                        setPage(1);
                    }}
                    options={itemOptions}
                    placeholder="Filter by material"
                    showSearch
                    optionFilterProp="label"
                    allowClear
                    style={{ width: 'min(100%, 420px)' }}
                />

                <Table<MaterialLot>
                    rowKey="id"
                    loading={isLoading}
                    dataSource={data?.data ?? []}
                    scroll={{ x: 'max-content' }}
                    pagination={
                        data?.meta
                            ? {
                                  current: data.meta.current_page,
                                  pageSize: data.meta.per_page,
                                  total: data.meta.total,
                                  showSizeChanger: false,
                                  onChange: setPage,
                              }
                            : false
                    }
                    expandable={{
                        expandedRowRender: (lot) => (
                            <Table
                                size="small"
                                rowKey="id"
                                pagination={false}
                                dataSource={lot.bags ?? []}
                                columns={[
                                    { title: 'Barcode', dataIndex: 'barcode' },
                                    { title: 'Original kg', dataIndex: 'original_kg', align: 'right', render: fmtKg },
                                    { title: 'Remaining kg', dataIndex: 'remaining_kg', align: 'right', render: fmtKg },
                                    {
                                        title: 'Status',
                                        dataIndex: 'status',
                                        render: (status: string) => <Tag>{status.replaceAll('_', ' ')}</Tag>,
                                    },
                                    {
                                        title: 'Registered',
                                        dataIndex: 'created_at',
                                        render: (value: string | null | undefined) =>
                                            value ? new Date(value).toLocaleString() : '—',
                                    },
                                    {
                                        title: 'Label',
                                        render: (_, bag) => (
                                            <Button
                                                size="small"
                                                onClick={() => setLabelSelection({ lot, bagId: bag.id })}
                                            >
                                                Print / Reprint
                                            </Button>
                                        ),
                                    },
                                ]}
                            />
                        ),
                    }}
                    columns={[
                        { title: 'GRN', dataIndex: 'grn_id', render: (value: number | null) => (value ? `#${value}` : '—') },
                        { title: 'Material', render: (_, lot) => (lot.item ? `${lot.item.sku} — ${lot.item.name}` : '—') },
                        { title: 'Supplier lot', dataIndex: 'supplier_lot_no', render: (value: string | null) => value ?? '—' },
                        { title: 'Received', dataIndex: 'received_date', render: (value: string | null) => value ?? '—' },
                        { title: 'Bags', dataIndex: 'bag_count', align: 'right' },
                        { title: 'Received kg', dataIndex: 'total_received_kg', align: 'right', render: fmtKg },
                        {
                            title: 'Labels',
                            render: (_, lot) => (
                                <Button size="small" onClick={() => setLabelSelection({ lot })}>
                                    Print / Reprint
                                </Button>
                            ),
                        },
                    ]}
                />
            </Space>

            <Drawer
                title={`Bag labels — ${labelSelection?.lot.supplier_lot_no ?? (labelSelection ? `Lot #${labelSelection.lot.id}` : '')}`}
                open={labelSelection !== null}
                onClose={() => setLabelSelection(null)}
                width={900}
                destroyOnHidden
            >
                {labelSelection && (
                    <MaterialBagLabels
                        lots={[labelSelection.lot]}
                        bagId={labelSelection.bagId}
                        reprint
                    />
                )}
            </Drawer>
        </>
    );
}
