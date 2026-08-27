import { useQuery } from '@tanstack/react-query';
import { Descriptions, Table, Tabs, Tag, Typography } from 'antd';
import { useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { getItem, listItemStockBalances, listStockMovements } from '@/features/inventory/api';
import { purchaseOrderIdIn } from '@/features/inventory/stockLedger';
import type { ItemTrackingType, StockMovement } from '@/features/inventory/types';
import { formatDateTime } from '@/lib/datetime';

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

// A goods receipt names the order it was received against ("GRN for PO #4"),
// and keeps doing so when the store team typed their own reference containing
// it. That number is the only handle back to the document, so the reference
// links to the goods receipts for that order. Purely a display rule — the
// stored reference text and the tab grouping above are untouched.
//
// The pattern itself lives in stockLedger.ts, with a test, and is read from
// there rather than restated here: it has to track a reference string the
// SERVER writes, and a second copy is a second place to forget when that
// format changes.
function ReferenceCell({ reference }: { reference: string | null }) {
    if (!reference) return <>—</>;

    const purchaseOrder = purchaseOrderIdIn(reference);
    if (purchaseOrder) {
        return <Link to={`/procurement/goods-receipts?po=${purchaseOrder}`}>{reference}</Link>;
    }

    return <>{reference}</>;
}

// The Unit Cost column exists only when `showsUnitCost` — see the page body
// for the rule (finance access AND the key actually present on the rows).
const movementColumns = (showsUnitCost: boolean) => [
    {
        title: 'Date',
        dataIndex: 'movement_date',
        render: (d: string) => formatDateTime(d),
    },
    {
        title: 'Type',
        dataIndex: 'type',
        render: (type: string) => <Tag color={movementTypeColor[type]}>{type}</Tag>,
    },
    { title: 'Warehouse', render: (_: unknown, row: StockMovement) => row.warehouse.code },
    { title: 'Quantity', dataIndex: 'quantity' },
    ...(showsUnitCost ? [{ title: 'Unit Cost', dataIndex: 'unit_cost' }] : []),
    {
        title: 'Reference',
        dataIndex: 'reference',
        render: (reference: string | null) => <ReferenceCell reference={reference} />,
    },
    { title: 'Notes', dataIndex: 'notes', render: (n: string | null) => n ?? '—' },
];

function MovementTable({
    movements,
    emptyText,
    showsUnitCost,
}: {
    movements: StockMovement[];
    emptyText: string;
    showsUnitCost: boolean;
}) {
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
            columns={movementColumns(showsUnitCost)}
        />
    );
}

export default function ItemDetailPage() {
    const { id } = useParams<{ id: string }>();
    const itemId = Number(id);
    const hasValidId = !Number.isNaN(itemId);
    const user = useAuthStore((s) => s.user);
    const financeAccess = hasModuleAccess(user, 'finance');

    // Loaded by id. This used to search the first page of the items list, so
    // with 600+ items in the master almost every item reported "not found".
    const { data: item, isLoading: itemLoading } = useQuery({
        queryKey: ['inventory', 'item', itemId],
        queryFn: () => getItem(itemId),
        enabled: hasValidId,
        retry: false,
    });

    // Asked for BY ID. This used to fetch the general balances list and pick
    // its rows out of it in the browser — but that list is paged, so past
    // twenty balances an item's own page showed no stock at all.
    const { data: balances } = useQuery({
        queryKey: ['inventory', 'stock-balances', 'for-item', itemId],
        queryFn: () => listItemStockBalances(itemId),
        enabled: hasValidId,
    });
    const itemBalances = balances?.data ?? [];

    const { data: movements, isLoading: movementsLoading } = useQuery({
        queryKey: ['inventory', 'stock-movements', itemId],
        queryFn: () => listStockMovements({ item_id: itemId, per_page: 300 }),
        enabled: hasValidId,
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

    if (hasValidId && itemLoading) {
        return <Typography.Text type="secondary">Loading…</Typography.Text>;
    }
    if (!item) {
        return (
            <Typography.Text type="danger">
                Item not found. Open it again from the <Link to="/inventory/items">Items list</Link>.
            </Typography.Text>
        );
    }

    const allMovements = movements?.data ?? [];

    /**
     * DO THESE ROWS CARRY RATES AT ALL — the server's answer, honoured locally
     * (the MaterialLotsPage precedent). unit_cost / average_cost are OMITTED
     * by StockMovementResource / StockBalanceResource for anyone without
     * finance access (FC-06), so their presence is the ruling that arrived
     * with the data; the permission check alongside can only make it
     * stricter. When false the cost columns do not exist — no '—' column
     * advertising a number it will not show.
     */
    const showsUnitCost = financeAccess && allMovements.some((m) => m.unit_cost !== undefined);
    const showsAverageCost = financeAccess && itemBalances.some((b) => b.average_cost !== undefined);

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>
                {item.sku} — {item.name}
            </Typography.Title>
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
                    scroll={{ x: 'max-content' }}
                    rowKey="id"
                    size="small"
                    pagination={false}
                    dataSource={itemBalances}
                    style={{ marginBottom: 24 }}
                    columns={[
                        { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                        { title: 'Quantity', dataIndex: 'quantity' },
                        ...(showsAverageCost ? [{ title: 'Avg. Cost', dataIndex: 'average_cost' }] : []),
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
                            <MovementTable showsUnitCost={showsUnitCost} movements={allMovements} emptyText="No transactions recorded for this item yet." />
                        ),
                    },
                    {
                        key: 'procurement',
                        label: `Purchase Orders (${byCategory.procurement.length})`,
                        children: (
                            <MovementTable
                                showsUnitCost={showsUnitCost}
                                movements={byCategory.procurement}
                                emptyText="No goods receipts recorded against this item yet."
                            />
                        ),
                    },
                    {
                        key: 'sales',
                        label: `Sales (${byCategory.sales.length})`,
                        children: (
                            <MovementTable showsUnitCost={showsUnitCost} movements={byCategory.sales} emptyText="No deliveries recorded for this item yet." />
                        ),
                    },
                    {
                        key: 'production',
                        label: `Production (${byCategory.production.length})`,
                        children: (
                            <MovementTable
                                showsUnitCost={showsUnitCost}
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
                                showsUnitCost={showsUnitCost}
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
                                showsUnitCost={showsUnitCost}
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
