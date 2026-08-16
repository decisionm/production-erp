import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Modal, Skeleton, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { Link } from 'react-router-dom';
import { cancelSalesOrder, getDelivery, getInvoice, getSalesOrder } from '@/features/sales/api';
import { cartonSummary, documentTitle, tallyLinkTag, unvalidatedBuilderTag } from '@/features/sales/drawer';
import type {
    Delivery,
    Invoice,
    SalesDocumentKind,
    SalesOrder,
    SalesOrderStatus,
    TallyLink,
    TraceCarton,
    TraceDelivery,
    TraceInvoice,
    TraceSalesOrder,
} from '@/features/sales/types';
import { instant } from '@/features/tally-sync/drawer';
import { formatDate } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';

type SalesDocument = SalesOrder | Delivery | Invoice;

/** One document to show: which kind, which id. */
export interface SalesDocumentTarget {
    kind: SalesDocumentKind;
    id: number;
}

/** The status colours the three list pages already use — kept identical here. */
export const salesOrderStatusColor: Record<SalesOrderStatus, string> = {
    draft: 'default',
    confirmed: 'blue',
    partially_delivered: 'gold',
    completed: 'green',
    cancelled: 'red',
};

export const invoiceStatusColor: Record<Invoice['status'], string> = {
    draft: 'default',
    issued: 'blue',
    paid: 'green',
};

const numeric = { fontVariantNumeric: 'tabular-nums' } as const;
const caption = { fontSize: 12, display: 'block' } as const;

/**
 * WHERE A DOCUMENT'S VOUCHER STANDS — the Tally cell, shared by the list
 * pages' Tally column and every block of the drawer, so a status is spelled
 * one way everywhere. Status Tag (the Tally Sync page's own words) + the
 * unvalidated-builder warning (server flag, server note on hover, decision
 * named) + the deep link into Tally Sync. A document with NO entry shows a
 * dash: "no entry" is a different fact from "waiting" and must not wear its
 * tag. Nothing here reads a payload, a rate or a rejection text — those
 * live on the Tally Sync page behind its own gates.
 */
export function TallyLinkCell({ link, compact = false }: { link: TallyLink | null | undefined; compact?: boolean }) {
    const tag = tallyLinkTag(link);
    if (!link || !tag) {
        return <Typography.Text type="secondary">—</Typography.Text>;
    }

    const warning = unvalidatedBuilderTag(link);

    return (
        <Space direction="vertical" size={2}>
            <Space size={4} wrap>
                <Tag color={tag.color} style={{ marginInlineEnd: 0 }}>{tag.label}</Tag>
                {warning && (
                    <Tooltip title={warning.note}>
                        <Tag color="warning" style={{ marginInlineEnd: 0, whiteSpace: 'normal' }}>
                            {compact ? 'unvalidated builder' : warning.text}
                        </Tag>
                    </Tooltip>
                )}
            </Space>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {link.voucher_type}
                {link.voucher_number ? ` ${link.voucher_number}` : ''}
                {link.synced_at ? ` · in Tally ${instant(link.synced_at)}` : ''}
                {' · '}
                <Link to={link.link}>Open in Tally Sync</Link>
            </Typography.Text>
        </Space>
    );
}

function SectionTitle({ children }: { children: ReactNode }) {
    return (
        <Typography.Title level={5} style={{ marginTop: 24, marginBottom: 8 }}>
            {children}
        </Typography.Title>
    );
}

/**
 * A caption line: how many boxes, pieces, which batches; then each carton on
 * one line. Plain text — there is no carton route to link to. An ABSENT list
 * (the trace did not arrive) is said as such, never as "no cartons": a typed
 * delivery and an unread one are different facts.
 */
function CartonsBlock({ cartons }: { cartons: readonly TraceCarton[] | null | undefined }) {
    if (!cartons) {
        return (
            <Typography.Text type="secondary" style={caption}>
                Cartons could not be read for this delivery.
            </Typography.Text>
        );
    }

    const rows = cartons;
    if (rows.length === 0) {
        return (
            <Typography.Text type="secondary" style={caption}>
                No cartons scanned — quantities were typed.
            </Typography.Text>
        );
    }

    const summary = cartonSummary(rows);

    return (
        <div style={{ marginTop: 4 }}>
            <Typography.Text style={caption}>
                {summary.cartons} carton{summary.cartons === 1 ? '' : 's'} · {summary.pieces.toLocaleString('en-IN')} pcs
                {summary.batches.length > 0 ? ` · batch ${summary.batches.join(', ')}` : ''}
            </Typography.Text>
            <Space wrap size={[8, 4]} style={{ marginTop: 4 }}>
                {rows.map((carton) => (
                    <Typography.Text key={carton.carton_no} code style={{ fontSize: 12 }}>
                        {carton.carton_no} · {Number(carton.pieces).toLocaleString('en-IN')} pcs
                        {carton.batch_no ? ` · ${carton.batch_no}` : ''}
                    </Typography.Text>
                ))}
            </Space>
        </div>
    );
}

/** The order a delivery / invoice hangs off — one line, clickable into the same drawer. */
function OrderRefLine({ order, onOpen }: { order: TraceSalesOrder | null | undefined; onOpen: (target: SalesDocumentTarget) => void }) {
    if (!order) return <Typography.Text type="secondary">—</Typography.Text>;

    return (
        <Space size={6} wrap>
            <Button type="link" size="small" style={{ padding: 0 }} onClick={() => onOpen({ kind: 'sales_order', id: order.id })}>
                {order.document_number}
            </Button>
            <Tag color={salesOrderStatusColor[order.status] ?? 'default'}>{order.status}</Tag>
            {order.customer && (
                <Typography.Text type="secondary">
                    {order.customer.code} — {order.customer.name}
                </Typography.Text>
            )}
        </Space>
    );
}

function DeliveryBlock({ delivery, onOpen }: { delivery: TraceDelivery; onOpen: (target: SalesDocumentTarget) => void }) {
    return (
        <div style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12, marginBottom: 12 }}>
            <Space size={8} wrap style={{ justifyContent: 'space-between', width: '100%' }}>
                <Space size={8} wrap>
                    <Button type="link" size="small" style={{ padding: 0 }} onClick={() => onOpen({ kind: 'delivery', id: delivery.id })}>
                        <strong>{delivery.document_number}</strong>
                    </Button>
                    <Typography.Text type="secondary">{instant(delivery.delivered_date)}</Typography.Text>
                    {delivery.warehouse && <Typography.Text type="secondary">from {delivery.warehouse.name}</Typography.Text>}
                    {delivery.reference && <Typography.Text type="secondary">ref {delivery.reference}</Typography.Text>}
                </Space>
                <TallyLinkCell link={delivery.tally} compact />
            </Space>
            <div style={{ marginTop: 6 }}>
                {(delivery.lines ?? []).map((line, index) => (
                    <Typography.Text key={`${line.item?.id ?? 'x'}-${index}`} style={{ ...caption, ...numeric }}>
                        {itemLabel(line.item)} × {line.quantity}
                    </Typography.Text>
                ))}
            </div>
            <CartonsBlock cartons={delivery.cartons} />
        </div>
    );
}

function InvoiceBlock({ invoice, onOpen }: { invoice: TraceInvoice; onOpen: (target: SalesDocumentTarget) => void }) {
    return (
        <div style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12, marginBottom: 12 }}>
            <Space size={8} wrap style={{ justifyContent: 'space-between', width: '100%' }}>
                <Space size={8} wrap>
                    <Button type="link" size="small" style={{ padding: 0 }} onClick={() => onOpen({ kind: 'invoice', id: invoice.id })}>
                        <strong>{invoice.document_number}</strong>
                    </Button>
                    <Tag color={invoiceStatusColor[invoice.status] ?? 'default'}>{invoice.status}</Tag>
                    <Typography.Text type="secondary">{formatDate(invoice.invoice_date)}</Typography.Text>
                </Space>
                <TallyLinkCell link={invoice.tally} compact />
            </Space>
            <div style={{ marginTop: 6 }}>
                {(invoice.lines ?? []).map((line, index) => (
                    <Typography.Text key={`${line.item?.id ?? 'x'}-${index}`} style={{ ...caption, ...numeric }}>
                        {itemLabel(line.item)} × {line.quantity} @ {line.unit_price}
                    </Typography.Text>
                ))}
            </div>
        </div>
    );
}

/** The 422 (or whatever the API said) when a cancel was refused — the server's words, never genericised. */
function apiMessage(error: unknown, fallback: string): string {
    const response = (error as { response?: { data?: { message?: string } } })?.response;

    return response?.data?.message ?? fallback;
}

// ------------------------------------------------------------- the bodies --

function SalesOrderBody({ order, onOpen }: { order: SalesOrder; onOpen: (target: SalesDocumentTarget) => void }) {
    // undefined = the trace did not arrive; [] = measured, none. Two
    // different sentences below.
    const deliveries = order.trace?.deliveries;
    const invoices = order.trace?.invoices;

    return (
        <>
            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Status">
                    <Tag color={salesOrderStatusColor[order.status]}>{order.status}</Tag>
                </Descriptions.Item>
                <Descriptions.Item label="Customer">
                    {order.customer ? `${order.customer.code} — ${order.customer.name}` : '—'}
                </Descriptions.Item>
                <Descriptions.Item label="Order Date">{order.order_date}</Descriptions.Item>
                <Descriptions.Item label="Expected Date">{order.expected_date ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Notes">{order.notes ?? '—'}</Descriptions.Item>
                {order.totals && (
                    <Descriptions.Item label="Quantities">
                        <span style={numeric}>
                            ordered {order.totals.ordered_quantity} · delivered {order.totals.delivered_quantity} · invoiced{' '}
                            {order.totals.invoiced_quantity}
                        </span>
                    </Descriptions.Item>
                )}
            </Descriptions>

            <SectionTitle>Lines</SectionTitle>
            <Table
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={order.lines}
                scroll={{ x: 'max-content' }}
                columns={[
                    { title: 'Item', render: (_, line) => itemLabel(line.item) },
                    { title: 'Quantity', dataIndex: 'quantity' },
                    { title: 'Delivered', dataIndex: 'quantity_delivered' },
                    { title: 'Unit Price', dataIndex: 'unit_price' },
                    {
                        title: 'Amount',
                        render: (_, line) => (Number(line.quantity) * Number(line.unit_price)).toFixed(2),
                    },
                ]}
                summary={(lines) => {
                    const total = lines.reduce((sum, line) => sum + Number(line.quantity) * Number(line.unit_price), 0);
                    return (
                        <Table.Summary.Row>
                            <Table.Summary.Cell index={0} colSpan={4}>
                                <strong>Total</strong>
                            </Table.Summary.Cell>
                            <Table.Summary.Cell index={1}>
                                <strong>{total.toFixed(2)}</strong>
                            </Table.Summary.Cell>
                        </Table.Summary.Row>
                    );
                }}
            />

            <SectionTitle>
                Deliveries{' '}
                {deliveries && (
                    <Typography.Text type="secondary" style={{ fontSize: 13, fontWeight: 'normal' }}>
                        ({deliveries.length})
                    </Typography.Text>
                )}
            </SectionTitle>
            {!deliveries ? (
                <Typography.Text type="secondary">The delivery trace could not be read for this order.</Typography.Text>
            ) : deliveries.length === 0 ? (
                <Typography.Text type="secondary">Nothing delivered against this order yet.</Typography.Text>
            ) : (
                deliveries.map((delivery) => <DeliveryBlock key={delivery.id} delivery={delivery} onOpen={onOpen} />)
            )}

            <SectionTitle>
                Invoices{' '}
                {invoices && (
                    <Typography.Text type="secondary" style={{ fontSize: 13, fontWeight: 'normal' }}>
                        ({invoices.length})
                    </Typography.Text>
                )}
            </SectionTitle>
            {!invoices ? (
                <Typography.Text type="secondary">The invoice trace could not be read for this order.</Typography.Text>
            ) : invoices.length === 0 ? (
                <Typography.Text type="secondary">No ERP invoice raised against this order.</Typography.Text>
            ) : (
                invoices.map((invoice) => <InvoiceBlock key={invoice.id} invoice={invoice} onOpen={onOpen} />)
            )}
        </>
    );
}

function DeliveryBody({ delivery, onOpen }: { delivery: Delivery; onOpen: (target: SalesDocumentTarget) => void }) {
    const trace = delivery.trace;
    const order: TraceSalesOrder | null = trace?.sales_order
        ?? (delivery.sales_order ? { ...delivery.sales_order, customer: delivery.customer } : null);
    const tally = trace?.tally ?? delivery.tally ?? null;

    return (
        <>
            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Sales Order">
                    <OrderRefLine order={order} onOpen={onOpen} />
                </Descriptions.Item>
                <Descriptions.Item label="Warehouse">
                    {delivery.warehouse ? `${delivery.warehouse.code} — ${delivery.warehouse.name}` : '—'}
                </Descriptions.Item>
                <Descriptions.Item label="Delivered">{instant(delivery.delivered_date)}</Descriptions.Item>
                <Descriptions.Item label="Reference">{delivery.reference ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Notes">{delivery.notes ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Tally">
                    <TallyLinkCell link={tally} />
                </Descriptions.Item>
            </Descriptions>

            <SectionTitle>Lines</SectionTitle>
            <Table
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={delivery.lines}
                scroll={{ x: 'max-content' }}
                columns={[
                    { title: 'Item', render: (_, line) => itemLabel(line.item) },
                    { title: 'Quantity', dataIndex: 'quantity' },
                ]}
            />

            <SectionTitle>Cartons</SectionTitle>
            <CartonsBlock cartons={trace?.cartons} />
        </>
    );
}

function InvoiceBody({ invoice, onOpen }: { invoice: Invoice; onOpen: (target: SalesDocumentTarget) => void }) {
    const trace = invoice.trace;
    const order: TraceSalesOrder | null = trace?.sales_order
        ?? (invoice.sales_order
            ? { ...invoice.sales_order, customer: { id: invoice.customer.id, code: invoice.customer.code, name: invoice.customer.name } }
            : null);
    const tally = trace?.tally ?? invoice.tally ?? null;

    return (
        <>
            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Status">
                    <Space size={6} wrap>
                        <Tag color={invoiceStatusColor[invoice.status]}>{invoice.status}</Tag>
                        {/* Paid is never set by this ERP: receipts are recorded in
                            Tally (DEC-20260809-003). Said beside the status so a
                            long-issued invoice is not read as unpaid. */}
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            Paid: recorded in Tally, not here
                        </Typography.Text>
                    </Space>
                </Descriptions.Item>
                <Descriptions.Item label="Customer">
                    {invoice.customer ? `${invoice.customer.code} — ${invoice.customer.name}` : '—'}
                </Descriptions.Item>
                <Descriptions.Item label="Sales Order">
                    <OrderRefLine order={order} onOpen={onOpen} />
                </Descriptions.Item>
                <Descriptions.Item label="Invoice Date">{invoice.invoice_date}</Descriptions.Item>
                <Descriptions.Item label="Due Date">{invoice.due_date ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Notes">{invoice.notes ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Tally">
                    <TallyLinkCell link={tally} />
                </Descriptions.Item>
            </Descriptions>

            <SectionTitle>Lines</SectionTitle>
            <Table
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={invoice.lines}
                scroll={{ x: 'max-content' }}
                columns={[
                    { title: 'Item', render: (_, line) => itemLabel(line.item) },
                    { title: 'Quantity', dataIndex: 'quantity' },
                    { title: 'Unit Price', dataIndex: 'unit_price' },
                    {
                        title: 'Amount',
                        render: (_, line) => (Number(line.quantity) * Number(line.unit_price)).toFixed(2),
                    },
                ]}
                summary={(lines) => {
                    const total = lines.reduce((sum, line) => sum + Number(line.quantity) * Number(line.unit_price), 0);
                    return (
                        <Table.Summary.Row>
                            <Table.Summary.Cell index={0} colSpan={3}>
                                <strong>Total</strong>
                            </Table.Summary.Cell>
                            <Table.Summary.Cell index={1}>
                                <strong>{total.toFixed(2)}</strong>
                            </Table.Summary.Cell>
                        </Table.Summary.Row>
                    );
                }}
            />
        </>
    );
}

// ------------------------------------------------------------- the drawer --

/** The list-page query prefix each kind's show query hangs under, so the pages' invalidations refetch the drawer too. */
const QUERY_SEGMENT: Record<SalesDocumentKind, string> = {
    sales_order: 'sales-orders',
    delivery: 'deliveries',
    invoice: 'invoices',
};

interface SalesDocumentDrawerProps {
    /** The document to show; null closes the drawer. */
    target: SalesDocumentTarget | null;
    onClose: () => void;
    /**
     * Follow a link inside the drawer to another document (a delivery's
     * order, an order's invoice) — the page decides how (it rewrites its
     * `?open=` param), so the URL stays the source of truth.
     */
    onOpen: (target: SalesDocumentTarget) => void;
    /** Rendered under the trace — the Sales Orders page hands its cost & margin section in here. */
    extra?: ReactNode;
}

/**
 * The detail drawer for one ERP-originated sales document — order,
 * delivery or invoice — showing the chain in the order it runs: header →
 * lines → deliveries (with the cartons that physically left) → invoices →
 * where each voucher stands in the Tally queue. Fed by the show endpoint
 * (`trace` rides only on that). Cancel, on an order the server says can be
 * cancelled, is the one write here; it touches no stock and queues nothing.
 *
 * Every document on this drawer is the ERP's own. Real sales are invoiced
 * in Tally (DEC-20260809-003) — the panel above the lists says so, and the
 * unvalidated-builder tag beside every Sales / Delivery Note voucher
 * repeats it.
 */
export default function SalesDocumentDrawer({ target, onClose, onOpen, extra }: SalesDocumentDrawerProps) {
    const queryClient = useQueryClient();

    // While the drawer slides shut `target` is already null; the last
    // document shown is kept for the closing frames so the body does not
    // flash to nothing on the way out (React's adjust-state-during-render
    // pattern — EntryDrawer does the same). The query is disabled once
    // closed and only READS the cache for that key; destroyOnHidden throws
    // the content away after the animation.
    const [lastTarget, setLastTarget] = useState<SalesDocumentTarget | null>(null);
    if (target && target !== lastTarget) {
        setLastTarget(target);
    }
    const shown = target ?? lastTarget;
    const kind = shown?.kind ?? null;
    const id = shown?.id ?? null;

    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['sales', kind ? QUERY_SEGMENT[kind] : 'none', 'show', id],
        queryFn: (): Promise<SalesDocument> => {
            if (kind === 'sales_order') return getSalesOrder(id as number);
            if (kind === 'delivery') return getDelivery(id as number);
            return getInvoice(id as number);
        },
        enabled: target !== null,
    });

    const cancelMutation = useMutation({
        mutationFn: cancelSalesOrder,
        onSuccess: async (order) => {
            message.success(`${order.document_number ?? documentTitle('sales_order', order)} cancelled — no stock moved and nothing was queued for Tally.`);
            await queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });
        },
    });

    // A refusal belongs to the order it was refused for — not to the next
    // document opened in this drawer.
    const resetCancel = cancelMutation.reset;
    useEffect(() => {
        resetCancel();
    }, [kind, id, resetCancel]);

    function confirmCancel(order: SalesOrder) {
        Modal.confirm({
            title: `Cancel ${order.document_number}?`,
            content:
                'The order will be marked cancelled and will refuse confirmation, deliveries and invoices from then on. '
                + 'No stock moves and nothing is sent to Tally.',
            okText: 'Cancel the order',
            okButtonProps: { danger: true },
            cancelText: 'Keep it',
            onOk: () => cancelMutation.mutateAsync(order.id).then(() => undefined, () => undefined),
        });
    }

    const order = kind === 'sales_order' ? (data as SalesOrder | undefined) : undefined;
    const title = shown
        ? documentTitle(shown.kind, data as { id: number; document_number?: string } | undefined, shown.id)
        : 'Document';
    const status = (error as { response?: { status?: number } } | null)?.response?.status;

    return (
        <Drawer
            open={target !== null}
            onClose={onClose}
            width="min(100vw, 760px)"
            destroyOnHidden
            title={title}
            footer={
                <Space wrap>
                    <Button onClick={onClose}>Close</Button>
                    {order?.can_cancel && (
                        <Tooltip title="Allowed only while nothing has been delivered and no invoice exists — the server decides.">
                            <Button danger loading={cancelMutation.isPending} onClick={() => confirmCancel(order)}>
                                Cancel order
                            </Button>
                        </Tooltip>
                    )}
                </Space>
            }
        >
            {isError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={
                        status === 404
                            ? 'No such document'
                            : status === 403
                                ? 'This login cannot view sales documents'
                                : 'Could not load this document'
                    }
                    description={
                        <Space direction="vertical" size={8}>
                            <span>{apiMessage(error, 'The ERP did not answer for this document. Check the connection and try again.')}</span>
                            {status !== 404 && status !== 403 && (
                                <Button size="small" onClick={() => refetch()}>
                                    Try again
                                </Button>
                            )}
                        </Space>
                    }
                />
            )}

            {cancelMutation.isError && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="Not cancelled"
                    description={apiMessage(cancelMutation.error, 'The order could not be cancelled.')}
                />
            )}

            {isLoading && <Skeleton active paragraph={{ rows: 6 }} />}

            {data !== undefined && kind === 'sales_order' && <SalesOrderBody order={data as SalesOrder} onOpen={onOpen} />}
            {data !== undefined && kind === 'delivery' && <DeliveryBody delivery={data as Delivery} onOpen={onOpen} />}
            {data !== undefined && kind === 'invoice' && <InvoiceBody invoice={data as Invoice} onOpen={onOpen} />}

            {data !== undefined ? extra : null}
        </Drawer>
    );
}
