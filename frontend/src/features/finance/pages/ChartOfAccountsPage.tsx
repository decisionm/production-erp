import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createGLAccount, listGLAccounts, updateGLAccount } from '@/features/finance/api';
import type { GLAccount, GLAccountType } from '@/features/finance/types';

const accountSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
    type: z.enum(['asset', 'liability', 'equity', 'revenue', 'expense'], { error: 'Type is required' }),
});
type AccountFormValues = z.infer<typeof accountSchema>;

const typeColor: Record<GLAccountType, string> = {
    asset: 'blue',
    liability: 'red',
    equity: 'purple',
    revenue: 'green',
    expense: 'gold',
};

const typeOptions = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    { value: 'revenue', label: 'Revenue' },
    { value: 'expense', label: 'Expense' },
];

export default function ChartOfAccountsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingAccount, setEditingAccount] = useState<GLAccount | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['finance', 'gl-accounts'], queryFn: listGLAccounts });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['finance', 'gl-accounts'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<AccountFormValues>({
        resolver: zodResolver(accountSchema),
        defaultValues: { code: '', name: '', type: undefined },
    });

    const mutation = useMutation({
        mutationFn: createGLAccount,
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
    } = useForm<AccountFormValues>({ resolver: zodResolver(accountSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & AccountFormValues) => updateGLAccount(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingAccount(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update account', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const activeMutation = useMutation({
        mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => updateGLAccount(id, { is_active }),
        onSuccess: invalidate,
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Chart of Accounts</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Account</Button>
            </Space>

            <Table<GLAccount>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    {
                        title: 'Type',
                        dataIndex: 'type',
                        render: (type: GLAccountType) => <Tag color={typeColor[type]}>{type}</Tag>,
                    },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
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
                                    setEditingAccount(row);
                                    resetEdit({ code: row.code, name: row.name, type: row.type });
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
                title="New GL Account"
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
                    <Form.Item label="Type" validateStatus={errors.type ? 'error' : ''} help={errors.type?.message}>
                        <Controller
                            name="type"
                            control={control}
                            render={({ field }) => <Select {...field} options={typeOptions} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingAccount?.name}"`}
                open={editingAccount !== null}
                onCancel={() => setEditingAccount(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingAccount) editMutation.mutate({ id: editingAccount.id, ...values });
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
                    <Form.Item label="Type" validateStatus={editErrors.type ? 'error' : ''} help={editErrors.type?.message}>
                        <Controller
                            name="type"
                            control={editControl}
                            render={({ field }) => <Select {...field} options={typeOptions} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
