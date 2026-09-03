import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import { createOpportunity, listOpportunities, updateOpportunity, updateOpportunityStage } from '@/features/crm/api';
import {
    OPPORTUNITY_DEFAULT_SORT,
    OPPORTUNITY_LIST_SPEC,
    OPPORTUNITY_SORT_FIELDS,
    type OpportunityListParams,
    opportunitiesQueryKey,
    opportunityServerFilters,
} from '@/features/crm/opportunityList';
import type { Opportunity, OpportunityStage } from '@/features/crm/types';
import { listCustomers } from '@/features/sales/api';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const stageColor: Record<OpportunityStage, string> = {
    prospecting: 'default',
    qualification: 'blue',
    proposal: 'gold',
    negotiation: 'orange',
    won: 'green',
    lost: 'red',
};

const opportunitySchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    customer_id: z.number({ error: 'Customer is required' }),
    estimated_value: z.number().min(0).optional(),
    probability: z.number().min(0).max(100).optional(),
    expected_close_date: z.string().optional(),
    notes: z.string().optional(),
});
type OpportunityFormValues = z.infer<typeof opportunitySchema>;

const stageOptions: { value: OpportunityStage; label: string }[] = [
    { value: 'prospecting', label: 'Prospecting' },
    { value: 'qualification', label: 'Qualification' },
    { value: 'proposal', label: 'Proposal' },
    { value: 'negotiation', label: 'Negotiation' },
    { value: 'won', label: 'Won' },
    { value: 'lost', label: 'Lost' },
];

export default function OpportunitiesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailOpportunity, setDetailOpportunity] = useState<Opportunity | null>(null);
    const [editingOpportunity, setEditingOpportunity] = useState<Opportunity | null>(null);
    const queryClient = useQueryClient();

    // THE LIST'S VIEW IS ITS URL: sort, page and page size, sorted and paged
    // on the SERVER over every opportunity.
    const { params, setParams, setPage } = useListParams<OpportunityListParams>(OPPORTUNITY_LIST_SPEC);
    const filters = useMemo(() => opportunityServerFilters(params), [params]);
    const { data, isLoading } = useQuery({
        queryKey: opportunitiesQueryKey(filters),
        queryFn: () => listOpportunities(filters),
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
    // WS-B: `StoreOpportunityRequest` refuses a RETIRED customer, so a new
    // opportunity is not offered one. The EDIT form is a separate list
    // because an opportunity opened before the customer was retired still
    // names them: that row stays on screen, marked and unselectable, rather
    // than the field silently blanking the record's own customer.
    const customerOptions = activePickerOptions(customers?.data, {
        isActive: (c) => c.is_active,
        option: (c) => ({ value: c.id, label: `${c.code} — ${c.name}` }),
    });
    const editingCustomerOptions = activePickerOptions(customers?.data, {
        isActive: (c) => c.is_active,
        option: (c) => ({ value: c.id, label: `${c.code} — ${c.name}` }),
        keep: editingOpportunity?.customer?.id ?? null,
    });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<OpportunityFormValues>({
        resolver: zodResolver(opportunitySchema),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['crm', 'opportunities'] });

    const createMutation = useMutation({
        mutationFn: createOpportunity,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const stageMutation = useMutation({
        mutationFn: ({ id, stage }: { id: number; stage: OpportunityStage }) => updateOpportunityStage(id, stage),
        onSuccess: invalidate,
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<OpportunityFormValues>({ resolver: zodResolver(opportunitySchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & OpportunityFormValues) => updateOpportunity(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingOpportunity(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update opportunity', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Opportunities</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Opportunity</Button>
            </Space>

            <Table<Opportunity>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries every opportunity. Customer is a relation's
                // name: no sorter.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, OPPORTUNITY_SORT_FIELDS, OPPORTUNITY_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'opportunities')}
                columns={[
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        key: 'name',
                        sorter: true,
                        sortOrder: columnSortOrder('name', params.sort, OPPORTUNITY_DEFAULT_SORT),
                    },
                    { title: 'Customer', render: (_, row) => row.customer.name },
                    {
                        title: 'Estimated Value',
                        dataIndex: 'estimated_value',
                        key: 'estimated_value',
                        sorter: true,
                        sortOrder: columnSortOrder('estimated_value', params.sort, OPPORTUNITY_DEFAULT_SORT),
                    },
                    {
                        title: 'Probability %',
                        dataIndex: 'probability',
                        key: 'probability',
                        sorter: true,
                        sortOrder: columnSortOrder('probability', params.sort, OPPORTUNITY_DEFAULT_SORT),
                    },
                    {
                        title: 'Expected Close',
                        dataIndex: 'expected_close_date',
                        key: 'expected_close_date',
                        sorter: true,
                        sortOrder: columnSortOrder('expected_close_date', params.sort, OPPORTUNITY_DEFAULT_SORT),
                    },
                    {
                        title: 'Stage',
                        dataIndex: 'stage',
                        key: 'stage',
                        sorter: true,
                        sortOrder: columnSortOrder('stage', params.sort, OPPORTUNITY_DEFAULT_SORT),
                        render: (stage: OpportunityStage, row) => (
                            <Select
                                value={stage}
                                options={stageOptions}
                                style={{ width: 160 }}
                                onChange={(value) => stageMutation.mutate({ id: row.id, stage: value })}
                            />
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailOpportunity(row)}>
                                    View
                                </Button>
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setEditingOpportunity(row);
                                        resetEdit({
                                            name: row.name,
                                            customer_id: row.customer.id,
                                            estimated_value: row.estimated_value ? Number(row.estimated_value) : undefined,
                                            probability: row.probability ? Number(row.probability) : undefined,
                                            expected_close_date: row.expected_close_date ?? undefined,
                                            notes: row.notes ?? '',
                                        });
                                    }}
                                >
                                    Edit
                                </Button>
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Opportunity"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Customer"
                        validateStatus={errors.customer_id ? 'error' : ''}
                        help={errors.customer_id?.message}
                    >
                        <Controller
                            name="customer_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={customerOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Estimated Value">
                        <Controller
                            name="estimated_value"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Probability %">
                        <Controller
                            name="probability"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} max={100} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Expected Close Date">
                        <Controller
                            name="expected_close_date"
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
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingOpportunity?.name}"`}
                open={editingOpportunity !== null}
                onCancel={() => setEditingOpportunity(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingOpportunity) editMutation.mutate({ id: editingOpportunity.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Customer"
                        validateStatus={editErrors.customer_id ? 'error' : ''}
                        help={editErrors.customer_id?.message}
                    >
                        <Controller
                            name="customer_id"
                            control={editControl}
                            render={({ field }) => (
                                <Select {...field} options={editingCustomerOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Estimated Value">
                        <Controller
                            name="estimated_value"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Probability %">
                        <Controller
                            name="probability"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={0} max={100} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Expected Close Date">
                        <Controller
                            name="expected_close_date"
                            control={editControl}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    value={field.value ? dayjs(field.value) : undefined}
                                    onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Drawer
                title={detailOpportunity?.name}
                open={detailOpportunity !== null}
                onClose={() => setDetailOpportunity(null)}
                width="min(100vw, 440px)"
                destroyOnHidden
            >
                {detailOpportunity && (
                    <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Stage">
                            <Tag color={stageColor[detailOpportunity.stage]}>{detailOpportunity.stage}</Tag>
                        </Descriptions.Item>
                        <Descriptions.Item label="Customer">{detailOpportunity.customer.name}</Descriptions.Item>
                        <Descriptions.Item label="Estimated Value">
                            {detailOpportunity.estimated_value ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Probability">
                            {detailOpportunity.probability ? `${detailOpportunity.probability}%` : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Expected Close">
                            {detailOpportunity.expected_close_date ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Source Lead">
                            {detailOpportunity.lead_id ? `#${detailOpportunity.lead_id}` : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Assigned To">{detailOpportunity.assigned_to ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Notes">{detailOpportunity.notes ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Created">
                            {new Date(detailOpportunity.created_at).toLocaleString()}
                        </Descriptions.Item>
                    </Descriptions>
                )}
            </Drawer>
        </>
    );
}
