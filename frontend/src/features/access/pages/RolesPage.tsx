import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Checkbox, Form, Input, Modal, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createRole, deleteRole, listPermissionCatalog, listRoles, updateRole } from '@/features/access/api';
import type { PermissionCatalogEntry, Role } from '@/features/access/types';

const roleSchema = z.object({
    name: z.string().min(1, 'Name is required').max(255),
    permissions: z.array(z.string()),
});
type RoleFormValues = z.infer<typeof roleSchema>;

interface PermissionGridProps {
    catalog: PermissionCatalogEntry[];
    value: string[];
    onChange: (next: string[]) => void;
}

function PermissionGrid({ catalog, value, onChange }: PermissionGridProps) {
    const toggle = (name: string, checked: boolean) => {
        onChange(checked ? [...value, name] : value.filter((p) => p !== name));
    };

    return (
        <Table
            size="small"
            pagination={false}
            rowKey="module"
            dataSource={catalog}
            columns={[
                { title: 'Feature', dataIndex: 'label' },
                {
                    title: 'View',
                    width: 80,
                    align: 'center' as const,
                    render: (_, entry) => {
                        const permission = entry.permissions.find((p) => p.name.endsWith('.view'));
                        return permission ? (
                            <Checkbox
                                checked={value.includes(permission.name)}
                                onChange={(e) => toggle(permission.name, e.target.checked)}
                            />
                        ) : null;
                    },
                },
                {
                    title: 'Manage',
                    width: 80,
                    align: 'center' as const,
                    render: (_, entry) => {
                        const permission = entry.permissions.find((p) => p.name.endsWith('.manage'));
                        return permission ? (
                            <Checkbox
                                checked={value.includes(permission.name)}
                                onChange={(e) => toggle(permission.name, e.target.checked)}
                            />
                        ) : null;
                    },
                },
            ]}
        />
    );
}

function summarizePermissions(permissions: string[], catalog: PermissionCatalogEntry[]): string[] {
    return catalog
        .filter((entry) => entry.permissions.some((p) => permissions.includes(p.name)))
        .map((entry) => {
            const manage = permissions.includes(`${entry.module}.manage`);
            return `${entry.label} (${manage ? 'Manage' : 'View'})`;
        });
}

export default function RolesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingRole, setEditingRole] = useState<Role | null>(null);
    const queryClient = useQueryClient();

    const { data: roles, isLoading } = useQuery({ queryKey: ['access', 'roles'], queryFn: listRoles });
    const { data: catalog } = useQuery({ queryKey: ['access', 'permissions'], queryFn: listPermissionCatalog });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['access', 'roles'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RoleFormValues>({
        resolver: zodResolver(roleSchema),
        defaultValues: { name: '', permissions: [] },
    });

    const createMutation = useMutation({
        mutationFn: createRole,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ name: '', permissions: [] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create role', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<RoleFormValues>({ resolver: zodResolver(roleSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & RoleFormValues) => updateRole(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingRole(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update role', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const deleteMutation = useMutation({
        mutationFn: deleteRole,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not delete role', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Roles</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Role</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                A role grants access per feature — View allows reading a module's data, Manage additionally
                allows creating and editing it. A role with no boxes checked for a feature has no access to it
                at all.
            </Typography.Paragraph>

            <Table<Role>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={roles}
                pagination={false}
                columns={[
                    { title: 'Name', dataIndex: 'name' },
                    {
                        title: 'Access',
                        render: (_, row) => (
                            <Space wrap>
                                {catalog && summarizePermissions(row.permissions, catalog).length > 0
                                    ? summarizePermissions(row.permissions, catalog).map((s) => <Tag key={s}>{s}</Tag>)
                                    : '—'}
                            </Space>
                        ),
                    },
                    { title: 'Users', dataIndex: 'users_count' },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setEditingRole(row);
                                        resetEdit({ name: row.name, permissions: row.permissions });
                                    }}
                                >
                                    Edit
                                </Button>
                                <Button
                                    size="small"
                                    danger
                                    loading={deleteMutation.isPending}
                                    onClick={() => {
                                        Modal.confirm({
                                            title: `Delete role "${row.name}"?`,
                                            content: 'This cannot be undone. Roles assigned to users cannot be deleted.',
                                            okButtonProps: { danger: true },
                                            onOk: () => deleteMutation.mutate(row.id),
                                        });
                                    }}
                                >
                                    Delete
                                </Button>
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Role"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={560}
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Feature Access">
                        <Controller
                            name="permissions"
                            control={control}
                            render={({ field }) => (
                                <PermissionGrid catalog={catalog ?? []} value={field.value} onChange={field.onChange} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingRole?.name}"`}
                open={editingRole !== null}
                onCancel={() => setEditingRole(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingRole) editMutation.mutate({ id: editingRole.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
                width={560}
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Feature Access">
                        <Controller
                            name="permissions"
                            control={editControl}
                            render={({ field }) => (
                                <PermissionGrid catalog={catalog ?? []} value={field.value} onChange={field.onChange} />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
