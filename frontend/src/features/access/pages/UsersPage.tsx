import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import {
    createUser,
    listRoles,
    listUsers,
    resetUserPassword,
    updateUser,
} from '@/features/access/api';
import { useAuthStore } from '@/features/auth/store';
import type { User } from '@/features/auth/types';

const createSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().email('Enter a valid email'),
    password: z.string().min(8, 'Password must be at least 8 characters'),
    roles: z.array(z.number()).optional(),
});
type CreateFormValues = z.infer<typeof createSchema>;

const editSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    email: z.string().email('Enter a valid email'),
    roles: z.array(z.number()).optional(),
});
type EditFormValues = z.infer<typeof editSchema>;

const resetPasswordSchema = z.object({
    password: z.string().min(8, 'Password must be at least 8 characters'),
});
type ResetPasswordFormValues = z.infer<typeof resetPasswordSchema>;

export default function UsersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState<User | null>(null);
    const [resettingUser, setResettingUser] = useState<User | null>(null);
    const currentUser = useAuthStore((state) => state.user);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['access', 'users'], queryFn: listUsers });
    const { data: roles } = useQuery({ queryKey: ['access', 'roles'], queryFn: listRoles });
    const roleOptions = roles?.map((r) => ({ value: r.id, label: r.name })) ?? [];

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['access', 'users'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CreateFormValues>({
        resolver: zodResolver(createSchema),
        defaultValues: { name: '', email: '', password: '', roles: [] },
    });

    const createMutation = useMutation({
        mutationFn: createUser,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create user', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<EditFormValues>({ resolver: zodResolver(editSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & EditFormValues) => updateUser(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingUser(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update user', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const activeMutation = useMutation({
        mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => updateUser(id, { is_active }),
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not update user', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: resetControl,
        handleSubmit: handleResetSubmit,
        reset: resetResetForm,
        formState: { errors: resetErrors },
    } = useForm<ResetPasswordFormValues>({ resolver: zodResolver(resetPasswordSchema), defaultValues: { password: '' } });

    const resetPasswordMutation = useMutation({
        mutationFn: ({ id, password }: { id: number; password: string }) => resetUserPassword(id, password),
        onSuccess: () => {
            setResettingUser(null);
            resetResetForm({ password: '' });
            Modal.success({ title: 'Password reset', content: 'The user can now sign in with the new password.' });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not reset password', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Users</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New User</Button>
            </Space>

            <Table<User>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Email', dataIndex: 'email' },
                    {
                        title: 'Roles',
                        render: (_, row) => (
                            <Space wrap>
                                {row.roles?.length ? row.roles.map((r) => <Tag key={r.id}>{r.name}</Tag>) : '—'}
                            </Space>
                        ),
                    },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean, row) => (
                            <Switch
                                checked={active}
                                size="small"
                                loading={activeMutation.isPending}
                                disabled={row.id === currentUser?.id}
                                onChange={(checked) => activeMutation.mutate({ id: row.id, is_active: checked })}
                            />
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setEditingUser(row);
                                        resetEdit({
                                            name: row.name,
                                            email: row.email,
                                            roles: row.roles?.map((r) => r.id) ?? [],
                                        });
                                    }}
                                >
                                    Edit
                                </Button>
                                <Button size="small" onClick={() => setResettingUser(row)}>
                                    Reset Password
                                </Button>
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                title="New User"
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
                    <Form.Item label="Email" validateStatus={errors.email ? 'error' : ''} help={errors.email?.message}>
                        <Controller name="email" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Password"
                        validateStatus={errors.password ? 'error' : ''}
                        help={errors.password?.message}
                    >
                        <Controller
                            name="password"
                            control={control}
                            render={({ field }) => <Input.Password {...field} />}
                        />
                    </Form.Item>
                    <Form.Item label="Roles">
                        <Controller
                            name="roles"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} mode="multiple" options={roleOptions} showSearch optionFilterProp="label" placeholder="Assign roles" />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title={`Edit "${editingUser?.name}"`}
                open={editingUser !== null}
                onCancel={() => setEditingUser(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingUser) editMutation.mutate({ id: editingUser.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Email" validateStatus={editErrors.email ? 'error' : ''} help={editErrors.email?.message}>
                        <Controller name="email" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Roles">
                        <Controller
                            name="roles"
                            control={editControl}
                            render={({ field }) => (
                                <Select {...field} mode="multiple" options={roleOptions} showSearch optionFilterProp="label" placeholder="Assign roles" />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title={`Reset Password for "${resettingUser?.name}"`}
                open={resettingUser !== null}
                onCancel={() => setResettingUser(null)}
                onOk={handleResetSubmit((values) => {
                    if (resettingUser) resetPasswordMutation.mutate({ id: resettingUser.id, password: values.password });
                })}
                confirmLoading={resetPasswordMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="New Password"
                        validateStatus={resetErrors.password ? 'error' : ''}
                        help={resetErrors.password?.message}
                    >
                        <Controller
                            name="password"
                            control={resetControl}
                            render={({ field }) => <Input.Password {...field} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
