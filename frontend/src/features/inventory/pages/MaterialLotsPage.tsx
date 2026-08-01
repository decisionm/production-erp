import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Drawer, Empty, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import MaterialBagLabels from '@/features/inventory/components/MaterialBagLabels';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { listAllItems } from '@/features/inventory/api';
import { listMaterialLots } from '@/features/production/api';
import type { MaterialLot } from '@/features/production/types';
import { formatDateTime } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';

/**
 * The register also carries each lot's receipt provenance (MaterialLotResource
 * `receipt`): which goods receipt it arrived on, the price paid and the exact
 * date+time it was received. Present whenever the API eager-loads it, which
 * this endpoint does.
 */
type LotWithReceipt = MaterialLot & {
    receipt?: {
        goods_receipt_note_id: number;
        purchase_order_id: number | null;
        received_at: string | null;
        unit_cost: string | null;
    };
};

function fmtKg(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = Number(value);
    return Number.isFinite(parsed) ? String(parseFloat(parsed.toFixed(4))) : '—';
}

/** A rupee-per-kg rate from its decimal string. Parsed only to display it. */
function fmtRate(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return '—';
    return `₹${parsed.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`;
}

export default function MaterialLotsPage() {
    const [itemId, setItemId] = useState<number | null>(null);
    const [page, setPage] = useState(1);
    const [labelSelection, setLabelSelection] = useState<{ lot: MaterialLot; bagId?: number } | null>(null);
    const user = useAuthStore((s) => s.user);

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
            .map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];

    /**
     * DOES THIS REGISTER CARRY RATES AT ALL — the server's answer, honoured
     * locally.
     *
     * The rate keys are omitted entirely by MaterialLotResource for anyone
     * without finance access, so their PRESENCE on a row is the server's own
     * ruling arriving with the data, and it cannot go stale against a cached
     * /auth/me the way a permission check alone can. It is paired with the
     * finance permission, which can only ever make this stricter.
     *
     * When it is false the column does not exist. No greyed cells and no
     * "ask for access" placeholder — a column advertising a number it will
     * not show only sends somebody looking for it.
     */
    const lots = (data?.data ?? []) as LotWithReceipt[];
    const showsRates =
        hasModuleAccess(user, 'finance') && lots.some((lot) => lot.current_rate_per_kg !== undefined);

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

                <Table<LotWithReceipt>
                    rowKey="id"
                    loading={isLoading}
                    dataSource={lots}
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
                        {
                            title: 'GRN',
                            dataIndex: 'grn_id',
                            // Straight to the receipt this lot arrived on, so
                            // the price stops being a dead end here.
                            render: (value: number | null) =>
                                value ? (
                                    <Link to={`/procurement/goods-receipts?grn=${value}`}>#{value}</Link>
                                ) : (
                                    '—'
                                ),
                        },
                        { title: 'Material', render: (_, lot) => (lot.item ? itemLabel(lot.item) : '—') },
                        { title: 'Supplier lot', dataIndex: 'supplier_lot_no', render: (value: string | null) => value ?? '—' },
                        { title: 'Received', dataIndex: 'received_date', render: (value: string | null) => value ?? '—' },
                        {
                            title: 'Receipt date & time',
                            render: (_, lot) => formatDateTime(lot.receipt?.received_at),
                        },
                        {
                            title: 'Receipt price',
                            align: 'right',
                            render: (_, lot) => lot.receipt?.unit_cost ?? '—',
                        },
                        // ONE COLUMN, and only for the logins the server sends
                        // rates to. The GRN rate above is PROVISIONAL — the
                        // purchase invoice and any landed cost land afterwards
                        // and are appended as new versions, never written over
                        // the original. This column is therefore the only place
                        // that answers "what does this lot cost TODAY", and the
                        // tag is how a reader knows the two figures differ
                        // without opening anything.
                        ...(showsRates
                            ? [
                                  {
                                      title: 'Current rate / kg',
                                      align: 'right' as const,
                                      render: (_: unknown, lot: LotWithReceipt) => (
                                          <Space direction="vertical" size={0} style={{ alignItems: 'flex-end' }}>
                                              <span>{fmtRate(lot.current_rate_per_kg)}</span>
                                              {lot.has_revisions === true && (
                                                  <Space size={4} wrap style={{ justifyContent: 'flex-end' }}>
                                                      <Tag color="gold">revised</Tag>
                                                      <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                          was {fmtRate(lot.receipt_rate_per_kg)}
                                                      </Typography.Text>
                                                  </Space>
                                              )}
                                              {/* Not a dash on its own. A lot with
                                                  no rate at all is opening stock
                                                  that was never bought through a
                                                  receipt — a real answer, and one
                                                  that must not read as "free". */}
                                              {lot.current_rate_per_kg === null && (
                                                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                      no purchase rate on record
                                                  </Typography.Text>
                                              )}
                                          </Space>
                                      ),
                                  },
                              ]
                            : []),
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
