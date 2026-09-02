import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listAllEmployees } from '@/features/hrms/api';
import { closeCapa, createCapa, listCapas, listNonConformanceReports, startCapa, updateCapa } from '@/features/quality/api';
import { CAPA_DEFAULT_SORT, CAPA_LIST, CAPA_SORT_FIELDS, type SortedListParams } from '@/features/quality/qualityLists';
import type { Capa, CapaStatus } from '@/features/quality/types';
import { compactParams } from '@/lib/listParams';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

/** The register's URL keys beyond page / per_page (CAPA_LIST, qualityLists.ts). Module-level: useListParams memoises on it. */
const CAPA_LIST_SPEC = CAPA_LIST.spec;

const createSchema = z.object({
    non_conformance_report_id: z.number().optional(),
    title: z.string().min(1, 'Title is required').max(255),
    problem_statement: z.string().min(1, 'Problem statement is required'),
    owner: z.number().optional(),
    due_date: z.string().optional(),
});
type CreateFormValues = z.infer<typeof createSchema>;

const editSchema = z.object({
    root_cause: z.string().optional(),
    corrective_action: z.string().optional(),
    preventive_action: z.string().optional(),
});
type EditFormValues = z.infer<typeof editSchema>;

const statusColor: Record<CapaStatus, string> = {
    open: 'default',
    in_progress: 'blue',
    closed: 'green',
};

export default function CapasPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Capa | null>(null);
    const [closing, setClosing] = useState<Capa | null>(null);
    const [verifiedEffective, setVerifiedEffective] = useState(true);
    const queryClient = useQueryClient();

    // THE URL IS THE LIST'S STATE (sort, page, page size) and the SERVER
    // cuts the page: this table used to draw the server's first twenty rows
    // under pagination={false}, with nothing on screen to say a twenty-first
    // existed.
    const { params, setParams, setPage } = useListParams<SortedListParams>(CAPA_LIST_SPEC);
    const request = compactParams(params);
    const { data, isLoading } = useQuery({
        queryKey: ['quality', 'capas', request],
        queryFn: () => listCapas(params),
        placeholderData: (previous) => previous,
    });
    const { data: ncrs } = useQuery({ queryKey: ['quality', 'ncrs'], queryFn: () => listNonConformanceReports() });
    const { data: employees } = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });

    const ncrOptions = ncrs?.data.map((n) => ({ value: n.id, label: `NCR #${n.id} — ${n.description.slice(0, 40)}` })) ?? [];
    const employeeOptions = employees?.data.map((e) => ({ value: e.id, label: `${e.employee_code} — ${e.name}` })) ?? [];

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['quality', 'capas'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CreateFormValues>({
        resolver: zodResolver(createSchema),
    });

    const createMutation = useMutation({
        mutationFn: createCapa,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create CAPA', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const startMutation = useMutation({
        mutationFn: startCapa,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not start CAPA', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: editControl,
        handleSubmit: handleEditSubmit,
        reset: resetEdit,
    } = useForm<EditFormValues>({ resolver: zodResolver(editSchema) });

    const editMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & EditFormValues) => updateCapa(id, payload),
        onSuccess: () => {
            invalidate();
            setEditing(null);
            resetEdit();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not update CAPA', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const closeMutation = useMutation({
        mutationFn: ({ id, verified }: { id: number; verified: boolean }) => closeCapa(id, verified),
        onSuccess: () => {
            invalidate();
            setClosing(null);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not close CAPA', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>CAPA</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New CAPA</Button>
            </Space>

            <Table<Capa>
                scroll={{ x: 'max-content' }}
                sticky={TABLE_STICKY}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries the whole register.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, CAPA_SORT_FIELDS, CAPA_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'CAPAs')}
                columns={[
                    {
                        title: 'Title',
                        key: 'title',
                        dataIndex: 'title',
                        sorter: true,
                        sortOrder: columnSortOrder('title', params.sort, CAPA_DEFAULT_SORT),
                    },
                    {
                        title: 'Status',
                        key: 'status',
                        dataIndex: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, CAPA_DEFAULT_SORT),
                        render: (status: CapaStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    // Owner shows the employee's NAME (a relation), which the
                    // server has no column to sort on — so no sorter here.
                    { title: 'Owner', render: (_, row) => row.owner?.name },
                    {
                        // Nullable: an undated CAPA sorts last either way (server-side).
                        title: 'Due',
                        key: 'due_date',
                        dataIndex: 'due_date',
                        sorter: true,
                        sortOrder: columnSortOrder('due_date', params.sort, CAPA_DEFAULT_SORT),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                {row.status !== 'closed' && (
                                    <Button
                                        size="small"
                                        onClick={() => {
                                            setEditing(row);
                                            resetEdit({
                                                root_cause: row.root_cause ?? '',
                                                corrective_action: row.corrective_action ?? '',
                                                preventive_action: row.preventive_action ?? '',
                                            });
                                        }}
                                    >
                                        Edit Actions
                                    </Button>
                                )}
                                {row.status === 'open' && (
                                    <Button size="small" onClick={() => startMutation.mutate(row.id)} loading={startMutation.isPending}>
                                        Start
                                    </Button>
                                )}
                                {row.status === 'in_progress' && (
                                    <Button
                                        size="small"
                                        onClick={() => {
                                            setClosing(row);
                                            setVerifiedEffective(true);
                                        }}
                                    >
                                        Close
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New CAPA"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Linked NCR (optional)">
                        <Controller
                            name="non_conformance_report_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={ncrOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Title" validateStatus={errors.title ? 'error' : ''} help={errors.title?.message}>
                        <Controller name="title" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Problem Statement"
                        validateStatus={errors.problem_statement ? 'error' : ''}
                        help={errors.problem_statement?.message}
                    >
                        <Controller
                            name="problem_statement"
                            control={control}
                            render={({ field }) => <Input.TextArea {...field} rows={3} />}
                        />
                    </Form.Item>
                    <Form.Item label="Owner">
                        <Controller
                            name="owner"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={employeeOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Due Date">
                        <Controller
                            name="due_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Edit CAPA #${editing?.id} — Root Cause & Actions`}
                open={editing !== null}
                onCancel={() => setEditing(null)}
                onOk={handleEditSubmit((values) => {
                    if (editing) editMutation.mutate({ id: editing.id, ...values });
                })}
                confirmLoading={editMutation.isPending}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    <Form.Item label="Root Cause">
                        <Controller
                            name="root_cause"
                            control={editControl}
                            render={({ field }) => <Input.TextArea {...field} rows={3} />}
                        />
                    </Form.Item>
                    <Form.Item label="Corrective Action">
                        <Controller
                            name="corrective_action"
                            control={editControl}
                            render={({ field }) => <Input.TextArea {...field} rows={3} />}
                        />
                    </Form.Item>
                    <Form.Item label="Preventive Action">
                        <Controller
                            name="preventive_action"
                            control={editControl}
                            render={({ field }) => <Input.TextArea {...field} rows={3} />}
                        />
                    </Form.Item>
                    <Typography.Text type="secondary">
                        Root cause, corrective action, and preventive action must all be filled in before this CAPA
                        can be closed.
                    </Typography.Text>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`Close CAPA #${closing?.id}`}
                open={closing !== null}
                onCancel={() => setClosing(null)}
                onOk={() => {
                    if (closing) closeMutation.mutate({ id: closing.id, verified: verifiedEffective });
                }}
                confirmLoading={closeMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Verified Effective">
                        <Switch checked={verifiedEffective} onChange={setVerifiedEffective} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
