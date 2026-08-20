import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createCustomer, listCustomers, updateCustomer } from '@/features/sales/api';
import type { Customer } from '@/features/sales/types';

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

    // Server-side paging. The list is about to hold the factory's real
    // customer master (hundreds of ledger-derived rows), not a handful of
    // demo ones, so the page number is part of the query key.
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);

    const { data, isLoading } = useQuery({
        queryKey: ['sales', 'customers', page, perPage],
        queryFn: () => listCustomers(page, perPage),
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
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={{
                    current: page,
                    pageSize: perPage,
                    // The server's count, not the page's length — otherwise
                    // the pager would claim the list ends at the first screen.
                    total: data?.meta?.total ?? data?.data?.length ?? 0,
                    showSizeChanger: true,
                    pageSizeOptions: [20, 50, 100, 200],
                    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} customers`,
                    onChange: (nextPage, nextSize) => {
                        setPage(nextPage);
                        setPerPage(nextSize);
                    },
                }}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Email', dataIndex: 'email' },
                    { title: 'Phone', dataIndex: 'phone' },
                    { title: 'GSTIN', dataIndex: 'gstin' },
                    { title: 'State', dataIndex: 'state_code' },
                    {
                        title: 'Status',
                        dataIndex: 'is_active',
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
