import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Drawer, Empty, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { getMaterialLot, listAllItems, listMaterialBags } from '@/features/inventory/api';
import { bagStatusLabel, bagStatusOptions, formatKg } from '@/features/inventory/bagStatus';
import MaterialBagLabels from '@/features/inventory/components/MaterialBagLabels';
import type { MaterialBag } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

/**
 * THE LABEL BENCH — every barcoded bag this factory has registered, and the
 * button that prints its label again.
 *
 * A READ AND A PRINTER, nothing else. It registers no lot, receives no
 * material, moves no stock and posts nothing to Tally. Receiving happens on
 * Goods Receipts; the lot register with its receipt provenance and its
 * remaining kilograms is Material Receipts & Bag Labels, which this does not
 * replace — that page is per RECEIPT, this one is per BAG, which is what
 * somebody standing at a printer with a torn label actually has in their hand.
 *
 * NO IDENTITY IS MINTED HERE (DEC-20260807-008: no printing surface mints a
 * second identity). Every barcode on this page already exists on
 * `material_bags`; reprinting one prints the identity the bag was born with.
 * There is no "generate barcode" action and there must never be one.
 *
 * NO CARTONS TAB. Finished cartons carry barcodes too, but the only list of
 * them is per shift-production-entry
 * (`GET /production/shift-production-entries/{id}/cartons`) and the by-number
 * lookup sits behind the carton-trace tier (DEC-20260810-001: Owner, Plant
 * Manager, Accounts — never a supervisor). There is no carton list endpoint to
 * page through, and neither inventing one nor putting a tiered surface behind
 * an inventory-gated menu entry is this change's to make.
 *
 * The whole surface 404s while `production.traceability_enabled` is off. With
 * the flag down the feature genuinely does not exist, so the page says so
 * rather than showing an empty register that looks like a factory with no bags.
 */
export default function BarcodeLabelsPage() {
    const [itemId, setItemId] = useState<number | null>(null);
    const [status, setStatus] = useState<string | null>(null);
    const [page, setPage] = useState(1);
    const [reprintBag, setReprintBag] = useState<MaterialBag | null>(null);

    const { data: items } = useQuery({
        queryKey: ['inventory', 'items', 'all'],
        queryFn: listAllItems,
    });

    const { data, isLoading, isError, error } = useQuery({
        queryKey: ['inventory', 'material-bags', itemId, status, page],
        queryFn: () =>
            listMaterialBags({
                item_id: itemId ?? undefined,
                status: status ?? undefined,
                page,
            }),
        retry: false,
    });

    /**
     * The lot behind the bag being reprinted, fetched whole.
     *
     * MaterialBagLabels numbers a label "Bag N of M" from the bag's position
     * within its lot's bags, so it is handed the LOT and told which bag to
     * open. Synthesizing a one-bag lot from the row would print "Bag 1 of M"
     * onto a physical label — a sequence the screen made up.
     */
    const { data: reprintLot, isLoading: reprintLoading } = useQuery({
        queryKey: ['inventory', 'material-lot', reprintBag?.material_lot_id],
        queryFn: () => getMaterialLot(reprintBag!.material_lot_id),
        enabled: reprintBag !== null,
    });

    if (isError && (error as { response?: { status?: number } })?.response?.status === 404) {
        return (
            <>
                <Typography.Title level={3}>Barcode &amp; Labels</Typography.Title>
                <Empty description="Bag barcodes are not enabled for this deployment." />
            </>
        );
    }

    const itemOptions =
        items?.data.filter((item) => item.is_active).map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];

    return (
        <>
            <Typography.Title level={3}>Barcode &amp; Labels</Typography.Title>

            <Space wrap style={{ marginBottom: 16 }}>
                <Select
                    value={itemId ?? undefined}
                    onChange={(value) => {
                        setItemId(value ?? null);
                        setPage(1);
                    }}
                    options={itemOptions}
                    placeholder="All materials"
                    showSearch
                    optionFilterProp="label"
                    allowClear
                    style={{ width: 'min(100%, 360px)' }}
                />
                <Select
                    value={status ?? undefined}
                    onChange={(value) => {
                        setStatus(value ?? null);
                        setPage(1);
                    }}
                    options={bagStatusOptions()}
                    placeholder="Any status"
                    allowClear
                    style={{ width: 'min(100%, 220px)' }}
                />
            </Space>

            {/* Everything that is not the traceability flag being off. An
                empty register and a failed read look the same in a table, and
                only one of them means the factory has no bags. */}
            {isError && (
                <Alert
                    type="error"
                    showIcon
                    message="Could not load the bag register"
                    style={{ marginBottom: 16 }}
                />
            )}

            <Table<MaterialBag>
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
                        title: 'Barcode',
                        dataIndex: 'barcode',
                        render: (barcode: string) => <Typography.Text code copyable>{barcode}</Typography.Text>,
                    },
                    { title: 'Material', render: (_, bag) => itemLabel(bag.lot?.item) },
                    {
                        title: 'Supplier lot',
                        render: (_, bag) => bag.lot?.supplier_lot_no ?? `Lot #${bag.material_lot_id}`,
                    },
                    { title: 'Original kg', dataIndex: 'original_kg', align: 'right', render: formatKg },
                    { title: 'Remaining kg', dataIndex: 'remaining_kg', align: 'right', render: formatKg },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (value: string) => {
                            const label = bagStatusLabel(value);
                            return <Tag color={label.tone}>{label.text}</Tag>;
                        },
                    },
                    {
                        // `created_at` is a genuine server instant, not a
                        // wall-clock the factory keyed in, so it is converted
                        // to the reader's clock — the same call MaterialLotsPage
                        // and MaterialBagLabels make for this exact column.
                        // formatDateTime() is for the other kind and would
                        // print this one in UTC.
                        title: 'Registered',
                        dataIndex: 'created_at',
                        render: (value: string | null | undefined) =>
                            value ? new Date(value).toLocaleString() : '—',
                    },
                    {
                        title: 'Label',
                        render: (_, bag) => (
                            <Button size="small" onClick={() => setReprintBag(bag)}>
                                Print / Reprint
                            </Button>
                        ),
                    },
                ]}
            />

            <Drawer
                title={reprintBag ? `Bag label — ${reprintBag.barcode}` : 'Bag label'}
                open={reprintBag !== null}
                onClose={() => setReprintBag(null)}
                width="min(100vw, 640px)"
                destroyOnHidden
            >
                {reprintLoading && <Typography.Text type="secondary">Loading…</Typography.Text>}
                {reprintBag && reprintLot && (
                    <MaterialBagLabels lots={[reprintLot]} bagId={reprintBag.id} reprint />
                )}
            </Drawer>
        </>
    );
}
