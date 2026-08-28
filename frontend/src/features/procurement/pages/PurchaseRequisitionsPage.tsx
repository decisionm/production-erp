import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm, useWatch } from 'react-hook-form';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { listAllItems } from '@/features/inventory/api';
import {
    approvePurchaseRequisition,
    createPurchaseRequisition,
    listPurchaseRequisitions,
    rejectPurchaseRequisition,
} from '@/features/procurement/api';
import type { PurchaseRequisition, PurchaseRequisitionStatus } from '@/features/procurement/types';
import { apiMessage } from '@/features/procurement/components/apiMessage';
import { prDrawerTitle, prNumber, requisitionStatusTag } from '@/features/procurement/documentWords';
import { poNumber } from '@/features/procurement/purchaseOrders';
import { instant } from '@/features/tally-sync/drawer';
import { itemLabel } from '@/lib/itemLabel';
import { ListEmpty } from '@/lib/ListEmpty';
import { requisitionItemsLabel } from '@/features/procurement/requisitionItems';

const requisitionSchema = z.object({
    needed_by_date: z.string().optional(),
    notes: z.string().optional(),
    lines: z
        .array(
            z.object({
                item_id: z.number({ error: 'Item is required' }),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                notes: z.string().optional(),
            }),
        )
        .min(1, 'Add at least one line'),
});
type RequisitionFormValues = z.infer<typeof requisitionSchema>;

const STATUS_OPTIONS: { value: PurchaseRequisitionStatus | ''; label: string }[] = [
    { value: '', label: 'All statuses' },
    { value: 'draft', label: 'Awaiting approval' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
];

/**
 * WHO decided, and WHEN — one spelling for the column and the drawer. A
 * requisition decided before the stamps existed reads "not recorded" rather
 * than inventing an approver.
 */
function decisionLine(row: PurchaseRequisition): string {
    if (row.status === 'approved') {
        return row.approved_by
            ? `Approved by ${row.approved_by}${row.approved_at ? ` · ${instant(row.approved_at)}` : ''}`
            : 'Approved (decider not recorded — predates the trail)';
    }
    if (row.status === 'rejected') {
        return row.rejected_by
            ? `Rejected by ${row.rejected_by}${row.rejected_at ? ` · ${instant(row.rejected_at)}` : ''}`
            : 'Rejected (decider not recorded — predates the trail)';
    }

    return 'Awaiting approval';
}

/**
 * THE PURCHASE REQUISITION QUEUE (28-Aug audit finding 8). Status and free
 * text live in the URL so the dashboard's "Requisitions to approve" tile
 * deep-links to ?status=draft — the tile's count and this list then show
 * the same rows, which is what makes the figure checkable. The server does
 * every narrowing (ListPurchaseRequisitionsRequest); `q` takes "PR-12" in
 * any spelling, a requester's name, or an item's name or SKU.
 *
 * The paper trail is on the row: who approved or rejected, and when. An
 * approved requisition offers Raise PO, which opens the Purchase Orders
 * page's create form prefilled with these lines (rates are typed there —
 * a requisition carries no money, FC-06). The orders already raised from
 * a requisition ride the row as links.
 */
export default function PurchaseRequisitionsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailRequisition, setDetailRequisition] = useState<PurchaseRequisition | null>(null);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [searchParams, setSearchParams] = useSearchParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const status = (searchParams.get('status') ?? '') as PurchaseRequisitionStatus | '';
    const q = searchParams.get('q') ?? '';

    const writeParams = (patch: { status?: string; q?: string }) => {
        setPage(1);
        setSearchParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [key, value] of Object.entries(patch)) {
                    if (value === undefined || value === '') next.delete(key);
                    else next.set(key, value);
                }
                return next;
            },
            { replace: true },
        );
    };

    const filters = { status, q, page, per_page: perPage };
    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        // The filters are part of the key, and the key still STARTS with the
        // prefix the invalidate uses, so creating or approving one refreshes
        // whichever view is on screen.
        queryKey: ['procurement', 'purchase-requisitions', filters],
        queryFn: () => listPurchaseRequisitions(filters),
        placeholderData: (previous) => previous,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RequisitionFormValues>({
        resolver: zodResolver(requisitionSchema),
        defaultValues: { lines: [{ item_id: undefined as unknown as number, quantity: undefined as unknown as number, notes: '' }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
    // Watched so each quantity field can carry the chosen item's unit.
    const watchedLines = useWatch({ control, name: 'lines' });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-requisitions'] });

    // A REFUSAL MUST REACH THE PERSON WHO CAUSED IT. These three had onSuccess
    // and nothing else, and the shared axios instance installs no global error
    // toast — only a 401 redirect. So a server refusal was swallowed whole: the
    // row simply stayed as it was and the operator was left to guess whether
    // the click had registered. The server's own sentence is shown, never
    // genericised, because the refusal names the rule.
    const refused = (fallback: string) => (error: unknown) =>
        Modal.error({ title: fallback, content: apiMessage(error, fallback) });

    const createMutation = useMutation({
        mutationFn: createPurchaseRequisition,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: refused('Could not create the requisition'),
    });
    const approveMutation = useMutation({
        mutationFn: approvePurchaseRequisition,
        onSuccess: invalidate,
        onError: refused('Could not approve the requisition'),
    });
    const rejectMutation = useMutation({
        mutationFn: rejectPurchaseRequisition,
        onSuccess: invalidate,
        onError: refused('Could not reject the requisition'),
    });

    /**
     * The handoff to the Purchase Orders page: the requisition's lines ride
     * router state (no refetch, no extra endpoint), and the create form
     * opens prefilled — items and quantities from here, the vendor and the
     * rates typed there, because a requisition names neither.
     */
    const raisePurchaseOrder = (row: PurchaseRequisition) => {
        navigate('/procurement/purchase-orders', {
            state: {
                raiseFromRequisition: {
                    purchase_requisition_id: row.id,
                    document_number: row.document_number ?? `PR-${row.id}`,
                    lines: row.lines
                        .filter((line) => line.item?.id !== undefined)
                        .map((line) => ({ item_id: line.item.id, quantity: Number(line.quantity) })),
                },
            },
        });
    };

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Purchase Requisitions</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Requisition</Button>
            </Space>

            <Space wrap style={{ marginBottom: 12 }}>
                <Input.Search
                    allowClear
                    defaultValue={q}
                    style={{ width: 320 }}
                    placeholder='Search — "PR-12", a requester, an item name or SKU'
                    onSearch={(value) => writeParams({ q: value.trim() })}
                />
                <Select
                    value={status}
                    style={{ width: 190 }}
                    options={STATUS_OPTIONS}
                    onChange={(value) => writeParams({ status: value })}
                />
            </Space>

            <Table<PurchaseRequisition>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={{ isPending, isError, error, refetch }}
                            entity="purchase requisitions"
                            empty={
                                status || q ? 'No purchase requisitions match these filters.' : 'No purchase requisitions yet.'
                            }
                        />
                    ),
                }}
                pagination={{
                    current: page,
                    pageSize: perPage,
                    // The server's count, not this page's length — otherwise
                    // the pager claims the queue ends at the first screen,
                    // which is exactly how the newest 20 came to look like all
                    // of them.
                    total: data?.meta?.total ?? data?.data?.length ?? 0,
                    showSizeChanger: true,
                    pageSizeOptions: [20, 50, 100, 200],
                    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} requisitions`,
                    onChange: (nextPage, nextSize) => {
                        setPage(nextPage);
                        setPerPage(nextSize);
                    },
                }}
                columns={[
                    { title: 'Requisition', render: (_, row) => row.document_number ?? prNumber(row) },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: PurchaseRequisitionStatus, row) => {
                            const tag = requisitionStatusTag(status);
                            return (
                                <Tooltip title={decisionLine(row)}>
                                    <Tag color={tag.color}>{tag.label}</Tag>
                                </Tooltip>
                            );
                        },
                    },
                    { title: 'Requested By', dataIndex: 'requested_by' },
                    {
                        title: 'Decision',
                        render: (_, row) =>
                            row.status === 'draft' ? (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ) : (
                                <Typography.Text style={{ fontSize: 12 }}>{decisionLine(row)}</Typography.Text>
                            ),
                    },
                    { title: 'Needed By', dataIndex: 'needed_by_date' },
                    // What is being asked for, which is what a buyer scans
                    // this list to find.
                    { title: 'Items', render: (_, row) => requisitionItemsLabel(row.lines) },
                    {
                        title: 'Purchase orders',
                        render: (_, row) =>
                            (row.purchase_orders ?? []).length === 0 ? (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ) : (
                                <Space size={4} wrap>
                                    {(row.purchase_orders ?? []).map((order) => (
                                        <Link key={order.id} to={`/procurement/purchase-orders?po=${order.id}`}>
                                            {poNumber(order)}
                                        </Link>
                                    ))}
                                </Space>
                            ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailRequisition(row)}>
                                    View
                                </Button>
                                {row.status === 'draft' && (
                                    <>
                                        <Button
                                            size="small"
                                            onClick={() => approveMutation.mutate(row.id)}
                                            loading={approveMutation.isPending}
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            size="small"
                                            danger
                                            onClick={() =>
                                                // Rejecting is irreversible and sat one
                                                // mis-tap from Approve. The confirm names
                                                // the requisition so the dialog is about a
                                                // row rather than about a verb.
                                                Modal.confirm({
                                                    title: `Reject requisition PR-${row.id}?`,
                                                    content: 'This cannot be undone.',
                                                    okText: 'Reject',
                                                    okButtonProps: { danger: true },
                                                    onOk: () => rejectMutation.mutate(row.id),
                                                })
                                            }
                                            loading={rejectMutation.isPending}
                                        >
                                            Reject
                                        </Button>
                                    </>
                                )}
                                {row.status === 'approved' && (
                                    <Button size="small" type="primary" ghost onClick={() => raisePurchaseOrder(row)}>
                                        Raise PO
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Purchase Requisition"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    <Form.Item label="Needed By">
                        <Controller
                            name="needed_by_date"
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
                        <div key={field.id}>
                        <Space align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <Controller
                                name={`lines.${index}.item_id`}
                                control={control}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        options={itemOptions}
                                        showSearch
                                        optionFilterProp="label"
                                        style={{ width: 260 }}
                                        placeholder="Item"
                                    />
                                )}
                            />
                            <Controller
                                name={`lines.${index}.quantity`}
                                control={control}
                                render={({ field }) => (
                                    <InputNumber
                                        {...field}
                                        min={0}
                                        placeholder="Quantity"
                                        // The unit rides the field, so "500" reads
                                        // as 500 of something (audit finding 8).
                                        addonAfter={
                                            items?.data.find((item) => item.id === watchedLines?.[index]?.item_id)?.uom ?? undefined
                                        }
                                    />
                                )}
                            />
                            <Button danger onClick={() => remove(index)}>Remove</Button>
                        </Space>
                        {/*
                          Only the array-level error was rendered, so a line
                          with no item or no quantity failed validation with
                          nothing shown against it: pressing OK appeared to do
                          nothing and there was no way to find the bad row. The
                          messages were already in form state.
                        */}
                        {(() => {
                            const line = errors.lines?.[index];
                            const messages = [line?.item_id?.message, line?.quantity?.message].filter(Boolean) as string[];

                            return messages.length > 0 ? (
                                <div style={{ color: '#ff4d4f', marginTop: 4 }}>{messages.join(' · ')}</div>
                            ) : null;
                        })()}
                        </div>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() => append({ item_id: undefined as unknown as number, quantity: undefined as unknown as number, notes: '' })}
                    >
                        Add Line
                    </Button>
                </Form>
            </Modal>

            <Drawer
                title={prDrawerTitle(detailRequisition)}
                open={detailRequisition !== null}
                onClose={() => setDetailRequisition(null)}
                width="min(100vw, 560px)"
                destroyOnHidden
            >
                {detailRequisition && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                {(() => {
                                    const tag = requisitionStatusTag(detailRequisition.status);
                                    return <Tag color={tag.color}>{tag.label}</Tag>;
                                })()}
                            </Descriptions.Item>
                            <Descriptions.Item label="Requested By">
                                {detailRequisition.requested_by ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Decision">{decisionLine(detailRequisition)}</Descriptions.Item>
                            <Descriptions.Item label="Needed By">
                                {detailRequisition.needed_by_date ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailRequisition.notes ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Purchase orders">
                                {(detailRequisition.purchase_orders ?? []).length === 0 ? (
                                    'None raised yet'
                                ) : (
                                    <Space size={4} wrap>
                                        {(detailRequisition.purchase_orders ?? []).map((order) => (
                                            <Link key={order.id} to={`/procurement/purchase-orders?po=${order.id}`}>
                                                {poNumber(order)} ({order.status})
                                            </Link>
                                        ))}
                                    </Space>
                                )}
                            </Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Lines
                        </Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailRequisition.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                { title: 'Item', render: (_, line) => itemLabel(line.item) },
                                {
                                    title: 'Quantity',
                                    align: 'right',
                                    render: (_, line) => `${line.quantity}${line.item?.uom ? ` ${line.item.uom}` : ''}`,
                                },
                                { title: 'Notes', dataIndex: 'notes' },
                            ]}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
