import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createShift, listShifts, updateShift } from '@/features/production/api';
import type { Shift } from '@/features/production/types';

const shiftSchema = z.object({
    name: z.string().min(1, 'Name is required').max(64),
    start_time: z.string().min(1, 'Start time is required'),
    end_time: z.string().min(1, 'End time is required'),
});
type ShiftFormValues = z.infer<typeof shiftSchema>;

/**
 * `embedded` is set when this renders as the Shifts TAB of Production
 * Configuration, which is now the only way a user reaches it. All it
 * suppresses is the page-level heading — the tab is already labelled
 * "Shifts". The paragraph below it STAYS: it explains why a retired shift is
 * still listed, which is content, not a duplicated title.
 *
 * Nothing else is conditional on it: the same queries, the same endpoints,
 * the same lifecycle actions and the same (absent) client-side permission
 * gate in both modes. The default keeps the standalone signature working for
 * anything that still imports this page.
 *
 * NOTE for anyone grepping: the UI route `/production/shifts` is retired and
 * now redirects here, but the API path `production/shifts` (api.ts,
 * entities.ts) is a DIFFERENT thing that did not move and must not be
 * touched — the two happen to share a string.
 */
export default function ShiftsPage({ embedded = false }: { embedded?: boolean }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Shift | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'shifts'], queryFn: () => listShifts() });

    // Running shifts first, then retired, each still in start-time order as the
    // API returns them.
    const rows = [
        ...(data?.data.filter((shift) => shift.is_active) ?? []),
        ...(data?.data.filter((shift) => !shift.is_active) ?? []),
    ];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ShiftFormValues>({
        resolver: zodResolver(shiftSchema),
    });

    const mutation = useMutation({
        mutationFn: createShift,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'shifts'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create shift', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<ShiftFormValues>({ resolver: zodResolver(shiftSchema) });

    /**
     * Edit — name and the two clock times, nothing else. Retiring a shift is
     * Archive: past entries point at the record, so the act that withdraws it
     * has to be the one that counts them first.
     */
    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & ShiftFormValues) => updateShift(id, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'shifts'] });
            setEditing(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update shift', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: embedded ? 'flex-end' : 'space-between', width: '100%' }}>
                {!embedded && <Typography.Title level={3} style={{ margin: 0 }}>Shifts</Typography.Title>}
                <Button type="primary" onClick={() => setModalOpen(true)}>New Shift</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                Which shift a production entry was made in. Retired shifts stay listed because
                past entries point at them, but only the running ones are offered on the floor.
            </Typography.Paragraph>

            <Table<Shift>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                // Running shifts first. A retired row sorted in between two live
                // ones reads as a fourth shift — which is exactly the complaint
                // that got the duplicates merged: "THERE IS NOT NIGHT, SHIFT A TO C".
                dataSource={rows}
                pagination={false}
                columns={[
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        render: (name: string, shift) =>
                            shift.is_active ? name : <Typography.Text type="secondary" delete>{name}</Typography.Text>,
                    },
                    { title: 'Start Time', dataIndex: 'start_time' },
                    { title: 'End Time', dataIndex: 'end_time' },
                    {
                        // The tag says the word. A switch that is merely off does not
                        // tell a supervisor whether the shift is gone or just paused —
                        // and the two halves of that answer used to be rendered by two
                        // different controls on the one column. Now it is the one tag
                        // every master uses.
                        title: 'Status',
                        dataIndex: 'is_active',
                        render: (_: boolean, row) => <ConfigurationStatusTag entity="shift" row={row} />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditing(row);
                                resetEdit({ name: row.name, start_time: row.start_time, end_time: row.end_time });
                            };
                            return (
                                <ConfigurationActionsCell
                                    entity="shift"
                                    id={row.id}
                                    can={row.can}
                                    recordName={row.name}
                                    onEdit={edit}
                                />
                            );
                        },
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Shift"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller
                            name="name"
                            control={control}
                            render={({ field }) => <Input {...field} placeholder="e.g. Morning" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Start Time"
                        validateStatus={errors.start_time ? 'error' : ''}
                        help={errors.start_time?.message}
                    >
                        <Controller
                            name="start_time"
                            control={control}
                            render={({ field }) => (
                                <TimePicker
                                    style={{ width: '100%' }}
                                    format="HH:mm"
                                    value={field.value ? dayjs(field.value, 'HH:mm') : undefined}
                                    onChange={(value) => field.onChange(value ? value.format('HH:mm') : undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="End Time"
                        validateStatus={errors.end_time ? 'error' : ''}
                        help={errors.end_time?.message}
                    >
                        <Controller
                            name="end_time"
                            control={control}
                            render={({ field }) => (
                                <TimePicker
                                    style={{ width: '100%' }}
                                    format="HH:mm"
                                    value={field.value ? dayjs(field.value, 'HH:mm') : undefined}
                                    onChange={(value) => field.onChange(value ? value.format('HH:mm') : undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit "${editing?.name ?? ''}"`}
                open={editing !== null}
                onCancel={() => setEditing(null)}
                onOk={handleEditSubmit((values) => {
                    if (editing) editMutation.mutate({ id: editing.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Start Time"
                        validateStatus={editErrors.start_time ? 'error' : ''}
                        help={editErrors.start_time?.message}
                    >
                        <Controller
                            name="start_time"
                            control={editControl}
                            render={({ field }) => (
                                <TimePicker
                                    style={{ width: '100%' }}
                                    format="HH:mm"
                                    value={field.value ? dayjs(field.value, 'HH:mm') : undefined}
                                    onChange={(value) => field.onChange(value ? value.format('HH:mm') : undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="End Time"
                        validateStatus={editErrors.end_time ? 'error' : ''}
                        help={editErrors.end_time?.message}
                    >
                        <Controller
                            name="end_time"
                            control={editControl}
                            render={({ field }) => (
                                <TimePicker
                                    style={{ width: '100%' }}
                                    format="HH:mm"
                                    value={field.value ? dayjs(field.value, 'HH:mm') : undefined}
                                    onChange={(value) => field.onChange(value ? value.format('HH:mm') : undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
