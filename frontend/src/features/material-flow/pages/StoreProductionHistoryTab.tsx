import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Select, Space, Statistic, Table, Tag, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { listAllWarehouses } from '@/features/inventory/api';
import type { StockMovement } from '@/features/inventory/types';
import { itemLabel } from '@/lib/itemLabel';
import { ListEmpty } from '@/lib/ListEmpty';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { listProductionReturnable, listRequestableMaterials, listStoreProductionMovements } from '../api';
import { formatQuantity } from '../words';

/**
 * SORTED BY THE SERVER (ListStockMovementsRequest::SORTABLE): the history
 * is paged, so a sorter here re-queries the whole list rather than
 * reordering the twenty rows on screen. Direction is the movement's `type`
 * read against the WIP leg, so that column sorts on `type`.
 */
const HISTORY_SORT_FIELDS: readonly string[] = ['movement_date', 'type', 'quantity'];
const HISTORY_DEFAULT_SORT = '-movement_date';

/**
 * ONE HISTORY FOR BOTH DIRECTIONS — every handover out to production and
 * every return back to the store, in the order they happened.
 *
 * WHAT A READER MUST NOT DO WITH THIS LIST, and the reason the standing
 * figures sit above it. A list of signed movements invites subtraction:
 * issue 100 kg, return 20, and the arithmetic on screen says 80 kg is still
 * standing in production. It usually is not — the batch consumed most of it,
 * and consumption is a different event that this list deliberately does not
 * carry (see listStoreProductionMovements for why including it would be
 * worse than leaving it out). So what is ACTUALLY standing is read from the
 * balance, the same read the Returns tab shows, and printed above the rows.
 * The list answers "what happened"; the figures answer "what is left". A
 * reader never has to derive the second from the first.
 *
 * The figures are per material, because they have to be: `on_floor` is a
 * quantity in that material's own unit, and adding kilograms of resin to a
 * count of caps would produce a number that means nothing. With no material
 * chosen the history is still the whole story — there is simply no single
 * standing figure to print, and inventing one is the failure mode above.
 */
export default function StoreProductionHistoryTab() {
    const [itemId, setItemId] = useState<number | undefined>();
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    // Component state, not the URL: this tab shares its route with the
    // issue queue, whose own list params already own `page` there.
    const [sort, setSort] = useState<string | undefined>();

    const warehouses = useQuery({
        queryKey: ['inventory', 'warehouses', 'return-destinations'],
        queryFn: listAllWarehouses,
    });

    /**
     * The Production/WIP row, from the warehouses index's own meta — the
     * same source the Returns tab's destination dropdown uses, so both
     * halves of this screen agree on which row production is.
     */
    const wipWarehouseId = warehouses.data?.meta?.production_wip_warehouse_id ?? null;

    const materials = useQuery({ queryKey: ['material-flow', 'materials'], queryFn: listRequestableMaterials });

    /**
     * The authoritative standing balance. Keyed identically to the Returns
     * tab's own read (`search` there starts empty), so opening both tabs
     * costs one request, and a return recorded on that tab refreshes this
     * figure through the same invalidation.
     */
    const floor = useQuery({
        queryKey: ['material-flow', 'production-returnable', ''],
        queryFn: () => listProductionReturnable(undefined),
    });

    const movements = useQuery({
        queryKey: ['material-flow', 'store-production-movements', wipWarehouseId, itemId, sort, page, perPage],
        queryFn: () =>
            listStoreProductionMovements({
                wipWarehouseId: wipWarehouseId as number,
                itemId,
                sort,
                page,
                perPage,
            }),
        enabled: wipWarehouseId !== null,
    });

    const standing = useMemo(
        () => (itemId ? (floor.data ?? []).find((row) => row.item_id === itemId) : undefined),
        [floor.data, itemId],
    );

    const materialOptions = useMemo(
        () =>
            (materials.data ?? []).map((material) => ({
                value: material.id,
                label: itemLabel(material),
            })),
        [materials.data],
    );

    /**
     * WIP UNRESOLVED IS NOT AN EMPTY HISTORY. Without the setting there is no
     * leg to filter on, so the honest answer is that the question cannot be
     * asked yet — not a clean table implying nothing has ever moved.
     */
    if (warehouses.isSuccess && wipWarehouseId === null) {
        return (
            <Alert
                type="warning"
                showIcon
                message="Production/WIP is not configured"
                description="No warehouse is set as Production/WIP, so the ERP cannot tell which movements are handovers to production. Set it in Inventory → Warehouses; the history appears as soon as it resolves."
            />
        );
    }

    const columns = [
        {
            title: 'When',
            dataIndex: 'movement_date',
            key: 'movement_date',
            width: 160,
            sorter: true,
            sortOrder: columnSortOrder('movement_date', sort, HISTORY_DEFAULT_SORT),
            render: (value: string | null) => (value ? new Date(value).toLocaleString() : '—'),
        },
        {
            title: 'Material',
            key: 'item',
            render: (_: unknown, row: StockMovement) => itemLabel(row.item),
        },
        {
            /**
             * Direction read off `type` against the Production/WIP leg, which
             * is the one leg this list selects: material arriving INTO
             * production is the handover, material leaving it is the return.
             * Named in the flow's own words rather than shown as a raw
             * transfer_in / transfer_out, which say nothing to a storekeeper.
             */
            title: 'Direction',
            key: 'type',
            width: 190,
            sorter: true,
            sortOrder: columnSortOrder('type', sort, HISTORY_DEFAULT_SORT),
            render: (_: unknown, row: StockMovement) =>
                row.type === 'transfer_in' ? (
                    <Tag color="blue">Issued to production</Tag>
                ) : (
                    <Tag color="green">Returned to store</Tag>
                ),
        },
        {
            title: 'Quantity',
            key: 'quantity',
            width: 130,
            align: 'right' as const,
            sorter: true,
            sortOrder: columnSortOrder('quantity', sort, HISTORY_DEFAULT_SORT),
            render: (_: unknown, row: StockMovement) => formatQuantity(row.quantity, row.item?.uom),
        },
        { title: 'Reference', dataIndex: 'reference', width: 200 },
        {
            title: 'Recorded by',
            dataIndex: 'recorded_by',
            width: 160,
            render: (value: string | null | undefined) => value ?? '—',
        },
    ];

    return (
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            <Card size="small">
                <Space wrap align="center" size="large">
                    <Select
                        allowClear
                        showSearch
                        optionFilterProp="label"
                        placeholder="All materials"
                        options={materialOptions}
                        value={itemId}
                        onChange={(value) => {
                            setItemId(value);
                            setPage(1);
                        }}
                        style={{ width: 260 }}
                    />

                    {standing ? (
                        <>
                            <Statistic
                                title="In production now"
                                value={formatQuantity(standing.on_floor, standing.uom)}
                                valueStyle={{ fontSize: 18 }}
                            />
                            <Statistic
                                title="Held by a store issue"
                                value={formatQuantity(standing.attributed, standing.uom)}
                                valueStyle={{ fontSize: 18 }}
                            />
                            <Statistic
                                title="Free to return"
                                value={formatQuantity(standing.unattributed, standing.uom)}
                                valueStyle={{ fontSize: 18 }}
                            />
                        </>
                    ) : (
                        <Typography.Text type="secondary">
                            {itemId
                                ? 'Nothing of this material is standing in production.'
                                : 'Choose a material to see what is still standing in production.'}
                        </Typography.Text>
                    )}
                </Space>

                <Typography.Paragraph type="secondary" style={{ marginBottom: 0, marginTop: 12 }}>
                    This is every handover and every return, newest first. It does not list what production consumed, so
                    the rows below do not add up to what is standing — the figures above are the answer to that.
                </Typography.Paragraph>
            </Card>

            <Table<StockMovement>
                rowKey="id"
                size="small"
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                columns={columns}
                dataSource={movements.data?.data ?? []}
                loading={movements.isPending}
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setSort(sortParamFromSorter(sorter, HISTORY_SORT_FIELDS, HISTORY_DEFAULT_SORT));
                    setPage(1);
                }}
                pagination={serverPagination(
                    movements.data?.meta,
                    (nextPage, nextSize) => {
                        setPage(nextPage);
                        setPerPage(nextSize);
                    },
                    'movements',
                )}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={movements}
                            entity="movements"
                            empty="Nothing has moved between the store and production yet."
                        />
                    ),
                }}
            />
        </Space>
    );
}
