import { useQuery } from '@tanstack/react-query';
import { Alert, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { listAllItems, listAllWarehouses, listStockMovements } from '@/features/inventory/api';
import {
    movementPurposeLabel,
    movementTypeTone,
    purchaseOrderIdIn,
    stockLedgerParams,
} from '@/features/inventory/stockLedger';
import type { StockMovement } from '@/features/inventory/types';
import { formatDateTime } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';

/**
 * THE STOCK LEDGER, first-class.
 *
 * Every movement in the factory, newest first, across every item and every
 * warehouse. It reads `GET /inventory/stock-movements` — the same endpoint the
 * item detail page's history tabs and the Stock page's per-row drawer read, and
 * the same `StockMovement` shape. Those two answer "what happened to THIS
 * item"; this one answers "what happened", which is the question nothing in the
 * app could be asked until now.
 *
 * IT WRITES NOTHING. Receiving, issuing and transferring stay on the Stock
 * page, where the balance being changed is on screen beside the form.
 *
 * TWO FILTERS, NOT SIX. The endpoint filters on item and warehouse and nothing
 * else, and this table is SERVER-PAGED: a type, purpose or date control here
 * would filter the twenty rows on screen and hide every match on the other
 * pages, which is worse than not offering it. stockLedger.ts holds that rule
 * and the test that pins it.
 *
 * NO COST COLUMN. `StockMovementResource` omits unit_cost for anyone without
 * finance access (FC-06) and the two screens that do show it are per-item, with
 * the item's own purchase history in view. A factory-wide rate column is a
 * different decision from a factory-wide ledger, and it is not this one's.
 *
 * NO "BY" COLUMN. `stock_movements.created_by` is recorded but
 * StockMovementResource does not serve it, so there is no honest way to render
 * who made a movement from here — and a column of em dashes claiming otherwise
 * is worse than its absence.
 */
export default function StockMovementsPage() {
    const [itemId, setItemId] = useState<number | null>(null);
    const [warehouseId, setWarehouseId] = useState<number | null>(null);
    const [page, setPage] = useState(1);

    const { data: items } = useQuery({
        queryKey: ['inventory', 'items', 'all'],
        queryFn: listAllItems,
    });
    const { data: warehouses } = useQuery({
        queryKey: ['inventory', 'warehouses', 'all'],
        queryFn: listAllWarehouses,
    });

    const { data, isLoading, isError } = useQuery({
        queryKey: ['inventory', 'stock-movements', 'ledger', itemId, warehouseId, page],
        queryFn: () => listStockMovements(stockLedgerParams({ itemId, warehouseId, page })),
    });

    // The pickers list what a person can still choose today. A movement
    // against an item or a warehouse that has since been retired keeps showing
    // in the table — the ledger is append-only and history does not shrink
    // when a master is archived.
    const itemOptions =
        items?.data.filter((item) => item.is_active).map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];
    const warehouseOptions =
        warehouses?.data
            .filter((warehouse) => warehouse.is_active)
            .map((warehouse) => ({ value: warehouse.id, label: `${warehouse.code} — ${warehouse.name}` })) ?? [];

    return (
        <>
            <Typography.Title level={3}>Stock Movements</Typography.Title>

            <Space wrap style={{ marginBottom: 16 }}>
                <Select
                    value={itemId ?? undefined}
                    onChange={(value) => {
                        setItemId(value ?? null);
                        setPage(1);
                    }}
                    options={itemOptions}
                    placeholder="All items"
                    showSearch
                    optionFilterProp="label"
                    allowClear
                    style={{ width: 'min(100%, 360px)' }}
                />
                <Select
                    value={warehouseId ?? undefined}
                    onChange={(value) => {
                        setWarehouseId(value ?? null);
                        setPage(1);
                    }}
                    options={warehouseOptions}
                    placeholder="All warehouses"
                    showSearch
                    optionFilterProp="label"
                    allowClear
                    style={{ width: 'min(100%, 280px)' }}
                />
            </Space>

            {/* A failed read and an empty ledger look identical in a table.
                On a factory with a real history the second one is a lie, so
                the failure is named. */}
            {isError && (
                <Alert
                    type="error"
                    showIcon
                    message="Could not load the stock ledger"
                    style={{ marginBottom: 16 }}
                />
            )}

            <Table<StockMovement>
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
                columns={[
                    {
                        title: 'Date',
                        dataIndex: 'movement_date',
                        render: (value: string) => formatDateTime(value),
                    },
                    {
                        title: 'Item',
                        render: (_, row) => (
                            <Link to={`/inventory/items/${row.item.id}`}>{itemLabel(row.item)}</Link>
                        ),
                    },
                    { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                    {
                        title: 'Type',
                        dataIndex: 'type',
                        render: (type: string) => <Tag color={movementTypeTone(type)}>{type.replaceAll('_', ' ')}</Tag>,
                    },
                    {
                        title: 'Purpose',
                        dataIndex: 'purpose',
                        // A dash here means the row predates the purpose column;
                        // "Not stated" means the writer had one to give and did
                        // not. Two different facts, two different cells.
                        render: (purpose: string | null | undefined) => {
                            const label = movementPurposeLabel(purpose);
                            return label ? <Tag color={label.tone}>{label.text}</Tag> : '—';
                        },
                    },
                    { title: 'Quantity', dataIndex: 'quantity', align: 'right' },
                    {
                        title: 'Reference',
                        dataIndex: 'reference',
                        render: (reference: string | null) => {
                            if (!reference) return '—';
                            const purchaseOrder = purchaseOrderIdIn(reference);

                            return purchaseOrder ? (
                                <Link to={`/procurement/goods-receipts?po=${purchaseOrder}`}>{reference}</Link>
                            ) : (
                                reference
                            );
                        },
                    },
                    { title: 'Notes', dataIndex: 'notes', render: (notes: string | null) => notes ?? '—' },
                ]}
            />
        </>
    );
}
