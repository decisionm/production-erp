import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Switch, Table, Tag, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createGstRegistration, listGstRegistrations, updateGstRegistration } from '@/features/compliance/api';
import {
    GST_REGISTRATION_DEFAULT_SORT,
    GST_REGISTRATION_LIST_SPEC,
    GST_REGISTRATION_SORT_FIELDS,
    type GstRegistrationListParams,
    gstRegistrationServerFilters,
    gstRegistrationsQueryKey,
} from '@/features/compliance/gstRegistrationList';
import type { GstRegistration } from '@/features/compliance/types';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const registrationSchema = z.object({
    gstin: z
        .string()
        .regex(/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/, 'Enter a valid 15-character GSTIN'),
    state_code: z.string().regex(/^[0-9]{2}$/, 'Enter a 2-digit GST state code'),
    state_name: z.string().min(1, 'State name is required').max(255),
    is_primary: z.boolean().optional(),
});
type RegistrationFormValues = z.infer<typeof registrationSchema>;

export default function GstRegistrationsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingRegistration, setEditingRegistration] = useState<GstRegistration | null>(null);
    const queryClient = useQueryClient();

    // THE LIST'S VIEW IS ITS URL: sort, page and page size, sorted and paged
    // on the SERVER over every registration.
    const { params, setParams, setPage } = useListParams<GstRegistrationListParams>(GST_REGISTRATION_LIST_SPEC);
    const filters = useMemo(() => gstRegistrationServerFilters(params), [params]);
    const { data, isLoading } = useQuery({
        queryKey: gstRegistrationsQueryKey(filters),
        queryFn: () => listGstRegistrations(filters),
        placeholderData: (previous) => previous,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['compliance', 'gst-registrations'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RegistrationFormValues>({
        resolver: zodResolver(registrationSchema),
        defaultValues: { gstin: '', state_code: '', state_name: '', is_primary: false },
    });

    const mutation = useMutation({
        mutationFn: createGstRegistration,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<RegistrationFormValues>({ resolver: zodResolver(registrationSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & RegistrationFormValues) => updateGstRegistration(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingRegistration(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update registration', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const activeMutation = useMutation({
        mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => updateGstRegistration(id, { is_active }),
        onSuccess: invalidate,
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>GST Registrations</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Registration</Button>
            </Space>

            <Table<GstRegistration>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries every registration; clearing one returns to
                // the primary-first order.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, GST_REGISTRATION_SORT_FIELDS, GST_REGISTRATION_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'registrations')}
                columns={[
                    {
                        title: 'GSTIN',
                        dataIndex: 'gstin',
                        key: 'gstin',
                        sorter: true,
                        sortOrder: columnSortOrder('gstin', params.sort, GST_REGISTRATION_DEFAULT_SORT),
                    },
                    {
                        title: 'State',
                        dataIndex: 'state_name',
                        key: 'state_name',
                        sorter: true,
                        sortOrder: columnSortOrder('state_name', params.sort, GST_REGISTRATION_DEFAULT_SORT),
                    },
                    {
                        title: 'State Code',
                        dataIndex: 'state_code',
                        key: 'state_code',
                        sorter: true,
                        sortOrder: columnSortOrder('state_code', params.sort, GST_REGISTRATION_DEFAULT_SORT),
                    },
                    {
                        title: 'Primary',
                        dataIndex: 'is_primary',
                        render: (primary: boolean) => primary && <Tag color="blue">Primary</Tag>,
                    },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        key: 'is_active',
                        sorter: true,
                        sortOrder: columnSortOrder('is_active', params.sort, GST_REGISTRATION_DEFAULT_SORT),
                        render: (active: boolean, row) => (
                            <Switch
                                checked={active}
                                size="small"
                                loading={activeMutation.isPending}
                                onChange={(checked) => activeMutation.mutate({ id: row.id, is_active: checked })}
                            />
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button
                                size="small"
                                onClick={() => {
                                    setEditingRegistration(row);
                                    resetEdit({
                                        gstin: row.gstin,
                                        state_code: row.state_code,
                                        state_name: row.state_name,
                                        is_primary: row.is_primary,
                                    });
                                }}
                            >
                                Edit
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New GST Registration"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="GSTIN" validateStatus={errors.gstin ? 'error' : ''} help={errors.gstin?.message}>
                        <Controller name="gstin" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="State Code"
                        validateStatus={errors.state_code ? 'error' : ''}
                        help={errors.state_code?.message}
                    >
                        <Controller name="state_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="State Name"
                        validateStatus={errors.state_name ? 'error' : ''}
                        help={errors.state_name?.message}
                    >
                        <Controller name="state_name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Primary Registration">
                        <Controller
                            name="is_primary"
                            control={control}
                            render={({ field: { value, onChange } }) => (
                                <Switch checked={value} onChange={onChange} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingRegistration?.gstin}"`}
                open={editingRegistration !== null}
                onCancel={() => setEditingRegistration(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingRegistration) editMutation.mutate({ id: editingRegistration.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="GSTIN" validateStatus={editErrors.gstin ? 'error' : ''} help={editErrors.gstin?.message}>
                        <Controller name="gstin" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="State Code"
                        validateStatus={editErrors.state_code ? 'error' : ''}
                        help={editErrors.state_code?.message}
                    >
                        <Controller name="state_code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="State Name"
                        validateStatus={editErrors.state_name ? 'error' : ''}
                        help={editErrors.state_name?.message}
                    >
                        <Controller name="state_name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Primary Registration">
                        <Controller
                            name="is_primary"
                            control={editControl}
                            render={({ field: { value, onChange } }) => (
                                <Switch checked={value} onChange={onChange} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
