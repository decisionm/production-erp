import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
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

/**
 * The statuses the EDIT FORM may set.
 *
 * `under_repair` belongs here: it is an operational fact about the tool, and
 * it is the case `ActiveFlag` keeps deliberately separate — a mould under
 * repair is neither active nor retired, so neither Archive nor Reactivate
 * expresses it.
 *
 * `retired` does NOT belong here, and stays listed only so a mould that is
 * already retired shows what it is instead of an empty box. Retiring is
 * Archive on the row: that path counts what uses the mould first, takes a
 * reason, and is reversible. A status dropdown writing `retired` straight
 * onto the record would be the same act with none of those.
 */
const statusOptions: { value: MoldStatus; label: string; disabled?: boolean }[] = [
    { value: 'active', label: 'Active' },
    { value: 'under_repair', label: 'Under Repair' },
    { value: 'retired', label: 'Retired', disabled: true },
];

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
                        // Active / Retired in the product's two words, and
                        // "Under repair" in the factory's own — the middle case
                        // the mechanism keeps reachable in both directions.
                        render: (_: MoldStatus, row) => <ConfigurationStatusTag entity="mold" row={row} />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditingMold(row);
                                resetEdit({
                                    code: row.code,
                                    name: row.name,
                                    cavity_count: row.cavity_count ?? undefined,
                                    status: row.status,
                                    notes: row.notes ?? '',
                                });
                            };
                            return (
                                <ConfigurationActionsCell
                                    entity="mold"
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
                    <Form.Item
                        label="Status"
                        extra="Retiring a mould is done with Archive on the row — that path counts what already uses it, takes a reason, and can be undone."
                    >
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
