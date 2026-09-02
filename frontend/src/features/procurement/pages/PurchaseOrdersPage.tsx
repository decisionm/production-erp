import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import { listAllItems } from '@/features/inventory/api';
import { listAllVendors, listPurchaseOrders, sendPurchaseOrder } from '@/features/procurement/api';
import { apiMessage } from '@/features/procurement/components/apiMessage';
import CreatePurchaseOrderModal, { type RaiseFromRequisition } from '@/features/procurement/components/CreatePurchaseOrderModal';
import PurchaseOrderDetailDrawer from '@/features/procurement/components/PurchaseOrderDetailDrawer';
import PurchaseOrderFilterBar from '@/features/procurement/components/PurchaseOrderFilterBar';
import { AmendPurchaseOrderModal, PurchaseOrderReasonModal, type ReasonAction } from '@/features/procurement/components/PurchaseOrderLifecycleModals';
import PurchaseOrderTallyCell from '@/features/procurement/components/PurchaseOrderTallyCell';
import PurchaseOrderTraceDrawer from '@/features/procurement/components/PurchaseOrderTraceDrawer';
import {
    type PurchaseOrderAction,
    amendedItemIds,
    canLabels,
    hasActiveFilters,
    poNumber,
    purchasableItemOptions,
    reconcileReceipts,
    uomPhrase,
    statusTag,
    tallyStateLine,
} from '@/features/procurement/purchaseOrders';
import { purchasePickerItems } from '@/features/procurement/purchasePicker';
import type { PurchaseOrder } from '@/features/procurement/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { usePurchaseOrderListParams } from '@/features/procurement/usePurchaseOrderListParams';

const numeric = { fontVariantNumeric: 'tabular-nums' } as const;

// What an EMPTY table says is judged on the query's state — never on the row
// count alone (the Sales lists' rule): a list that could not be read has no
// rows, and "No purchase orders match" over a 403 would be a permission
// error read as an empty result. The judging moved to @/lib/ListEmpty so a
// failed read also offers Try again, on every list the same way.

/**
 * THE PURCHASE ORDERS LIST (Phase 6: P6-01 lifecycle, P6-02 show/trace/
 * filters, P6-03 Tally state). The filters, the page and the open drawer
 * live in the URL (usePurchaseOrderListParams) and the SERVER does the
 * narrowing. Each row carries where it stands with Tally — worded in one
 * place, tallyStateLine — and offers exactly the lifecycle actions the
 * server says are allowed (`can`); the modals for Close / Cancel (reason
 * required) and Amend (draft only) and the two drawers (detail, trace) are
 * their own components, so this file reads as a list.
 */
export default function PurchaseOrdersPage() {
    const [createOpen, setCreateOpen] = useState(false);
    // A requisition's Raise PO arrives as router state (no refetch, no
    // extra endpoint): open the create form prefilled, once, and clear the
    // state so a later back/refresh does not reopen it.
    const location = useLocation();
    const navigate = useNavigate();
    const [raiseFrom, setRaiseFrom] = useState<RaiseFromRequisition | null>(null);
    useEffect(() => {
        const arrived = (location.state as { raiseFromRequisition?: RaiseFromRequisition } | null)?.raiseFromRequisition;
        if (arrived) {
            setRaiseFrom(arrived);
            setCreateOpen(true);
            navigate(location.pathname + location.search, { replace: true, state: null });
        }
    }, [location, navigate]);
    const [reasonAction, setReasonAction] = useState<{ action: ReasonAction; order: PurchaseOrder } | null>(null);
    const [amendOrder, setAmendOrder] = useState<PurchaseOrder | null>(null);
    const queryClient = useQueryClient();

    const { filters, setFilters, setPage, openId, traceId, openDetail, openTrace, closeDrawers } = usePurchaseOrderListParams();
    const filtersActive = hasActiveFilters(filters);

    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        queryKey: ['procurement', 'purchase-orders', 'list', filters],
        queryFn: () => listPurchaseOrders(filters),
        placeholderData: (previous) => previous,
    });
    const { data: vendors } = useQuery({ queryKey: ['procurement', 'vendors', 'all'], queryFn: listAllVendors });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

    // WS-B: `StorePurchaseOrderRequest` refuses a RETIRED vendor on an
    // ERP-entered order, so the buyer is no longer offered one. (The Tally
    // MIRROR path keeps accepting it — it records an order Tally already
    // holds — but nothing on this screen raises a mirror.) The FILTER bar is
    // deliberately untouched: past orders against a retired vendor must stay
    // findable.
    const vendorOptions = activePickerOptions(vendors?.data, {
        isActive: (v) => v.is_active,
        option: (v) => ({ value: v.id, label: `${v.code} — ${v.name}` }),
    });
    // DEC-20260902-023: the picker offers Raw and Packing material by
    // default; Other and unclassified items only behind "Show additional
    // purchasable items", and a finished good never at all, whatever the
    // choice (purchasePickerItems). `purchasableItemOptions` is still the
    // source of the option shape and of the AMENDED order's own
    // visible-but-disabled items — but it is composed on the ALREADY
    // category-filtered list, so its archived-item `keepIds` branch cannot
    // fire for an item `purchasePickerItems` dropped for being inactive
    // before this call ever sees it (an amended draft naming a
    // since-archived item renders that line blank rather than "(Retired)" —
    // a known gap of this composition, not new drift in `purchasableItemOptions`
    // itself). The FILTER bar builds its own options and is deliberately left
    // alone: past orders must stay findable.
    //
    // `amendedItemIds` is what tolerates a line the payload served without an
    // item — see its docblock. `purchasableItemOptions` builds its label from
    // `itemLabel` alone and knows nothing of `warning`, so the "· Unclassified
    // — reason required" suffix is joined back on afterward from `pickerItems`.
    const [showAdditional, setShowAdditional] = useState(false);
    const pickerItems = useMemo(() => purchasePickerItems(items?.data, showAdditional), [items, showAdditional]);
    const unclassifiedItemIds = useMemo(
        () => new Set(pickerItems.filter((p) => p.warning).map((p) => p.id)),
        [pickerItems],
    );
    const itemOptions = useMemo(() => {
        const warningById = new Map(pickerItems.filter((p) => p.warning).map((p) => [p.id, p.warning as string]));
        return purchasableItemOptions(pickerItems.map((p) => p.item), amendedItemIds(amendOrder?.lines)).map((option) =>
            warningById.has(option.value) ? { ...option, label: `${option.label} · ${warningById.get(option.value)}` } : option,
        );
    }, [pickerItems, amendOrder]);

    const orders = useMemo(() => data?.data ?? [], [data]);
    const rowFor = (id: number | null) => (id === null ? undefined : orders.find((order) => order.id === id));

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-orders'] });

    const sendMutation = useMutation({
        mutationFn: sendPurchaseOrder,
        onSuccess: (order) => {
            // What Send did about Tally, in the words the row will show —
            // staged and refused/disabled/queued are different outcomes and
            // the toast says which.
            message.success(`${poNumber(order)} sent. Tally: ${tallyStateLine(order).text}`);
            invalidate();
        },
    });

    // A refusal to send belongs to the row it was refused for; the banner
    // clears when the reader moves on.
    const resetSend = sendMutation.reset;
    useEffect(() => {
        resetSend();
    }, [openId, resetSend]);

    function onAction(action: PurchaseOrderAction, order: PurchaseOrder) {
        if (action === 'send') sendMutation.mutate(order.id);
        else if (action === 'amend') setAmendOrder(order);
        else setReasonAction({ action, order });
    }

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Purchase Orders</Typography.Title>
                <Button type="primary" onClick={() => setCreateOpen(true)}>New Purchase Order</Button>
            </Space>

            <PurchaseOrderFilterBar filters={filters} onChange={setFilters} />

            {sendMutation.isError && (
                <Alert
                    type="warning"
                    showIcon
                    closable
                    onClose={() => sendMutation.reset()}
                    style={{ marginBottom: 12 }}
                    message="Not sent"
                    description={apiMessage(sendMutation.error, 'The order could not be sent.')}
                />
            )}

            {/* placeholderData keeps stale rows on a failed refetch, so
                emptyText never shows the failure — this line does. */}
            <ListReadAlert state={{ isPending, isError, error, refetch }} entity="purchase orders" />

            <Table<PurchaseOrder>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={orders}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={{ isPending, isError, error, refetch }}
                            entity="purchase orders"
                            empty={filtersActive ? 'No purchase orders match these filters.' : 'No purchase orders yet.'}
                        />
                    ),
                }}
                pagination={
                    data?.meta
                        ? {
                              current: data.meta.current_page,
                              pageSize: data.meta.per_page,
                              total: data.meta.total,
                              showSizeChanger: true,
                              pageSizeOptions: [20, 50, 100],
                              showTotal: (total) => `${total} order${total === 1 ? '' : 's'}`,
                              onChange: (page, pageSize) => setPage(page, pageSize),
                          }
                        : false
                }
                columns={[
                    { title: 'Number', render: (_, row) => <strong>{poNumber(row)}</strong> },
                    {
                        title: 'Status',
                        render: (_, row) => <Tag color={statusTag(row.status).color}>{statusTag(row.status).label}</Tag>,
                    },
                    {
                        title: 'Source',
                        render: (_, row) =>
                            row.source === 'tally' ? (
                                // The real order lives in Tally — this row is
                                // its read-only mirror, corrected there.
                                <Tag color="geekblue">Tally · {row.tally_order_no ?? 'mirror'}</Tag>
                            ) : (
                                <Typography.Text type="secondary">ERP</Typography.Text>
                            ),
                    },
                    { title: 'Vendor', render: (_, row) => row.vendor?.name ?? '—' },
                    { title: 'Order Date', dataIndex: 'order_date' },
                    {
                        title: 'Received',
                        render: (_, row) => {
                            const summary = reconcileReceipts(row.lines).summary;

                            return (
                                // UNIT-WISE, NOT A TOTAL. This cell printed
                                // `received / ordered` summed across every
                                // line, so an order for 500 Kgs of resin and
                                // 40 Nos of caps read "0 / 540" — kilograms
                                // added to pieces, a figure in no unit that a
                                // buyer cannot check against anything.
                                <Tooltip title={`${summary.complete} of ${summary.lines} line${summary.lines === 1 ? '' : 's'} fully received`}>
                                    <span style={numeric}>
                                        {uomPhrase(summary.by_uom, 'received')} of {uomPhrase(summary.by_uom, 'ordered')}
                                        {row.receipts_count !== undefined ? ` · ${row.receipts_count} GRN` : ''}
                                    </span>
                                </Tooltip>
                            );
                        },
                    },
                    { title: 'Tally', render: (_, row) => <PurchaseOrderTallyCell order={row} compact /> },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space wrap>
                                <Button size="small" onClick={() => openDetail(row.id)}>
                                    View
                                </Button>
                                <Button size="small" onClick={() => openTrace(row.id)}>
                                    Trace
                                </Button>
                                {canLabels(row.can).map(({ action, label }) => (
                                    <Button
                                        key={action}
                                        size="small"
                                        danger={action === 'cancel'}
                                        loading={action === 'send' && sendMutation.isPending && sendMutation.variables === row.id}
                                        onClick={() => onAction(action, row)}
                                    >
                                        {label}
                                    </Button>
                                ))}
                            </Space>
                        ),
                    },
                ]}
            />

            <CreatePurchaseOrderModal
                open={createOpen}
                onClose={() => {
                    setCreateOpen(false);
                    setRaiseFrom(null);
                }}
                onCreated={(order) => {
                    invalidate();
                    queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-requisitions'] });
                    setCreateOpen(false);
                    setRaiseFrom(null);
                    message.success(`${poNumber(order)} created as a draft.`);
                }}
                vendorOptions={vendorOptions}
                itemOptions={itemOptions}
                raiseFrom={raiseFrom}
                showAdditional={showAdditional}
                onShowAdditionalChange={setShowAdditional}
                unclassifiedItemIds={unclassifiedItemIds}
            />

            <PurchaseOrderReasonModal
                action={reasonAction?.action ?? null}
                order={reasonAction?.order ?? null}
                onClose={() => setReasonAction(null)}
            />

            <AmendPurchaseOrderModal
                order={amendOrder}
                onClose={() => setAmendOrder(null)}
                itemOptions={itemOptions}
                showAdditional={showAdditional}
                onShowAdditionalChange={setShowAdditional}
                unclassifiedItemIds={unclassifiedItemIds}
            />

            <PurchaseOrderDetailDrawer
                orderId={openId}
                listRow={rowFor(openId)}
                onClose={closeDrawers}
                onOpenTrace={openTrace}
                onAction={onAction}
                sending={sendMutation.isPending}
            />

            <PurchaseOrderTraceDrawer
                orderId={traceId}
                listRow={rowFor(traceId)}
                onClose={closeDrawers}
                onOpenDetail={openDetail}
            />
        </>
    );
}
