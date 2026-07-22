import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createMold, listMolds, updateMold } from '@/features/production/api';
import type { Mold, MoldStatus } from '@/features/production/types';

const moldSchema = z.object({
    code: z.string().min(1, 'Code is required').max(64),
    name: z.string().min(1, 'Name is required').max(255),
    cavity_count: z.number().min(1).optional(),
    status: z.enum(['active', 'under_repair', 'retired']).optional(),
    notes: z.string().optional(),
});
type MoldFormValues = z.infer<typeof moldSchema>;

const statusOptions: { value: MoldStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'under_repair', label: 'Under Repair' },
    { value: 'retired', label: 'Retired' },
];
const statusColor: Record<MoldStatus, string> = { active: 'success', under_repair: 'warning', retired: 'default' };

export default function MoldsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingMold, setEditingMold] = useState<Mold | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'molds'], queryFn: listMolds });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'molds'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<MoldFormValues>({
        resolver: zodResolver(moldSchema),
        defaultValues: { code: '', name: '' },
    });

    const mutation = useMutation({
        mutationFn: createMold,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create mold', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<MoldFormValues>({ resolver: zodResolver(moldSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & MoldFormValues) => updateMold(id, payload),
        onSuccess: () => {
            invalidate();
            setEditingMold(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update mold', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Molds</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Mold</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                The physical tools mounted into a machine — a mold change on the Shift Floor page picks one of these
                plus the item being produced separately, since one mold is often reused across an item&apos;s colour
                variants. A mold under repair won&apos;t show up as selectable there.
            </Typography.Paragraph>

            <Table<Mold>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    { title: 'Cavities', dataIndex: 'cavity_count', render: (v: number | null) => v ?? '—' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: MoldStatus) => <Tag color={statusColor[status]}>{statusOptions.find((o) => o.value === status)?.label}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button
                                size="small"
                                onClick={() => {
                                    setEditingMold(row);
                                    resetEdit({
                                        code: row.code,
                                        name: row.name,
                                        cavity_count: row.cavity_count ?? undefined,
                                        status: row.status,
                                        notes: row.notes ?? '',
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
                title="New Mold"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} placeholder="e.g. MLD-500PET-01" />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Cavity Count">
                        <Controller
                            name="cavity_count"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={1} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editingMold?.name}"`}
                open={editingMold !== null}
                onCancel={() => setEditingMold(null)}
                onOk={handleEditSubmit((values) => {
                    if (editingMold) editMutation.mutate({ id: editingMold.id, ...values });
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
                    <Form.Item label="Cavity Count">
                        <Controller
                            name="cavity_count"
                            control={editControl}
                            render={({ field }) => <InputNumber {...field} min={1} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Status">
                        <Controller
                            name="status"
                            control={editControl}
                            render={({ field }) => <Select {...field} options={statusOptions} />}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={editControl} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
