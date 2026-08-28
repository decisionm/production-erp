import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
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

const statusColor: Record<PurchaseRequisitionStatus, string> = {
    draft: 'default',
    approved: 'green',
    rejected: 'red',
};

export default function PurchaseRequisitionsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailRequisition, setDetailRequisition] = useState<PurchaseRequisition | null>(null);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const queryClient = useQueryClient();

    const { data, isLoading, isPending, isError, error, refetch } = useQuery({
        // The page number is part of the key, and the key still STARTS with the
        // prefix the invalidate uses, so creating or approving one refreshes
        // whichever page is on screen.
        queryKey: ['procurement', 'purchase-requisitions', page, perPage],
        queryFn: () => listPurchaseRequisitions(page, perPage),
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RequisitionFormValues>({
        resolver: zodResolver(requisitionSchema),
        defaultValues: { lines: [{ item_id: undefined, quantity: undefined, notes: '' }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

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

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Purchase Requisitions</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Requisition</Button>
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
                            empty="No purchase requisitions yet."
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
                    { title: 'ID', dataIndex: 'id' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: PurchaseRequisitionStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Requested By', dataIndex: 'requested_by' },
                    { title: 'Needed By', dataIndex: 'needed_by_date' },
                    // What is being asked for, which is what a buyer scans
                    // this list to find. It showed only the line COUNT, so the
                    // queue named every requisition by a number and the
                    // products lived one click away in the drawer.
                    { title: 'Items', render: (_, row) => requisitionItemsLabel(row.lines) },
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
                                                    title: `Reject requisition #${row.id}?`,
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
                                    <InputNumber {...field} min={0} placeholder="Quantity" />
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
                title={`Purchase Requisition #${detailRequisition?.id}`}
                open={detailRequisition !== null}
                onClose={() => setDetailRequisition(null)}
                width="min(100vw, 560px)"
                destroyOnHidden
            >
                {detailRequisition && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailRequisition.status]}>{detailRequisition.status}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Requested By">
                                {detailRequisition.requested_by ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Needed By">
                                {detailRequisition.needed_by_date ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailRequisition.notes ?? '—'}</Descriptions.Item>
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
                                { title: 'Quantity', dataIndex: 'quantity' },
                                { title: 'Notes', dataIndex: 'notes' },
                            ]}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
