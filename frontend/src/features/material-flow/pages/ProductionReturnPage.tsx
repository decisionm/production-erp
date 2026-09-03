import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Input, InputNumber, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { useMemo, useState } from 'react';
import { listAllWarehouses } from '@/features/inventory/api';
import { columnSorter } from '@/lib/clientSort';
import { itemLabel } from '@/lib/itemLabel';
import { ListEmpty } from '@/lib/ListEmpty';
import { TABLE_STICKY } from '@/lib/tableProps';
import { apiRefusalMessage, listProductionReturnable, recordProductionReturn } from '../api';
import type { ProductionReturnable, ReturnedQualityState } from '../types';
import { formatQuantity, permitsFractions } from '../words';

/**
 * THE DAILY RETURN — what is standing in the production area, and the way
 * home for all of it.
 *
 * The factory returns the balance to the store daily. Two things on this
 * screen carry that:
 *
 *  - EVERY MATERIAL STANDING IN PRODUCTION IS LISTED, whether a store issue
 *    put it there or not. The one that did not is exactly the case the
 *    system could not record before, and it is the majority of what is live.
 *  - THE SPLIT IS VISIBLE PER ROW. What an open handover is holding must go
 *    home against that handover's line, so its own arithmetic closes; the
 *    rest answers no document. The storekeeper sees both figures and
 *    chooses. Nothing on this screen attributes a kilogram on their behalf.
 *
 * NO BLURB, by the standing rule: the row's own numbers and the disabled
 * state say what is possible. A material with nothing free simply has no
 * free input to type in.
 */
/**
 * `embedded` — rendered as a tab of Store ↔ Production. The Card's TITLE is
 * what goes: "Return to Store" named the direction, and on a tab that
 * direction is in the tab label ("Returns from production") instead of being
 * said twice. The toolbar in `extra` is untouched — antd renders the card
 * head on `title || extra` — and so is every figure, refusal and input.
 */
/**
 * The two states a storekeeper is choosing between at the hatch — is this fit
 * to go back out, or not. The server's enum, and no third value invented on
 * this side of the wire.
 *
 * DAMAGED IS NOT A LABEL, IT IS A DESTINATION (DEC-20260901-003). A damaged
 * line does NOT go to the store picked in the dropdown above — it goes to
 * quality hold, and Quality decides afterwards whether it becomes Scrap or
 * comes back as usable stock. The store's balance does not rise by a
 * kilogram of it.
 *
 * THE DROPDOWN IS NOT DISABLED FOR A DAMAGED ROW, and that is deliberate: a
 * return can carry good and damaged lines together, so the picked store is
 * still the destination for every good line on the same document. The server
 * ignores it per damaged line rather than the screen removing it.
 *
 * WHERE IT GOES IS SAID BY THE ROW, NOT BY A SENTENCE — the Goes back to
 * column answers it per line, which is the standing rule for this screen.
 * The server also REFUSES a damaged line outright when no quality-hold
 * location is configured, and its refusal names the fix; nothing here
 * pre-empts that check, because a screen that guessed would either block a
 * working factory or promise a move the server will not make.
 */
const CONDITION_OPTIONS: { value: ReturnedQualityState; label: string }[] = [
    { value: 'good', label: 'Good' },
    { value: 'damaged', label: 'Damaged' },
];

export default function ProductionReturnPage({ embedded = false }: { embedded?: boolean }) {
    const queryClient = useQueryClient();
    const [term, setTerm] = useState('');
    const [search, setSearch] = useState('');
    const [destination, setDestination] = useState<number | undefined>();
    const [free, setFree] = useState<Record<number, number | null>>({});
    const [attributed, setAttributed] = useState<Record<number, number | null>>({});
    const [freeCondition, setFreeCondition] = useState<Record<number, ReturnedQualityState>>({});
    const [attributedCondition, setAttributedCondition] = useState<Record<number, ReturnedQualityState>>({});

    const floor = useQuery({
        queryKey: ['material-flow', 'production-returnable', search],
        queryFn: () => listProductionReturnable(search || undefined),
    });

    const warehouses = useQuery({
        queryKey: ['inventory', 'warehouses', 'return-destinations'],
        queryFn: listAllWarehouses,
    });

    /** Names for the per-line destinations the server chose. */
    const warehouseName = useMemo(() => {
        const byId = new Map<number, string>();
        for (const warehouse of warehouses.data?.data ?? []) byId.set(warehouse.id, warehouse.name);
        return byId;
    }, [warehouses.data]);

    /**
     * Only stores still in use, and never production itself — the server
     * refuses both, and a dropdown that offers a refusal is a dropdown that
     * wastes a storekeeper's evening.
     *
     * The production row comes from the warehouses index's own meta, NOT from
     * the first floor row: filter the floor to nothing and that row would be
     * back in the dropdown, which is the one place it must never be.
     */
    const destinations = useMemo(() => {
        const wipId = warehouses.data?.meta?.production_wip_warehouse_id ?? null;
        // QUALITY HOLD IS NOT A DESTINATION A PERSON PICKS (DEC-20260901-003).
        // Damaged material is routed there by the SERVER, from the condition;
        // offering it here would let a good return be filed into the hold,
        // which is a way of taking usable stock off the shelf by accident.
        const holdId = warehouses.data?.meta?.quality_hold_warehouse_id ?? null;
        return (warehouses.data?.data ?? [])
            .filter((warehouse) => warehouse.is_active && warehouse.id !== wipId && warehouse.id !== holdId)
            .map((warehouse) => ({ value: warehouse.id, label: warehouse.name }));
    }, [warehouses.data]);

    /** What the hold is called, for the rows that are going there. */
    const qualityHoldName = useMemo(() => {
        const holdId = warehouses.data?.meta?.quality_hold_warehouse_id ?? null;
        if (holdId === null) return null;
        return (warehouses.data?.data ?? []).find((warehouse) => warehouse.id === holdId)?.name ?? null;
    }, [warehouses.data]);

    /**
     * WHAT CONDITION EACH LINE CAME BACK IN, keyed exactly as its quantity is
     * — by item for the free rows, by store issue line for the attributed
     * ones. Per line and not per document: one trip to the hatch can carry a
     * clean sack and a wet one.
     *
     * A key with nothing in it is `good`, and that is the SERVER's reading
     * too, not a default invented here — an omitted quality_state is recorded
     * as good. So the map holds only the deliberate departures from it.
     */
    const lines = useMemo(() => {
        const typed: {
            item_id?: number;
            store_issue_line_id?: number;
            quantity: number;
            quality_state?: ReturnedQualityState;
        }[] = [];

        for (const [itemId, quantity] of Object.entries(free)) {
            if (quantity && quantity > 0) {
                typed.push({
                    item_id: Number(itemId),
                    quantity,
                    quality_state: freeCondition[Number(itemId)] ?? 'good',
                });
            }
        }
        for (const [lineId, quantity] of Object.entries(attributed)) {
            if (quantity && quantity > 0) {
                typed.push({
                    store_issue_line_id: Number(lineId),
                    quantity,
                    quality_state: attributedCondition[Number(lineId)] ?? 'good',
                });
            }
        }

        return typed;
    }, [free, attributed, freeCondition, attributedCondition]);

    const record = useMutation({
        mutationFn: () =>
            recordProductionReturn({
                to_warehouse_id: destination as number,
                lines,
            }),
        onSuccess: async () => {
            message.success(`Returned ${lines.length} line${lines.length === 1 ? '' : 's'} to store`);
            setFree({});
            setAttributed({});
            setFreeCondition({});
            setAttributedCondition({});
            await queryClient.invalidateQueries({ queryKey: ['material-flow'] });
            await queryClient.invalidateQueries({ queryKey: ['inventory'] });
        },
        onError: (error) => {
            // The server's own words: every bound that matters here — what an
            // open issue is still holding, what a negative balance means — is
            // its rule, and its wording is the authority on it.
            message.error(apiRefusalMessage(error, 'That return was not recorded.'));
        },
    });

    // The whole floor is here (no pager), so these are honest client sorters
    // over every row. Return and Condition are inputs and carry none.
    const columns = [
        {
            title: 'Material',
            key: 'material',
            sorter: columnSorter((row: ProductionReturnable) => itemLabel(row), 'text'),
            render: (row: ProductionReturnable) => (
                <Space size={4}>
                    <span>{itemLabel(row)}</span>
                    {!row.item_is_active && (
                        <Tooltip title="Deactivated — it can still come home, it just cannot be requested again">
                            <Tag>Retired</Tag>
                        </Tooltip>
                    )}
                </Space>
            ),
        },
        {
            title: 'In production',
            key: 'on_floor',
            align: 'right' as const,
            sorter: columnSorter((row: ProductionReturnable) => row.on_floor, 'number'),
            render: (row: ProductionReturnable) => (
                <Typography.Text type={Number(row.on_floor) < 0 ? 'danger' : undefined}>
                    {formatQuantity(row.on_floor, row.uom)}
                </Typography.Text>
            ),
        },
        {
            title: 'Held by a store issue',
            key: 'attributed',
            align: 'right' as const,
            render: (row: ProductionReturnable) =>
                row.store_issue_lines.length === 0 ? '—' : formatQuantity(row.attributed, row.uom),
        },
        {
            title: 'Free to return',
            key: 'unattributed',
            align: 'right' as const,
            sorter: columnSorter((row: ProductionReturnable) => row.unattributed, 'number'),
            render: (row: ProductionReturnable) => formatQuantity(row.unattributed, row.uom),
        },
        {
            title: 'Return',
            key: 'return',
            width: 160,
            render: (row: ProductionReturnable) => (
                <InputNumber
                    min={0}
                    max={Number(row.unattributed)}
                    step={permitsFractions(row.uom) ? 0.001 : 1}
                    // A material a handover is standing on goes home through
                    // ITS line, in the expanded row — the owner settled on
                    // 31-Aug-2026 (DEC-20260831-005, DEC-20260831-012) that
                    // material which came out on a store issue returns
                    // against that exact issue, for open, partially returned
                    // and completed issues alike. The
                    // server refuses it here, and an input that only ever
                    // produces a refusal is worse than no input. The "Held by
                    // a store issue" figure beside it is the explanation; no
                    // sentence is needed.
                    disabled={Number(row.unattributed) <= 0 || row.store_issue_lines.length > 0}
                    value={free[row.item_id] ?? null}
                    onChange={(value) => setFree((current) => ({ ...current, [row.item_id]: value }))}
                    style={{ width: '100%' }}
                />
            ),
        },
        {
            // DISABLED IN LOCKSTEP WITH ITS QUANTITY, for the same reason: a
            // condition on a row that cannot return through this door answers
            // a question nobody asked.
            title: 'Condition',
            key: 'condition',
            width: 130,
            render: (row: ProductionReturnable) => (
                <Select<ReturnedQualityState>
                    options={CONDITION_OPTIONS}
                    disabled={Number(row.unattributed) <= 0 || row.store_issue_lines.length > 0}
                    value={freeCondition[row.item_id] ?? 'good'}
                    onChange={(value) => setFreeCondition((current) => ({ ...current, [row.item_id]: value }))}
                    style={{ width: '100%' }}
                />
            ),
        },
    ];

    return (
        <Card
            title={embedded ? undefined : 'Return to Store'}
            extra={
                <Space>
                    <Input.Search
                        allowClear
                        placeholder="Search material"
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        onSearch={setSearch}
                        style={{ width: 220 }}
                    />
                    <Select
                        placeholder="Store"
                        options={destinations}
                        value={destination}
                        onChange={setDestination}
                        style={{ width: 220 }}
                    />
                    <Button
                        type="primary"
                        disabled={!destination || lines.length === 0}
                        loading={record.isPending}
                        onClick={() => record.mutate()}
                    >
                        {lines.length === 0 ? 'Return' : `Return ${lines.length} line${lines.length === 1 ? '' : 's'}`}
                    </Button>
                </Space>
            }
        >
            <Table<ProductionReturnable>
                rowKey="item_id"
                size="small"
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                columns={columns}
                dataSource={floor.data ?? []}
                loading={floor.isPending}
                pagination={false}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={floor}
                            entity="materials in production"
                            empty="Nothing is standing in production."
                        />
                    ),
                }}
                expandable={{
                    // Only a row with a handover behind it expands: the lines
                    // are how that handover's own arithmetic gets closed, and a
                    // row with none has nothing to show under it.
                    rowExpandable: (row) => row.store_issue_lines.length > 0,
                    expandedRowRender: (row) => (
                        <Table
                            rowKey="store_issue_line_id"
                            size="small"
                            pagination={false}
                            dataSource={row.store_issue_lines}
                            columns={[
                                { title: 'Store issue', dataIndex: 'issue_number', key: 'issue_number' },
                                {
                                    title: 'Still with production',
                                    key: 'outstanding',
                                    align: 'right' as const,
                                    render: (line: ProductionReturnable['store_issue_lines'][number]) =>
                                        formatQuantity(line.outstanding, row.uom),
                                },
                                {
                                    // THE DROPDOWN ABOVE DOES NOT ADDRESS THESE
                                    // LINES. An attributed return goes back to
                                    // the store it came OUT of — a fact about
                                    // the original handover, not this screen's
                                    // to redirect — and on live that is not
                                    // always the row the dropdown names. Shown
                                    // per line so the destination is never a
                                    // silent surprise.
                                    title: 'Goes back to',
                                    key: 'to_warehouse_id',
                                    render: (line: ProductionReturnable['store_issue_lines'][number]) => {
                                        // A DAMAGED LINE DOES NOT GO BACK TO
                                        // THE ISSUING STORE. It goes to
                                        // quality hold, and this column is
                                        // the only place the row says so —
                                        // the standing rule for this screen
                                        // is that the row's own figures
                                        // explain it, not a sentence above.
                                        if (attributedCondition[line.store_issue_line_id] === 'damaged') {
                                            return qualityHoldName ?? 'Quality hold';
                                        }

                                        return warehouseName.get(line.to_warehouse_id) ?? `#${line.to_warehouse_id}`;
                                    },
                                },
                                {
                                    title: 'Return',
                                    key: 'return',
                                    width: 160,
                                    render: (line: ProductionReturnable['store_issue_lines'][number]) => (
                                        <InputNumber
                                            min={0}
                                            max={Number(line.outstanding)}
                                            step={permitsFractions(row.uom) ? 0.001 : 1}
                                            value={attributed[line.store_issue_line_id] ?? null}
                                            onChange={(value) =>
                                                setAttributed((current) => ({
                                                    ...current,
                                                    [line.store_issue_line_id]: value,
                                                }))
                                            }
                                            style={{ width: '100%' }}
                                        />
                                    ),
                                },
                                {
                                    title: 'Condition',
                                    key: 'condition',
                                    width: 130,
                                    render: (line: ProductionReturnable['store_issue_lines'][number]) => (
                                        <Select<ReturnedQualityState>
                                            options={CONDITION_OPTIONS}
                                            value={attributedCondition[line.store_issue_line_id] ?? 'good'}
                                            onChange={(value) =>
                                                setAttributedCondition((current) => ({
                                                    ...current,
                                                    [line.store_issue_line_id]: value,
                                                }))
                                            }
                                            style={{ width: '100%' }}
                                        />
                                    ),
                                },
                            ]}
                        />
                    ),
                }}
            />
        </Card>
    );
}
