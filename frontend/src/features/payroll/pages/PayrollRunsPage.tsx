import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Empty, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { createPayrollRun, listPayrollRuns, markPayrollRunPaid, processPayrollRun } from '@/features/payroll/api';
import {
    RUNS_DEFAULT_SORT,
    RUNS_LIST_SPEC,
    RUNS_SORT_FIELDS,
    RUN_STATUS_CHOICES,
    periodLabel,
    runsQueryKey,
    runsServerFilters,
} from '@/features/payroll/lists';
import type { PayrollRun, PayrollRunListFilters, PayrollRunStatus } from '@/features/payroll/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { narrowingKeys } from '@/lib/listParams';
import { TABLE_STICKY, noMatchLine, pageRangeLine, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const currentYear = new Date().getFullYear();

const runSchema = z.object({
    year: z.number().min(2000).max(2100),
    month: z.number().min(1).max(12),
});
type RunFormValues = z.infer<typeof runSchema>;

const statusColor: Record<PayrollRunStatus, string> = {
    draft: 'default',
    processed: 'blue',
    paid: 'green',
};

const statusOptions = RUN_STATUS_CHOICES.map((status) => ({
    value: status,
    label: status.charAt(0).toUpperCase() + status.slice(1),
}));

/**
 * THE PAYROLL RUNS LIST. The search, the status and the page live in the
 * URL (useListParams) and the SERVER does the narrowing over every run —
 * this table used to draw the server's first page with the pager off, so
 * the 21st run existed and nothing on screen said so. The run's actions
 * (Process, Mark Paid, View Payslips) and the New Payroll Run modal are
 * exactly as they were.
 */
export default function PayrollRunsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    const { params, setParams, setPage, reset } = useListParams<PayrollRunListFilters>(RUNS_LIST_SPEC);
    const filters = useMemo(() => runsServerFilters(params), [params]);
    const term = params.q;
    const filtersActive = narrowingKeys(params).length > 0;
    // What is typed; the URL (and the server) hear it on Enter or the button.
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => {
        setQDraft(params.q ?? '');
    }, [params.q]);

    const runsQuery = useQuery({
        queryKey: runsQueryKey(filters),
        queryFn: () => listPayrollRuns(filters),
        // Turning a page keeps the last page on screen until the next one
        // lands; a refetch that fails then has rows in front of it, which
        // is why ListReadAlert sits above the table.
        placeholderData: (previous) => previous,
    });
    const { data, isLoading } = runsQuery;

    const { control, handleSubmit, reset: resetForm, formState: { errors } } = useForm<RunFormValues>({
        resolver: zodResolver(runSchema),
        defaultValues: { year: currentYear, month: new Date().getMonth() + 1 },
    });

    // The prefix reaches every page of this list and the payslip page's run picker.
    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['payroll', 'runs'] });

    const createMutation = useMutation({
        mutationFn: createPayrollRun,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            resetForm({ year: currentYear, month: new Date().getMonth() + 1 });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create payroll run', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const processMutation = useMutation({
        mutationFn: processPayrollRun,
        onSuccess: ({ skipped }) => {
            invalidate();
            if (skipped.length > 0) {
                Modal.warning({
                    title: 'Payroll processed with some employees skipped',
                    content: (
                        <div>
                            <p>These active employees have no salary structure yet and were not paid this run:</p>
                            <ul>
                                {skipped.map((s) => <li key={s.employee_id}>{s.employee_name}</li>)}
                            </ul>
                        </div>
                    ),
                });
            }
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not process payroll run', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const markPaidMutation = useMutation({
        mutationFn: markPayrollRunPaid,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not mark payroll run as paid', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    // What an EMPTY table says is judged on the query's state (ListEmpty);
    // these are only the wordings for a read that genuinely returned nothing,
    // and a search that missed must name the term it missed with.
    const emptyText = term ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('payroll runs', term)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : filtersActive ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No payroll runs match these filters.">
            <Button size="small" onClick={reset}>
                Clear filters
            </Button>
        </Empty>
    ) : (
        'No payroll runs yet.'
    );

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Payroll Runs</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Payroll Run</Button>
            </Space>

            <Space style={{ marginBottom: 12 }} wrap>
                <Input.Search
                    allowClear
                    placeholder="Period, e.g. Aug 2026"
                    style={{ width: 240 }}
                    value={qDraft}
                    onChange={(event) => setQDraft(event.target.value)}
                    onSearch={(value) => setParams({ q: value.trim() || undefined })}
                />
                <Select<PayrollRunStatus>
                    allowClear
                    placeholder="Status"
                    style={{ width: 140 }}
                    value={params.status}
                    onChange={(value) => setParams({ status: value ?? undefined })}
                    options={statusOptions}
                />
                <Typography.Text type="secondary">{pageRangeLine(data?.meta, 'payroll runs')}</Typography.Text>
                {filtersActive ? (
                    <Button size="small" onClick={reset}>
                        Clear
                    </Button>
                ) : null}
            </Space>

            {/* placeholderData keeps stale rows on a failed refetch, so
                emptyText never shows the failure — this line does. */}
            <ListReadAlert state={runsQuery} entity="payroll runs" />

            <Table<PayrollRun>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data ?? []}
                locale={{ emptyText: <ListEmpty state={runsQuery} entity="payroll runs" empty={emptyText} /> }}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queried.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, RUNS_SORT_FIELDS, RUNS_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'payroll runs')}
                columns={[
                    {
                        // Year and month read as one, the way the cell prints them.
                        title: 'Period',
                        key: 'period',
                        sorter: true,
                        sortOrder: columnSortOrder('period', params.sort, RUNS_DEFAULT_SORT),
                        render: (_, row) => <strong>{periodLabel(row)}</strong>,
                    },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        key: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, RUNS_DEFAULT_SORT),
                        render: (status: PayrollRunStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Processed At',
                        key: 'processed_at',
                        sorter: true,
                        sortOrder: columnSortOrder('processed_at', params.sort, RUNS_DEFAULT_SORT),
                        render: (_, row) => row.processed_at ?? '—',
                    },
                    {
                        title: 'Paid At',
                        key: 'paid_at',
                        sorter: true,
                        sortOrder: columnSortOrder('paid_at', params.sort, RUNS_DEFAULT_SORT),
                        render: (_, row) => row.paid_at ?? '—',
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                {row.status === 'draft' && (
                                    <Button
                                        size="small"
                                        onClick={() => processMutation.mutate(row.id)}
                                        loading={processMutation.isPending && processMutation.variables === row.id}
                                    >
                                        Process
                                    </Button>
                                )}
                                {row.status === 'processed' && (
                                    <Button
                                        size="small"
                                        onClick={() => markPaidMutation.mutate(row.id)}
                                        loading={markPaidMutation.isPending && markPaidMutation.variables === row.id}
                                    >
                                        Mark Paid
                                    </Button>
                                )}
                                {row.status !== 'draft' && (
                                    <Button size="small" onClick={() => navigate(`/payroll/payslips?payroll_run_id=${row.id}`)}>
                                        View Payslips
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Payroll Run"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Year" validateStatus={errors.year ? 'error' : ''} help={errors.year?.message}>
                        <Controller
                            name="year"
                            control={control}
                            render={({ field }) => <InputNumber {...field} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Month" validateStatus={errors.month ? 'error' : ''} help={errors.month?.message}>
                        <Controller
                            name="month"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={1} max={12} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
