import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { ConfigurationActionsCell, ConfigurationStatusTag } from '@/components/configuration';
import { createScrapReason, listScrapReasons, updateScrapReason } from '@/features/production/api';
import type { ScrapReason } from '@/features/production/types';

const scrapReasonSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
});
type ScrapReasonFormValues = z.infer<typeof scrapReasonSchema>;

/**
 * `embedded` is set when this renders as the Scrap Reasons TAB of Production
 * Configuration, which is now the only way a user reaches it. All it
 * suppresses is the page-level heading — the workspace already sits under a
 * tab labelled "Scrap Reasons", and repeating it as an H3 two lines below
 * reads as two screens stacked on top of each other.
 *
 * Nothing else is conditional on it: the same queries, the same endpoints,
 * the same lifecycle actions and the same (absent) client-side permission
 * gate in both modes. A second code path is how "permissions unchanged"
 * quietly stops being true, so there isn't one. The default keeps the
 * standalone signature working for anything that still imports this page.
 */
export default function ScrapReasonsPage({ embedded = false }: { embedded?: boolean }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<ScrapReason | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'scrap-reasons'], queryFn: listScrapReasons });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ScrapReasonFormValues>({
        resolver: zodResolver(scrapReasonSchema),
    });

    const mutation = useMutation({
        mutationFn: createScrapReason,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'scrap-reasons'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create scrap reason', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
        formState: { errors: editErrors },
    } = useForm<ScrapReasonFormValues>({ resolver: zodResolver(scrapReasonSchema) });

    /**
     * Edit — the act this master was missing entirely. It carries the code and
     * the name and nothing else: withdrawing a reason is Archive, which counts
     * what already uses it and takes a reason, and the FormRequest refuses the
     * flag here in any case.
     */
    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & ScrapReasonFormValues) => updateScrapReason(id, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'scrap-reasons'] });
            setEditing(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update scrap reason', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: embedded ? 'flex-end' : 'space-between', width: '100%' }}>
                {!embedded && <Typography.Title level={3} style={{ margin: 0 }}>Scrap Reasons</Typography.Title>}
                <Button type="primary" onClick={() => setModalOpen(true)}>New Scrap Reason</Button>
            </Space>

            <Table<ScrapReason>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    {
                        // One vocabulary: Active / Retired, the same two words
                        // on every master. The old control was a permanently
                        // disabled Switch, which said the state was a setting
                        // and then refused to let anyone change it.
                        title: 'Status',
                        dataIndex: 'is_active',
                        render: (_: boolean, row) => <ConfigurationStatusTag entity="scrap-reason" row={row} />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => {
                            const edit = () => {
                                setEditing(row);
                                resetEdit({ code: row.code, name: row.name });
                            };
                            return (
                                <ConfigurationActionsCell
                                    entity="scrap-reason"
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
                title="New Scrap Reason"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} placeholder="e.g. SHORT-SHOT" />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
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
                    <Form.Item label="Code" validateStatus={editErrors.code ? 'error' : ''} help={editErrors.code?.message}>
                        <Controller name="code" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={editErrors.name ? 'error' : ''} help={editErrors.name?.message}>
                        <Controller name="name" control={editControl} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
