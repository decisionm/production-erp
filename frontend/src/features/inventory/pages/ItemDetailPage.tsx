import { useQuery } from '@tanstack/react-query';
import { Descriptions, Table, Tabs, Tag, Typography } from 'antd';
import { useMemo } from 'react';
import { useParams } from 'react-router-dom';
import { listItems, listStockBalances, listStockMovements } from '@/features/inventory/api';
import type { ItemTrackingType, StockMovement } from '@/features/inventory/types';

const trackingTypeColor: Record<ItemTrackingType, string> = {
    none: 'default',
    batch: 'blue',
    serial: 'purple',
};

const movementTypeColor: Record<string, string> = {
    receipt: 'green',
    issue: 'red',
    transfer_in: 'blue',
    transfer_out: 'orange',
};

type MovementCategory = 'procurement' | 'sales' | 'production' | 'maintenance' | 'manual';

// Every automatic stock movement in the app is tagged with a consistent,
// server-generated reference prefix by whichever module caused it (GRN for
// PO, Delivery for SO, WO/SCO/RWO for Production, MWO for Maintenance) — see
// StockMovementService callers. Manual Stock page adjustments carry no such
// prefix, or a free-text one a user typed, so anything unrecognized falls
// back to "manual" rather than being miscategorized.
function categorize(reference: string | null): MovementCategory {
    if (!reference) return 'manual';
    if (reference.startsWith('GRN')) return 'procurement';
    if (reference.startsWith('Delivery')) return 'sales';
    if (reference.startsWith('WO #') || reference.startsWith('SCO') || reference.startsWith('RWO')) return 'production';
    if (reference.startsWith('MWO')) return 'maintenance';
    return 'manual';
}

const movementColumns = [
    {
        title: 'Date',
        dataIndex: 'movement_date',
        render: (d: string) => d.slice(0, 10),
    },
    {
        title: 'Type',
        dataIndex: 'type',
        render: (type: string) => <Tag color={movementTypeColor[type]}>{type}</Tag>,
    },
    { title: 'Warehouse', render: (_: unknown, row: StockMovement) => row.warehouse.code },
    { title: 'Quantity', dataIndex: 'quantity' },
    { title: 'Unit Cost', dataIndex: 'unit_cost' },
    { title: 'Reference', dataIndex: 'reference' },
    { title: 'Notes', dataIndex: 'notes', render: (n: string | null) => n ?? '—' },
];

function MovementTable({ movements, emptyText }: { movements: StockMovement[]; emptyText: string }) {
    if (movements.length === 0) {
        return <Typography.Text type="secondary">{emptyText}</Typography.Text>;
    }
    return (
        <Table<StockMovement>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={movements}
            scroll={{ x: 'max-content' }}
            columns={movementColumns}
        />
    );
}

export default function ItemDetailPage() {
    const { id } = useParams<{ id: string }>();
    const itemId = Number(id);

    const { data: items, isLoading: itemsLoading } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const item = items?.data.find((i) => i.id === itemId);

    const { data: balances } = useQuery({ queryKey: ['inventory', 'stock-balances'], queryFn: listStockBalances });
    const itemBalances = balances?.data.filter((b) => b.item.id === itemId) ?? [];

    const { data: movements, isLoading: movementsLoading } = useQuery({
        queryKey: ['inventory', 'stock-movements', itemId],
        queryFn: () => listStockMovements({ item_id: itemId, per_page: 300 }),
        enabled: !Number.isNaN(itemId),
    });

    const byCategory = useMemo(() => {
        const groups: Record<MovementCategory, StockMovement[]> = {
            procurement: [],
            sales: [],
            production: [],
            maintenance: [],
            manual: [],
        };
        for (const m of movements?.data ?? []) {
            groups[categorize(m.reference)].push(m);
        }
        return groups;
    }, [movements]);

    if (itemsLoading) {
        return <Typography.Text type="secondary">Loading…</Typography.Text>;
    }
    if (!item) {
        return <Typography.Text type="danger">Item not found.</Typography.Text>;
    }

    const allMovements = movements?.data ?? [];

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>
                {item.sku} — {item.name}
            </Typography.Title>
            <Typography.Paragraph type="secondary">
                Inventory summary and full transaction history for this item, across every module that
                touches stock.
            </Typography.Paragraph>

            <Descriptions column={2} size="small" bordered style={{ marginBottom: 24 }}>
                <Descriptions.Item label="SKU">{item.sku}</Descriptions.Item>
                <Descriptions.Item label="Name">{item.name}</Descriptions.Item>
                <Descriptions.Item label="UOM">{item.uom}</Descriptions.Item>
                <Descriptions.Item label="HSN/SAC">{item.hsn_sac_code ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Reorder Level">{item.reorder_level}</Descriptions.Item>
                <Descriptions.Item label="Tracking Type">
                    <Tag color={trackingTypeColor[item.tracking_type]}>{item.tracking_type}</Tag>
                </Descriptions.Item>
                <Descriptions.Item label="Active" span={2}>
                    {item.is_active ? 'Yes' : 'No'}
                </Descriptions.Item>
            </Descriptions>

            <Typography.Title level={5}>Stock by Warehouse</Typography.Title>
            {itemBalances.length > 0 ? (
                <Table
                    rowKey="id"
                    size="small"
                    pagination={false}
                    dataSource={itemBalances}
                    style={{ marginBottom: 24 }}
                    columns={[
                        { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                        { title: 'Quantity', dataIndex: 'quantity' },
                        { title: 'Avg. Cost', dataIndex: 'average_cost' },
                    ]}
                />
            ) : (
                <Typography.Paragraph type="secondary">No stock recorded for this item yet.</Typography.Paragraph>
            )}

            <Typography.Title level={5}>Transaction History</Typography.Title>
            <Tabs
                items={[
                    {
                        key: 'all',
                        label: `All (${allMovements.length})`,
                        children: (
                            <MovementTable movements={allMovements} emptyText="No transactions recorded for this item yet." />
                        ),
                    },
                    {
                        key: 'procurement',
                        label: `Purchase Orders (${byCategory.procurement.length})`,
                        children: (
                            <MovementTable
                                movements={byCategory.procurement}
                                emptyText="No goods receipts recorded against this item yet."
                            />
                        ),
                    },
                    {
                        key: 'sales',
                        label: `Sales (${byCategory.sales.length})`,
                        children: (
                            <MovementTable movements={byCategory.sales} emptyText="No deliveries recorded for this item yet." />
                        ),
                    },
                    {
                        key: 'production',
                        label: `Production (${byCategory.production.length})`,
                        children: (
                            <MovementTable
                                movements={byCategory.production}
                                emptyText="No work orders, subcontract orders, or rework orders for this item yet."
                            />
                        ),
                    },
                    {
                        key: 'maintenance',
                        label: `Maintenance (${byCategory.maintenance.length})`,
                        children: (
                            <MovementTable
                                movements={byCategory.maintenance}
                                emptyText="This item hasn't been consumed as a maintenance spare part yet."
                            />
                        ),
                    },
                    {
                        key: 'manual',
                        label: `Manual Adjustments (${byCategory.manual.length})`,
                        children: (
                            <MovementTable
                                movements={byCategory.manual}
                                emptyText="No manual receipts, issues, or transfers recorded for this item yet."
                            />
                        ),
                    },
                ]}
                tabBarExtraContent={movementsLoading ? <Typography.Text type="secondary">Loading…</Typography.Text> : undefined}
            />
        </>
    );
}
