import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createCustomer, listCustomers, updateCustomer } from '@/features/sales/api';
import {
    CUSTOMER_DEFAULT_SORT,
    CUSTOMER_LIST_SPEC,
    CUSTOMER_SORT_FIELDS,
    type CustomerListParams,
    customerListRequest,
} from '@/features/sales/customerList';
import type { Customer } from '@/features/sales/types';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const customerSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().email('Enter a valid email').optional().or(z.literal('')),
    phone: z.string().optional(),
    gstin: z
        .string()
        .regex(/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/, 'Enter a valid 15-character GSTIN')
        .optional()
        .or(z.literal('')),
    state_code: z.string().regex(/^[0-9]{2}$/, 'Enter a 2-digit GST state code').optional().or(z.literal('')),
});

type CustomerFormValues = z.infer<typeof customerSchema>;

export default function CustomersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingCustomer, setEditingCustomer] = useState<Customer | null>(null);
    const queryClient = useQueryClient();

    // Server-side paging AND ordering, both in the URL (useListParams,
    // customerList.ts). The list holds the factory's real customer master
    // (hundreds of ledger-derived rows), not a handful of demo ones, so the
    // page and the sort are part of the query key and a sorter never orders
    // the loaded page.
    const { params, setParams, setPage } = useListParams<CustomerListParams>(CUSTOMER_LIST_SPEC);
    const request = customerListRequest(params);

    const { data, isLoading } = useQuery({
        queryKey: ['sales', 'customers', request],
        queryFn: () => listCustomers(request.page, request.per_page, request.sort),
        placeholderData: (previous) => previous,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales', 'customers'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CustomerFormValues>({
        resolver: zodResolver(customerSchema),
        defaultValues: { code: '', name: '', email: '', phone: '', gstin: '', state_code: '' },
    });

    const mutation = useMutation({
        mutationFn: createCustomer,
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
    } = useForm<CustomerFormValues>({ resolver: zodResolver(customerSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & CustomerFormValues) => updateCustomer(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingCustomer(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update customer', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });


    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Customers</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Customer</Button>
            </Space>

            <Table<Customer>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queries the
                // whole master from page 1.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, CUSTOMER_SORT_FIELDS, CUSTOMER_DEFAULT_SORT) });
                }}
                // The server's count, not the page's length — otherwise the
                // pager would claim the list ends at the first screen.
                pagination={serverPagination(data?.meta, setPage, 'customers')}
                columns={[
                    {
                        title: 'Code',
                        dataIndex: 'code',
                        key: 'code',
                        sorter: true,
                        sortOrder: columnSortOrder('code', params.sort, CUSTOMER_DEFAULT_SORT),
                    },
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        key: 'name',
                        sorter: true,
                        sortOrder: columnSortOrder('name', params.sort, CUSTOMER_DEFAULT_SORT),
                    },
                    { title: 'Email', dataIndex: 'email' },
                    { title: 'Phone', dataIndex: 'phone' },
                    { title: 'GSTIN', dataIndex: 'gstin' },
                    {
                        title: 'State',
                        dataIndex: 'state_code',
                        key: 'state_code',
                        sorter: true,
                        sortOrder: columnSortOrder('state_code', params.sort, CUSTOMER_DEFAULT_SORT),
                    },
                    {
                        /*
                         * WHICH TALLY LEDGER THIS CUSTOMER POSTS AS — read-only
                         * and deliberately not editable anywhere on this page.
                         * The columns are not fillable on the server: a posting
                         * identity is imported from Tally by
                         * `sales:import-customers-from-ledgers`, never typed,
                         * so an edit control here would be a box the API
                         * discards.
                         *
                         * "No Tally ledger" is said in full rather than left as
                         * a dash: an unlinked customer is a real, actionable
                         * state (run the import), and a blank cell reads as a
                         * column that has not loaded.
                         */
                        title: 'Tally ledger',
                        render: (_, row) =>
                            row.tally_ledger_name ? (
                                <Typography.Text>posts as {row.tally_ledger_name}</Typography.Text>
                            ) : (
                                <Typography.Text type="secondary">no Tally ledger</Typography.Text>
                            ),
                    },
                    {
                        title: 'Status',
                        dataIndex: 'is_active',
                        key: 'is_active',
                        sorter: true,
                        sortOrder: columnSortOrder('is_active', params.sort, CUSTOMER_DEFAULT_SORT),
                        render: (_: boolean, row) => <ConfigurationStatusTag entity="customer" row={row} />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditingCustomer(row);
                                resetEdit({
                                    code: row.code,
                                    name: row.name,
                                    email: row.email ?? '',
                                    phone: row.phone ?? '',
                                    gstin: row.gstin ?? '',
                                    state_code: row.state_code ?? '',
                                });
                            };

                            return (
                                <ConfigurationActionsCell
                                    entity="customer"
                                    id={row.id}
                                    can={row.can}
                                    recordName={`${row.code} — ${row.name}`}
                                    onEdit={edit}
                                />
                            );
                        },
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Customer"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Email" validateStatus={errors.email ? 'error' : ''} help={errors.email?.message}>
                        <Controller name="email" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Phone">
                        <Controller name="phone" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="GSTIN" validateStatus={errors.gstin ? 'error' : ''} help={errors.gstin?.message}>
                        <Controller name="gstin" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="GST State Code"
                        validateStatus={errors.state_code ? 'error' : ''}
                        help={errors.state_code?.message}
                    >
                        <Controller name="state_code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingCustomer?.name}"`}
                open={editingCustomer !== null}
                onCancel={() => setEditingCustomer(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingCustomer) editMutation.mutate({ id: editingCustomer.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={editErrors.code ? 'error' : ''} help={editErrors.code?.message}>
                        <Controller name="code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Email" validateStatus={editErrors.email ? 'error' : ''} help={editErrors.email?.message}>
                        <Controller name="email" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Phone">
                        <Controller name="phone" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="GSTIN" validateStatus={editErrors.gstin ? 'error' : ''} help={editErrors.gstin?.message}>
                        <Controller name="gstin" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="GST State Code"
                        validateStatus={editErrors.state_code ? 'error' : ''}
                        help={editErrors.state_code?.message}
                    >
                        <Controller name="state_code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
