import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Collapse, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listAllItems } from '@/features/inventory/api';
import {
    confirmSalesOrder,
    createSalesOrder,
    getSalesOrderCostInsight,
    listCustomers,
    listSalesOrders,
} from '@/features/sales/api';
import { hasActiveFilters } from '@/features/sales/filters';
import SalesDocumentDrawer from '@/features/sales/SalesDocumentDrawer';
import SalesFilterBar from '@/features/sales/SalesFilterBar';
import TallyMirrorPanel from '@/features/sales/TallyMirrorPanel';
import type {
    SalesCostActualSource,
    SalesCostComponent,
    SalesCostComponentStatus,
    SalesCostLine,
    SalesCostOrderTotal,
    SalesOrder,
    SalesOrderStatus,
} from '@/features/sales/types';
import { salesRateSourceLabel } from '@/features/sales/types';
import { useSalesListParams } from '@/features/sales/useSalesListParams';
import { formatDate } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';
import { listEmptyText } from '@/features/sales/drawer';

const orderSchema = z.object({
    customer_id: z.number({ error: 'Customer is required' }),
    order_date: z.string({ error: 'Order date is required' }),
    expected_date: z.string().optional(),
    notes: z.string().optional(),
    lines: z
        .array(
            z.object({
                item_id: z.number({ error: 'Item is required' }),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                unit_price: z.number().min(0),
            }),
        )
        .min(1, 'Add at least one line'),
});
type OrderFormValues = z.infer<typeof orderSchema>;

const statusColor: Record<SalesOrderStatus, string> = {
    draft: 'default',
    confirmed: 'blue',
    partially_delivered: 'gold',
    completed: 'green',
    cancelled: 'red',
};

// ============================================================ cost & margin ==

/**
 * A rupee figure from a bcmath decimal STRING — never parsed for arithmetic,
 * only for display, and only at the last moment.
 *
 * SIX PLACES IS NOT A TYPO. A per-piece cost is a DIVIDED figure, three orders
 * of magnitude below the per-kg and per-box rates it comes from, and the server
 * computes it at six for exactly that reason: a tray component at ₹0.0004 would
 * print as ₹0.0000 at four, and a cost of zero is the one lie this endpoint
 * refuses to tell. Order totals and per-kg rates stay at the scale the rest of
 * the system prices money at.
 *
 * NULL IS NOT ZERO and the caller must never let it become one — every null in
 * this payload has a `reason` sentence behind it, which is printed beside this.
 */
const fmtMoney = (v: string | null | undefined, dp: 2 | 4 | 6 = 2): string => {
    if (v === null || v === undefined || v === '') return '—';
    const n = parseFloat(v);
    if (Number.isNaN(n)) return '—';
    return `₹${n.toLocaleString('en-IN', { minimumFractionDigits: dp, maximumFractionDigits: dp })}`;
};

const caption = { fontSize: 12, display: 'block' } as const;
const numeric = { fontVariantNumeric: 'tabular-nums' } as const;

/**
 * A margin, coloured by sign and printed at the server's own precision.
 *
 * The decimal STRING is rendered, not a reparsed float — the sign is all that
 * is read out of it. Tabular figures so margins line up down a column of lines.
 *
 * Zero is neither a win nor a loss and is left uncoloured: painting a
 * break-even line green would congratulate somebody for it.
 */
function MarginPct({ value }: { value: string | null }) {
    if (value === null) {
        return (
            <Typography.Text type="secondary" style={numeric}>
                margin —
            </Typography.Text>
        );
    }

    const n = parseFloat(value);
    const colour = Number.isNaN(n) || n === 0 ? undefined : n > 0 ? '#389e0d' : '#cf1322';

    return (
        <Typography.Text strong style={{ ...numeric, color: colour }}>
            {value}% margin
        </Typography.Text>
    );
}

const COMPONENT_STATUS_TAG: Record<SalesCostComponentStatus, { color: string; label: string }> = {
    priced: { color: 'green', label: 'priced' },
    unknown: { color: 'orange', label: 'unknown' },
    // NOT a failure and deliberately not orange: excluded means "there is no
    // price to know" — a Clear bottle takes no masterbatch — and it does not
    // null the total above it. Reading it as a gap sends somebody hunting for
    // a rate that was never meant to exist.
    excluded: { color: 'default', label: 'not costed' },
};

/**
 * The batch an actual came out of, said the way the drawer says it.
 *
 * Every part is conditional because every part is nullable on the wire, and a
 * tag reading "Actual — batch null" is worse than one reading "Actual".
 */
function actualBadgeLabel(source: SalesCostActualSource | null): string {
    if (source === null) return 'Actual';

    const bits: string[] = [];
    if (source.batch_number) bits.push(`batch ${source.batch_number}`);
    if (source.approved_at) bits.push(`approved ${dayjs(source.approved_at).format('DD MMM')}`);
    else if (source.production_date) bits.push(`run ${formatDate(source.production_date)}`);

    return bits.length > 0 ? `Actual — ${bits.join(' · ')}` : 'Actual';
}

/**
 * THE ANATOMY, for readers the server chose to show it to.
 *
 * Rendered only because `components` ARRIVED — no permission check of this
 * client's own. The server has already decided, its decision cannot go stale
 * against a cached /auth/me, and a second opinion here could only ever
 * disagree with it. Nothing renders in its place otherwise: no locked panel,
 * no "ask for access" shell, no heading over an absent key.
 *
 * THERE ARE NO BAG OR SUPPLIER LOT COLUMNS, and their deletion is the point.
 * The owner's correction (2-Aug) ended the "next bag out of the store" basis:
 * with ONE common resin input point, no single bag stands behind a price.
 * Resin is quoted at the common pool's weighted average, which the "Rate
 * from" column names — and the payload no longer carries a bag identity to
 * put in a column even if one were wanted.
 */
function ComponentBreakdown({ components }: { components: SalesCostComponent[] }) {
    if (components.length === 0) return null;

    return (
        <Collapse
            ghost
            size="small"
            style={{ marginTop: 4 }}
            items={[
                {
                    key: 'breakdown',
                    label: (
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            How this estimate is built
                        </Typography.Text>
                    ),
                    children: (
                        <Table<SalesCostComponent>
                            size="small"
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            dataSource={components}
                            rowKey={(row, index) => `${row.kind}-${index}`}
                            columns={[
                                {
                                    title: 'Part',
                                    render: (_, row) => (
                                        <Space direction="vertical" size={0}>
                                            <Typography.Text>{row.kind}</Typography.Text>
                                            {row.item !== null && (
                                                <Typography.Text type="secondary" style={caption}>
                                                    {itemLabel(row.item)}
                                                </Typography.Text>
                                            )}
                                            {/* The component's own explanation of why it
                                                could not be priced — the suggestion
                                                services' wording, passed through rather
                                                than re-said in a second vocabulary. */}
                                            {row.reason !== null && (
                                                <Typography.Text type="secondary" style={caption}>
                                                    {row.reason}
                                                </Typography.Text>
                                            )}
                                        </Space>
                                    ),
                                },
                                {
                                    title: 'State',
                                    render: (_, row) => (
                                        <Tag color={COMPONENT_STATUS_TAG[row.status]?.color ?? 'default'}>
                                            {COMPONENT_STATUS_TAG[row.status]?.label ?? row.status}
                                        </Tag>
                                    ),
                                },
                                {
                                    title: 'Per piece',
                                    align: 'right',
                                    render: (_, row) => (
                                        <span style={numeric}>{fmtMoney(row.per_unit_cost, 6)}</span>
                                    ),
                                },
                                {
                                    title: 'Rate',
                                    align: 'right',
                                    render: (_, row) => (
                                        <span style={numeric}>
                                            {fmtMoney(row.rate, 4)}
                                            {row.rate !== null && row.rate_unit !== null
                                                ? ` ${row.rate_unit.replace(/^per_/, '/')}`
                                                : ''}
                                        </span>
                                    ),
                                },
                                {
                                    title: 'Rate from',
                                    render: (_, row) => salesRateSourceLabel(row.rate_source),
                                },
                                {
                                    title: 'Basis',
                                    render: (_, row) => row.basis ?? '—',
                                },
                            ]}
                        />
                    ),
                },
            ]}
        />
    );
}

/**
 * One order line's two answers, kept apart on the screen the way the server
 * keeps them apart on the wire.
 *
 * THE ESTIMATE IS ALWAYS BADGED, even when it is the only figure there — an
 * unlabelled number next to a selling price reads as a fact, and this one is
 * a projection off today's store.
 *
 * THE FIGURE AND ITS SENTENCE BOTH RENDER. A `reason` does not replace a
 * figure and must not hide one: the server sets a reason on a perfectly good
 * cost when the LINE has no price to measure a margin against, so gating the
 * money on `reason === null` would blank a cost that was worked out correctly.
 */
function LineCostBlock({ line }: { line: SalesCostLine }) {
    const { estimate, actual } = line;
    // THE DISCRIMINATOR IS THE COST, NOT THE SOURCE. A batch can exist and
    // still be unpriceable, and that is a third state — see SalesCostActual.
    const hasActual = actual.actual_unit_cost !== null;

    return (
        <div style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12, marginBottom: 12 }}>
            <Typography.Text strong>{itemLabel(line.item)}</Typography.Text>
            <Typography.Text type="secondary" style={{ ...caption, ...numeric }}>
                {line.quantity} × {fmtMoney(line.unit_price)}
            </Typography.Text>

            <Space size={8} wrap style={{ marginTop: 10 }}>
                <Tag color="blue">Estimate</Tag>
                <Typography.Text strong style={numeric}>
                    {fmtMoney(estimate.estimated_unit_cost, 6)}
                </Typography.Text>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    per piece
                </Typography.Text>
                <MarginPct value={estimate.estimated_margin_pct} />
            </Space>

            {/* A READ STAMP, not a costing stamp — the server stamps it when
                the row is serialized, so it is labelled as when the figures
                were read, which is what it honestly is. */}
            <Typography.Text type="secondary" style={caption}>
                Read {dayjs(estimate.as_of).format('DD MMM YYYY HH:mm')}
            </Typography.Text>

            {estimate.reason !== null && (
                <Typography.Text type="warning" style={caption}>
                    {estimate.reason}
                </Typography.Text>
            )}

            {/* The identity-free explanations, present for everyone: a
                salesperson is never shown a figure they cannot account for,
                only one they cannot trace to a supplier. */}
            {Object.entries(estimate.sources ?? {}).map(([kind, words]) => (
                <Typography.Text key={kind} type="secondary" style={caption}>
                    {kind} — {words}
                </Typography.Text>
            ))}

            {estimate.components !== undefined && <ComponentBreakdown components={estimate.components} />}

            {hasActual ? (
                <>
                    <Space size={8} wrap style={{ marginTop: 10 }}>
                        <Tag color="green">{actualBadgeLabel(actual.source)}</Tag>
                        {/* FOUR PLACES HERE, SIX ON THE ESTIMATE, and the
                            difference is the wire's, not a choice: the actual
                            comes from BagCostAllocationService, which divides
                            the batch total by accepted pieces at money scale.
                            Padding it to six would invent two digits of
                            precision the server never computed — the same
                            figure, at the same four places, as the batch cost
                            block the approvers read. */}
                        <Typography.Text strong style={numeric}>
                            {fmtMoney(actual.actual_unit_cost, 4)}
                        </Typography.Text>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            per piece
                        </Typography.Text>
                        <MarginPct value={actual.actual_margin_pct} />
                    </Space>
                    {actual.source !== null && (
                        <Typography.Text type="secondary" style={caption}>
                            {actual.source.basis}
                            {actual.source.production_date !== null
                                ? ` · run ${formatDate(actual.source.production_date)}`
                                : ''}
                        </Typography.Text>
                    )}
                    {actual.reason !== null && (
                        <Typography.Text type="warning" style={caption}>
                            {actual.reason}
                        </Typography.Text>
                    )}
                </>
            ) : (
                <div style={{ marginTop: 10 }}>
                    {/* TWO SILENCES, AND THEY ARE NOT THE SAME SILENCE. No batch
                        at all is a grey "not made yet"; a batch that exists but
                        could not be priced keeps its identity on screen, because
                        telling somebody there is no batch for one they approved
                        yesterday is precisely the confidently-wrong sentence
                        this endpoint exists to refuse. Either way the server's
                        own words carry the explanation — never this client
                        switching on their text. */}
                    <Tag color={actual.source === null ? 'default' : 'orange'}>
                        {actual.source === null ? 'No actual yet' : actualBadgeLabel(actual.source)}
                    </Tag>
                    {actual.reason !== null && (
                        <Typography.Text type="secondary" style={{ ...caption, marginTop: 4 }}>
                            {actual.reason}
                        </Typography.Text>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * The order-level roll-up, rendered under the server's own label.
 *
 * BOTH blocks always render, present figures or not, and the actual one always
 * carries its `basis` sentence: there is no finished-goods cost allocation in
 * this system, so an order's actual is the batch figures of its products added
 * up, and a reader must never infer otherwise from a number that happens to be
 * there. Revenue with no cost beside it is a normal state — the server fills
 * revenue in even when it refuses a partial cost total.
 */
function OrderTotalBlock({ total }: { total: SalesCostOrderTotal }) {
    const isActual = total.label === 'actual';
    const priced = total.cost_total !== null;

    return (
        <div style={{ marginBottom: 8 }}>
            <Space size={8} wrap>
                <Tag color={isActual ? (priced ? 'green' : 'default') : 'blue'}>
                    {isActual ? 'Order actual' : 'Order estimate'}
                </Tag>
                <Typography.Text style={numeric}>cost {fmtMoney(total.cost_total)}</Typography.Text>
                <Typography.Text style={numeric}>revenue {fmtMoney(total.revenue_total)}</Typography.Text>
                <MarginPct value={total.margin_pct} />
            </Space>
            {total.basis !== undefined && (
                <Typography.Text type="secondary" style={caption}>
                    {total.basis}
                </Typography.Text>
            )}
            {total.reason !== null && (
                <Typography.Text type="secondary" style={caption}>
                    {total.reason}
                </Typography.Text>
            )}
        </div>
    );
}

/**
 * WHAT THIS ORDER COSTS — fetched when the drawer opens, and only then.
 *
 * DELIBERATELY NOT A COLUMN ON THE ORDERS TABLE. Costing one line reads the
 * common resin pool plus several moving-average lookups per distinct product,
 * so a margin column would fire one of those reads per row on every page of
 * the list — twenty orders' worth of production and inventory queries to fill
 * a column, paid on every visit, whether or not anybody reads it. The drawer
 * asks for one order because somebody asked to see that order.
 *
 * Mounted by the drawer's own `detailOrder &&` guard, so the query starts when
 * the drawer opens and the key changes when the reader moves to another order.
 *
 * Rendered from the endpoint's OWN `lines`, which carry their item, quantity
 * and unit price — no positional join back into the order's line array, whose
 * ordering is not a contract.
 */
function OrderCostSection({ orderId }: { orderId: number }) {
    const { data, isPending, isError } = useQuery({
        queryKey: ['sales', 'sales-orders', orderId, 'cost-insight'],
        queryFn: () => getSalesOrderCostInsight(orderId),
    });

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 24 }}>
                Cost &amp; margin
            </Typography.Title>

            {/* Quiet on both — one line, never a blank panel under a heading
                that has promised figures. */}
            {isPending && (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Reading cost and margin…
                </Typography.Text>
            )}
            {isError && (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Cost and margin could not be read for this order.
                </Typography.Text>
            )}

            {data && (
                <>
                    <OrderTotalBlock total={data.order_estimate} />
                    <OrderTotalBlock total={data.order_actual} />

                    <div style={{ marginTop: 12 }}>
                        {data.lines.map((line) => (
                            <LineCostBlock key={line.sales_order_line_id} line={line} />
                        ))}
                    </div>
                </>
            )}
        </>
    );
}

export default function SalesOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    // The filters, the page and the open drawer all live in the URL — a
    // pasted link is the same view. The server does the narrowing.
    const { filters, setFilters, setPage, target, openTarget, closeTarget } = useSalesListParams('sales_order');
    const filtersActive = hasActiveFilters('sales_order', filters);

    const { data, isLoading, isPending, isError, error } = useQuery({
        queryKey: ['sales', 'sales-orders', 'list', filters],
        queryFn: () => listSalesOrders(filters),
        placeholderData: (previous) => previous,
    });
    const { data: customers } = useQuery({
        queryKey: ['sales', 'customers', 'picker'],
        // Explicit thunk: passing listCustomers directly would hand
        // TanStack's query context in as the page number. A picker
        // wants breadth, so it asks for the server's clamp (200)
        // rather than the 20-row default this used to get.
        queryFn: () => listCustomers(1, 200),
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

    const customerOptions = customers?.data.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })) ?? [];
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<OrderFormValues>({
        resolver: zodResolver(orderSchema),
        defaultValues: { lines: [{ item_id: undefined, quantity: undefined, unit_price: undefined }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });

    const createMutation = useMutation({
        mutationFn: createSalesOrder,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });
    const confirmMutation = useMutation({ mutationFn: confirmSalesOrder, onSuccess: invalidate });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Sales Orders</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Sales Order</Button>
            </Space>

            {/* What this list is NOT: real sales are invoiced in Tally and
                are not mirrored here (DEC-20260809-003) — the server's own
                words, so an empty table never reads as "no sales". */}
            <TallyMirrorPanel />

            <SalesFilterBar kind="sales_order" filters={filters} onChange={setFilters} />

            <Table<SalesOrder>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                locale={{ emptyText: listEmptyText({ isPending, isError, error }, 'sales_order', filtersActive) }}
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
                    { title: 'Number', render: (_, row) => <strong>{row.document_number ?? `SO-${row.id}`}</strong> },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: SalesOrderStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Customer', render: (_, row) => row.customer?.name ?? '—' },
                    { title: 'Order Date', dataIndex: 'order_date' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: 'Delivered / Invoiced',
                        render: (_, row) =>
                            row.totals ? (
                                <span style={numeric}>
                                    {row.totals.delivered_quantity} / {row.totals.invoiced_quantity} of {row.totals.ordered_quantity}
                                </span>
                            ) : (
                                '—'
                            ),
                    },
                    {
                        title: 'Docs',
                        render: (_, row) =>
                            row.deliveries_count !== undefined && row.invoices_count !== undefined ? (
                                <Typography.Text type="secondary">
                                    {row.deliveries_count} DN · {row.invoices_count} INV
                                </Typography.Text>
                            ) : (
                                '—'
                            ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => openTarget({ kind: 'sales_order', id: row.id })}>
                                    View
                                </Button>
                                {row.status === 'draft' && (
                                    <Button
                                        size="small"
                                        onClick={() => confirmMutation.mutate(row.id)}
                                        loading={confirmMutation.isPending}
                                    >
                                        Confirm
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Sales Order"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={760}
            >
                <Form layout="vertical">
                    <Form.Item label="Customer" validateStatus={errors.customer_id ? 'error' : ''} help={errors.customer_id?.message}>
                        <Controller
                            name="customer_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={customerOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Order Date" validateStatus={errors.order_date ? 'error' : ''} help={errors.order_date?.message}>
                        <Controller
                            name="order_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Expected Date">
                        <Controller
                            name="expected_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Lines</Typography.Text>
                    {errors.lines?.root && (
                        <div style={{ color: '#ff4d4f', marginBottom: 8 }}>{errors.lines.root.message}</div>
                    )}
                    {fields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <Controller
                                name={`lines.${index}.item_id`}
                                control={control}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        options={itemOptions}
                                        showSearch
                                        optionFilterProp="label"
                                        style={{ width: 220 }}
                                        placeholder="Item"
                                    />
                                )}
                            />
                            <Controller
                                name={`lines.${index}.quantity`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Quantity" />}
                            />
                            <Controller
                                name={`lines.${index}.unit_price`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Unit Price" />}
                            />
                            <Button danger onClick={() => remove(index)}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() =>
                            append({
                                item_id: undefined as unknown as number,
                                quantity: undefined as unknown as number,
                                unit_price: undefined as unknown as number,
                            })
                        }
                    >
                        Add Line
                    </Button>
                </Form>
            </Modal>

            {/* The trace drawer: header → lines → deliveries (with cartons) →
                invoices → Tally state, from the show endpoint. Cost & margin
                rides underneath, fetched only for the order being looked at
                (see OrderCostSection). */}
            <SalesDocumentDrawer
                target={target}
                onClose={closeTarget}
                onOpen={openTarget}
                extra={target?.kind === 'sales_order' ? <OrderCostSection orderId={target.id} /> : undefined}
            />
        </>
    );
}
