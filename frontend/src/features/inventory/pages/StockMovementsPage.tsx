import { useQuery } from '@tanstack/react-query';
import { Button, Input, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listAllItems, listAllWarehouses, listStockMovements } from '@/features/inventory/api';
import {
    STOCK_LEDGER_DEFAULT_SORT,
    STOCK_LEDGER_SORT_FIELDS,
    STOCK_LEDGER_SPEC,
    type StockLedgerListParams,
    ledgerNoMatchLine,
    movementPurposeLabel,
    movementTypeTone,
    purchaseOrderIdIn,
    stockLedgerParams,
} from '@/features/inventory/stockLedger';
import { stockRange } from '@/features/inventory/stockList';
import type { StockMovement } from '@/features/inventory/types';
import { formatDateTime } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { narrowingKeys } from '@/lib/listParams';
import { TABLE_STICKY, rangeLine, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

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
 * THREE CONTROLS, NOT SIX. The endpoint filters on item, warehouse, purpose
 * and a reference needle (`q`) and nothing else, and this table is
 * SERVER-PAGED: a type or date control here would filter the twenty rows on
 * screen and hide every match on the other pages, which is worse than not
 * offering it. stockLedger.ts holds that rule and the test that pins it.
 *
 * SORTED BY THE SERVER, for the same reason. Date, Type, Purpose and
 * Quantity carry sortOrder-controlled sorters that re-query the whole ledger
 * (ListStockMovementsRequest::SORTABLE); antd never sorts the loaded page.
 *
 * THE URL IS THE LIST'S STATE (useListParams): item, warehouse, needle, sort,
 * page and page size, so a refresh, Back or a pasted link lands on the same view.
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
    const { params, setParams, setPage } = useListParams<StockLedgerListParams>(STOCK_LEDGER_SPEC);
    const request = stockLedgerParams({
        itemId: params.item_id,
        warehouseId: params.warehouse_id,
        q: params.q,
        sort: params.sort,
        page: params.page,
        perPage: params.per_page,
    });
    const narrowed = narrowingKeys(params).length > 0;
    const narrowedByPickers = params.item_id !== undefined || params.warehouse_id !== undefined;

    // The search box's text as typed; it becomes `q` on Enter / the search
    // button, so a half-typed number does not fire a request per keystroke.
    // Re-seeded when the URL's q changes under it (Back, a pasted link).
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => {
        setQDraft(params.q ?? '');
    }, [params.q]);

    const clearNarrowing = () => setParams({ q: undefined, item_id: undefined, warehouse_id: undefined });

    const { data: items } = useQuery({
        queryKey: ['inventory', 'items', 'all'],
        queryFn: listAllItems,
    });
    const { data: warehouses } = useQuery({
        queryKey: ['inventory', 'warehouses', 'all'],
        queryFn: listAllWarehouses,
    });

    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        queryKey: ['inventory', 'stock-movements', 'ledger', request],
        queryFn: () => listStockMovements(request),
        // Stale rows stay on screen while the next page loads; ListReadAlert
        // below names a failed refetch, since emptyText then cannot.
        placeholderData: (previous) => previous,
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

            <Space wrap style={{ marginBottom: 12 }}>
                <Select<number>
                    value={params.item_id}
                    onChange={(value) => setParams({ item_id: value ?? undefined })}
                    options={itemOptions}
                    placeholder="All items"
                    showSearch
                    optionFilterProp="label"
                    allowClear
                    style={{ width: 'min(100%, 360px)' }}
                />
                <Select<number>
                    value={params.warehouse_id}
                    onChange={(value) => setParams({ warehouse_id: value ?? undefined })}
                    options={warehouseOptions}
                    placeholder="All warehouses"
                    showSearch
                    optionFilterProp="label"
                    allowClear
                    style={{ width: 'min(100%, 280px)' }}
                />
                <Input.Search
                    allowClear
                    placeholder="Reference"
                    style={{ width: 240 }}
                    value={qDraft}
                    onChange={(event) => setQDraft(event.target.value)}
                    onSearch={(value) => setParams({ q: value.trim() || undefined })}
                />
            </Space>

            {data?.meta && (
                <Space size={4} style={{ marginBottom: 12 }}>
                    <Typography.Text type="secondary">
                        {rangeLine(data.meta.total, stockRange(data.meta), narrowed ? 'matching movements' : 'movements')}
                    </Typography.Text>
                    {narrowed && (
                        <Button type="link" size="small" onClick={clearNarrowing}>
                            Clear
                        </Button>
                    )}
                </Space>
            )}

            {/* A failed read and an empty ledger look identical in a table.
                On a factory with a real history the second one is a lie, so
                the failure is named — above the stale rows placeholderData
                keeps on screen, where emptyText cannot show it. */}
            <ListReadAlert state={{ isPending, isError, error, refetch }} entity="stock movements" />

            <Table<StockMovement>
                sticky={TABLE_STICKY}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data ?? []}
                scroll={{ x: 'max-content' }}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={{ isPending, isError, error, refetch }}
                            entity="stock movements"
                            empty={
                                narrowed ? (
                                    <Space direction="vertical" size={8} style={{ padding: '16px 0' }}>
                                        <Typography.Text>{ledgerNoMatchLine(params.q, narrowedByPickers)}</Typography.Text>
                                        <Button size="small" onClick={clearNarrowing}>
                                            Clear
                                        </Button>
                                    </Space>
                                ) : (
                                    'No stock movements yet.'
                                )
                            }
                        />
                    ),
                }}
                pagination={serverPagination(data?.meta, setPage, 'movements')}
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, STOCK_LEDGER_SORT_FIELDS, STOCK_LEDGER_DEFAULT_SORT) });
                }}
                columns={[
                    {
                        title: 'Date',
                        dataIndex: 'movement_date',
                        key: 'movement_date',
                        sorter: true,
                        sortOrder: columnSortOrder('movement_date', params.sort, STOCK_LEDGER_DEFAULT_SORT),
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
                        key: 'type',
                        sorter: true,
                        sortOrder: columnSortOrder('type', params.sort, STOCK_LEDGER_DEFAULT_SORT),
                        render: (type: string) => <Tag color={movementTypeTone(type)}>{type.replaceAll('_', ' ')}</Tag>,
                    },
                    {
                        title: 'Purpose',
                        dataIndex: 'purpose',
                        key: 'purpose',
                        sorter: true,
                        sortOrder: columnSortOrder('purpose', params.sort, STOCK_LEDGER_DEFAULT_SORT),
                        // A dash here means the row predates the purpose column;
                        // "Not stated" means the writer had one to give and did
                        // not. Two different facts, two different cells.
                        render: (purpose: string | null | undefined) => {
                            const label = movementPurposeLabel(purpose);
                            return label ? <Tag color={label.tone}>{label.text}</Tag> : '—';
                        },
                    },
                    {
                        title: 'Quantity',
                        dataIndex: 'quantity',
                        key: 'quantity',
                        align: 'right',
                        sorter: true,
                        sortOrder: columnSortOrder('quantity', params.sort, STOCK_LEDGER_DEFAULT_SORT),
                    },
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
