import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import { createJournalEntry, listAllGLAccounts, listJournalEntries, postJournalEntry } from '@/features/finance/api';
import {
    JOURNAL_ENTRY_DEFAULT_SORT,
    JOURNAL_ENTRY_LIST_SPEC,
    JOURNAL_ENTRY_SORT_FIELDS,
    type JournalEntryListParams,
    journalEntriesQueryKey,
    journalEntryServerFilters,
} from '@/features/finance/journalEntryList';
import type { JournalEntry, JournalEntryStatus } from '@/features/finance/types';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const lineSchema = z.object({
    gl_account_id: z.number({ error: 'Account is required' }),
    debit: z.number().min(0),
    credit: z.number().min(0),
});

const entrySchema = z
    .object({
        entry_date: z.string({ error: 'Entry date is required' }),
        reference: z.string().optional(),
        memo: z.string().optional(),
        lines: z.array(lineSchema).min(2, 'At least two lines are required'),
    })
    .refine(
        (data) => {
            const totalDebit = data.lines.reduce((sum, l) => sum + (l.debit || 0), 0);
            const totalCredit = data.lines.reduce((sum, l) => sum + (l.credit || 0), 0);
            return Math.abs(totalDebit - totalCredit) < 0.0001;
        },
        { message: 'Total debit must equal total credit', path: ['lines'] },
    );
type EntryFormValues = z.infer<typeof entrySchema>;

const statusColor: Record<JournalEntryStatus, string> = {
    draft: 'default',
    posted: 'green',
};

export default function JournalEntriesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailEntry, setDetailEntry] = useState<JournalEntry | null>(null);
    const queryClient = useQueryClient();

    // THE REGISTER'S VIEW IS ITS URL: sort, page and page size, sorted and
    // paged on the SERVER over the whole register.
    const { params, setParams, setPage } = useListParams<JournalEntryListParams>(JOURNAL_ENTRY_LIST_SPEC);
    const filters = useMemo(() => journalEntryServerFilters(params), [params]);
    const { data, isLoading } = useQuery({
        queryKey: journalEntriesQueryKey(filters),
        queryFn: () => listJournalEntries(filters),
        placeholderData: (previous) => previous,
    });
    const { data: accounts } = useQuery({ queryKey: ['finance', 'gl-accounts', 'all'], queryFn: listAllGLAccounts });
    // WS-B: a DEACTIVATED account takes no new posting, so the line picker
    // stops offering one. Entries already posted against it are untouched and
    // still read back on the list below.
    const accountOptions = activePickerOptions(accounts?.data, {
        isActive: (a) => a.is_active,
        option: (a) => ({ value: a.id, label: `${a.code} — ${a.name}` }),
    });

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<EntryFormValues>({
        resolver: zodResolver(entrySchema),
        defaultValues: { lines: [{ gl_account_id: undefined, debit: 0, credit: 0 }, { gl_account_id: undefined, debit: 0, credit: 0 }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const watchedLines = watch('lines');
    const { totalDebit, totalCredit, isBalanced } = useMemo(() => {
        const debit = watchedLines?.reduce((sum, l) => sum + (Number(l?.debit) || 0), 0) ?? 0;
        const credit = watchedLines?.reduce((sum, l) => sum + (Number(l?.credit) || 0), 0) ?? 0;
        return { totalDebit: debit, totalCredit: credit, isBalanced: Math.abs(debit - credit) < 0.0001 };
    }, [watchedLines]);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['finance', 'journal-entries'] });

    const createMutation = useMutation({
        mutationFn: createJournalEntry,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ lines: [{ gl_account_id: undefined, debit: 0, credit: 0 }, { gl_account_id: undefined, debit: 0, credit: 0 }] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create journal entry', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });
    const postMutation = useMutation({ mutationFn: postJournalEntry, onSuccess: invalidate });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Journal Entries</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Journal Entry</Button>
            </Space>

            <Table<JournalEntry>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries the whole register.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, JOURNAL_ENTRY_SORT_FIELDS, JOURNAL_ENTRY_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'journal entries')}
                columns={[
                    {
                        title: 'ID',
                        dataIndex: 'id',
                        key: 'id',
                        sorter: true,
                        sortOrder: columnSortOrder('id', params.sort, JOURNAL_ENTRY_DEFAULT_SORT),
                    },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        key: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, JOURNAL_ENTRY_DEFAULT_SORT),
                        render: (status: JournalEntryStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Date',
                        dataIndex: 'entry_date',
                        key: 'entry_date',
                        sorter: true,
                        sortOrder: columnSortOrder('entry_date', params.sort, JOURNAL_ENTRY_DEFAULT_SORT),
                    },
                    {
                        title: 'Reference',
                        dataIndex: 'reference',
                        key: 'reference',
                        sorter: true,
                        sortOrder: columnSortOrder('reference', params.sort, JOURNAL_ENTRY_DEFAULT_SORT),
                    },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailEntry(row)}>
                                    View
                                </Button>
                                {row.status === 'draft' && (
                                    <Button
                                        size="small"
                                        onClick={() => postMutation.mutate(row.id)}
                                        loading={postMutation.isPending}
                                    >
                                        Post
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Journal Entry"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) =>
                    createMutation.mutate({
                        entry_date: values.entry_date,
                        reference: values.reference,
                        memo: values.memo,
                        lines: values.lines.map((l) => ({
                            gl_account_id: l.gl_account_id,
                            debit: l.debit || 0,
                            credit: l.credit || 0,
                        })),
                    }),
                )}
                confirmLoading={createMutation.isPending}
                okButtonProps={{ disabled: !isBalanced }}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Entry Date"
                        validateStatus={errors.entry_date ? 'error' : ''}
                        help={errors.entry_date?.message}
                    >
                        <Controller
                            name="entry_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Reference">
                        <Controller name="reference" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Memo">
                        <Controller name="memo" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Lines</Typography.Text>
                    {fields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <Controller
                                name={`lines.${index}.gl_account_id`}
                                control={control}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        options={accountOptions}
                                        showSearch
                                        optionFilterProp="label"
                                        style={{ width: 240 }}
                                        placeholder="Account"
                                    />
                                )}
                            />
                            <Controller
                                name={`lines.${index}.debit`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Debit" />}
                            />
                            <Controller
                                name={`lines.${index}.credit`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Credit" />}
                            />
                            <Button danger onClick={() => remove(index)} disabled={fields.length <= 2}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() => append({ gl_account_id: undefined as unknown as number, debit: 0, credit: 0 })}
                    >
                        Add Line
                    </Button>

                    <div style={{ marginTop: 16 }}>
                        <Alert
                            type={isBalanced ? 'success' : 'warning'}
                            message={`Total debit: ${totalDebit.toFixed(2)}  —  Total credit: ${totalCredit.toFixed(2)}${isBalanced ? ' (balanced)' : ' (not balanced)'}`}
                            showIcon
                        />
                    </div>
                </Form>
            </Modal>

            <Drawer
                title={`Journal Entry #${detailEntry?.id}`}
                open={detailEntry !== null}
                onClose={() => setDetailEntry(null)}
                width="min(100vw, 600px)"
                destroyOnHidden
            >
                {detailEntry && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailEntry.status]}>{detailEntry.status}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Entry Date">{detailEntry.entry_date}</Descriptions.Item>
                            <Descriptions.Item label="Reference">{detailEntry.reference ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Memo">{detailEntry.memo ?? '—'}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Lines
                        </Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailEntry.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                {
                                    title: 'Account',
                                    render: (_, line) => `${line.gl_account.code} — ${line.gl_account.name}`,
                                },
                                { title: 'Debit', dataIndex: 'debit' },
                                { title: 'Credit', dataIndex: 'credit' },
                                { title: 'Memo', dataIndex: 'memo' },
                            ]}
                            summary={(lines) => {
                                const totalDebit = lines.reduce((sum, l) => sum + Number(l.debit), 0);
                                const totalCredit = lines.reduce((sum, l) => sum + Number(l.credit), 0);
                                return (
                                    <Table.Summary.Row>
                                        <Table.Summary.Cell index={0}>
                                            <strong>Total</strong>
                                        </Table.Summary.Cell>
                                        <Table.Summary.Cell index={1}>
                                            <strong>{totalDebit.toFixed(2)}</strong>
                                        </Table.Summary.Cell>
                                        <Table.Summary.Cell index={2}>
                                            <strong>{totalCredit.toFixed(2)}</strong>
                                        </Table.Summary.Cell>
                                        <Table.Summary.Cell index={3} />
                                    </Table.Summary.Row>
                                );
                            }}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
