import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, InputNumber, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { useMemo, useState } from 'react';
import { listAllWarehouses } from '@/features/inventory/api';
import { apiRefusalMessage } from '@/features/material-flow/api';
import { formatQuantity, permitsFractions } from '@/features/material-flow/words';
import { ListEmpty } from '@/lib/ListEmpty';
import {
    confirmReturnedMaterialDamage,
    listReturnedMaterialHolds,
    releaseReturnedMaterial,
    type ReturnedMaterialHold,
} from '../api';

/**
 * DAMAGED MATERIAL BACK FROM PRODUCTION, WAITING TO BE LOOKED AT
 * (DEC-20260901-003).
 *
 * The store marked these lines damaged on the way in, so the server put them
 * here instead of into issuable stock. This desk decides which way they go:
 *
 *   CONFIRM DAMAGE → the quantity is scrapped and leaves stock. It does not
 *   come back and there is no undo — a wrong disposition is answered by a new
 *   movement, like every other stock act in this system.
 *
 *   RELEASE → it was not damaged after all, and the quantity goes to a store
 *   as usable stock.
 *
 * TWO NUMBER BOXES PER ROW, NOT A MODE SWITCH. A row is rarely all one thing
 * — 60 of the 100 kg genuinely wet and 40 sound is the ordinary case — and a
 * screen with a Scrap mode and a Release mode would make that two trips and
 * two chances to mistype the split.
 *
 * NO BLURB EXPLAINING THE HOLD. The columns say what is waiting and the two
 * inputs say what can be done with it; a floor or desk user does not read a
 * paragraph. The one sentence on the page is the store picker's label, and
 * that is a choice the person has to make.
 *
 * THE PAGE IS EMPTY ON A GOOD DAY, and that is worth knowing rather than
 * looking like a broken screen: nothing waiting means nothing came back
 * damaged.
 */
export default function ReturnedMaterialHoldsPage() {
    const queryClient = useQueryClient();

    const [scrap, setScrap] = useState<Record<number, number | null>>({});
    const [release, setRelease] = useState<Record<number, number | null>>({});
    const [destination, setDestination] = useState<number | null>(null);

    const holds = useQuery({
        queryKey: ['quality', 'returned-material-holds'],
        queryFn: listReturnedMaterialHolds,
    });

    const warehouses = useQuery({
        queryKey: ['inventory', 'warehouses', 'all'],
        queryFn: listAllWarehouses,
    });

    /**
     * Where released material may go — never the hold itself (the server
     * refuses that, and a dropdown that offers a refusal wastes a trip) and
     * never the production floor, which is not a store.
     */
    const destinations = useMemo(() => {
        const holdId = warehouses.data?.meta?.quality_hold_warehouse_id ?? null;
        const wipId = warehouses.data?.meta?.production_wip_warehouse_id ?? null;
        return (warehouses.data?.data ?? [])
            .filter((warehouse) => warehouse.is_active && warehouse.id !== holdId && warehouse.id !== wipId)
            .map((warehouse) => ({ value: warehouse.id, label: warehouse.name }));
    }, [warehouses.data]);

    const linesFrom = (typed: Record<number, number | null>) =>
        Object.entries(typed)
            .filter(([, quantity]) => quantity != null && quantity > 0)
            .map(([itemId, quantity]) => ({ item_id: Number(itemId), quantity: quantity as number }));

    const scrapLines = linesFrom(scrap);
    const releaseLines = linesFrom(release);

    const settled = async (what: string, count: number) => {
        message.success(`${count} line${count === 1 ? '' : 's'} ${what}`);
        setScrap({});
        setRelease({});
        await queryClient.invalidateQueries({ queryKey: ['quality', 'returned-material-holds'] });
        // The store's own balances changed too — a release adds to them and a
        // scrap takes material out of the factory's stock entirely.
        await queryClient.invalidateQueries({ queryKey: ['inventory'] });
    };

    const confirm = useMutation({
        mutationFn: () => confirmReturnedMaterialDamage({ lines: scrapLines }),
        onSuccess: () => settled('scrapped', scrapLines.length),
        // The server's own words: what is standing in the hold is its figure,
        // recomputed under a lock, and its sentence carries the real number.
        onError: (error) => message.error(apiRefusalMessage(error, 'That scrap was not recorded.')),
    });

    const send = useMutation({
        mutationFn: () => releaseReturnedMaterial({ to_warehouse_id: destination as number, lines: releaseLines }),
        onSuccess: () => settled('released to store', releaseLines.length),
        onError: (error) => message.error(apiRefusalMessage(error, 'That release was not recorded.')),
    });

    const numberBox = (
        row: ReturnedMaterialHold,
        typed: Record<number, number | null>,
        set: (next: (current: Record<number, number | null>) => Record<number, number | null>) => void,
        other: Record<number, number | null>,
    ) => (
        <InputNumber
            min={0}
            // THE TWO BOXES SHARE ONE BUDGET, and the ceiling says so: what is
            // standing, less whatever the other box has already claimed. The
            // server refuses the overdraw either way; this stops a person
            // typing a split that adds up to more than they have.
            max={Math.max(Number(row.quantity) - (other[row.item_id] ?? 0), 0)}
            step={permitsFractions(row.uom ?? '') ? 0.001 : 1}
            value={typed[row.item_id] ?? null}
            onChange={(value) => set((current) => ({ ...current, [row.item_id]: value }))}
            style={{ width: '100%' }}
        />
    );

    return (
        <Card
            title="Returned Material — Quality Hold"
            extra={
                <Space>
                    <Button
                        danger
                        disabled={scrapLines.length === 0}
                        loading={confirm.isPending}
                        onClick={() => confirm.mutate()}
                    >
                        {scrapLines.length === 0
                            ? 'Confirm damage'
                            : `Confirm damage — scrap ${scrapLines.length} line${scrapLines.length === 1 ? '' : 's'}`}
                    </Button>
                    <Select
                        placeholder="Release to store"
                        options={destinations}
                        value={destination}
                        onChange={setDestination}
                        style={{ width: 200 }}
                    />
                    <Button
                        type="primary"
                        disabled={!destination || releaseLines.length === 0}
                        loading={send.isPending}
                        onClick={() => send.mutate()}
                    >
                        {releaseLines.length === 0
                            ? 'Release'
                            : `Release ${releaseLines.length} line${releaseLines.length === 1 ? '' : 's'}`}
                    </Button>
                </Space>
            }
        >
            <Table<ReturnedMaterialHold>
                rowKey="item_id"
                size="small"
                dataSource={holds.data ?? []}
                loading={holds.isPending}
                pagination={false}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={holds}
                            entity="materials in quality hold"
                            empty="Nothing is waiting for quality."
                        />
                    ),
                }}
                columns={[
                    {
                        title: 'Material',
                        key: 'material',
                        render: (row: ReturnedMaterialHold) => (
                            <Space size={4}>
                                <span>{row.item_name ?? `#${row.item_id}`}</span>
                                {row.item_sku && <Typography.Text type="secondary">{row.item_sku}</Typography.Text>}
                                {!row.item_is_active && (
                                    <Tooltip title="Deactivated — it can still be scrapped or released, it just cannot be requested again">
                                        <Tag>Retired</Tag>
                                    </Tooltip>
                                )}
                            </Space>
                        ),
                    },
                    {
                        title: 'Waiting',
                        key: 'quantity',
                        align: 'right' as const,
                        render: (row: ReturnedMaterialHold) => formatQuantity(row.quantity, row.uom ?? ''),
                    },
                    {
                        title: 'Scrap',
                        key: 'scrap',
                        width: 150,
                        render: (row: ReturnedMaterialHold) => numberBox(row, scrap, setScrap, release),
                    },
                    {
                        title: 'Release',
                        key: 'release',
                        width: 150,
                        render: (row: ReturnedMaterialHold) => numberBox(row, release, setRelease, scrap),
                    },
                ]}
            />
        </Card>
    );
}
