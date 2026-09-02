import { useQuery } from '@tanstack/react-query';
import { Button, Empty, Input, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { listAllPayrollRuns, listPayslips } from '@/features/payroll/api';
import {
    PAYSLIPS_DEFAULT_SORT,
    PAYSLIPS_LIST_SPEC,
    PAYSLIPS_SORT_FIELDS,
    payslipsQueryKey,
    payslipsServerFilters,
    periodLabel,
} from '@/features/payroll/lists';
import type { Payslip, PayslipLineType, PayslipListFilters } from '@/features/payroll/types';
import { ListEmpty, ListReadAlert } from '@/lib/ListEmpty';
import { narrowingKeys } from '@/lib/listParams';
import { TABLE_STICKY, noMatchLine, pageRangeLine, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const lineTypeColor: Record<PayslipLineType, string> = {
    earning: 'green',
    deduction: 'red',
    employer_contribution: 'blue',
};

/**
 * THE PAYSLIPS LIST. The run filter has always lived on this page's URL
 * (`?payroll_run_id=7`, the runs page's "View Payslips" link); the search,
 * the page and the page size now live beside it (useListParams), and the
 * SERVER narrows over every payslip — the table used to draw the first 20
 * with the pager off. The run picker asks for every run, not the first 20.
 */
export default function PayslipsPage() {
    const { params, setParams, setPage, reset } = useListParams<PayslipListFilters>(PAYSLIPS_LIST_SPEC);
    const filters = useMemo(() => payslipsServerFilters(params), [params]);
    const term = params.q;
    const filtersActive = narrowingKeys(params).length > 0;
    // What is typed; the URL (and the server) hear it on Enter or the button.
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => {
        setQDraft(params.q ?? '');
    }, [params.q]);

    const { data: runs } = useQuery({ queryKey: ['payroll', 'runs', 'all'], queryFn: listAllPayrollRuns });
    const runOptions = (runs?.data ?? []).map((run) => ({ value: run.id, label: periodLabel(run) }));

    const payslipsQuery = useQuery({
        queryKey: payslipsQueryKey(filters),
        queryFn: () => listPayslips(filters),
        // Turning a page keeps the last page on screen until the next one
        // lands; a refetch that fails then has rows in front of it, which
        // is why ListReadAlert sits above the table.
        placeholderData: (previous) => previous,
    });
    const { data, isLoading } = payslipsQuery;

    const emptyText = term ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={noMatchLine('payslips', term)}>
            <Button size="small" onClick={() => setParams({ q: undefined })}>
                Clear search
            </Button>
        </Empty>
    ) : filtersActive ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No payslips match these filters.">
            <Button size="small" onClick={reset}>
                Clear filters
            </Button>
        </Empty>
    ) : (
        'No payslips yet.'
    );

    return (
        <>
            <Typography.Title level={3} style={{ marginTop: 0, marginBottom: 16 }}>Payslips</Typography.Title>

            <Space style={{ marginBottom: 12 }} wrap>
                <Input.Search
                    allowClear
                    placeholder="Employee name or code"
                    style={{ width: 260 }}
                    value={qDraft}
                    onChange={(event) => setQDraft(event.target.value)}
                    onSearch={(value) => setParams({ q: value.trim() || undefined })}
                />
                <Select<number>
                    allowClear
                    showSearch
                    optionFilterProp="label"
                    placeholder="All runs"
                    style={{ width: 220 }}
                    value={params.payroll_run_id}
                    onChange={(value) => setParams({ payroll_run_id: value ?? undefined })}
                    options={runOptions}
                />
                <Typography.Text type="secondary">{pageRangeLine(data?.meta, 'payslips')}</Typography.Text>
                {filtersActive ? (
                    <Button size="small" onClick={reset}>
                        Clear
                    </Button>
                ) : null}
            </Space>

            {/* placeholderData keeps stale rows on a failed refetch, so
                emptyText never shows the failure — this line does. */}
            <ListReadAlert state={payslipsQuery} entity="payslips" />

            <Table<Payslip>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data ?? []}
                locale={{ emptyText: <ListEmpty state={payslipsQuery} entity="payslips" empty={emptyText} /> }}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queried. The
                // expanded lines keep the payslip's own order.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, PAYSLIPS_SORT_FIELDS, PAYSLIPS_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'payslips')}
                columns={[
                    // A name through the relation, not a column of this table: no server sort.
                    { title: 'Employee', render: (_, row) => row.employee?.name ?? '—' },
                    {
                        title: 'Gross Earnings',
                        dataIndex: 'gross_earnings',
                        key: 'gross_earnings',
                        align: 'right',
                        sorter: true,
                        sortOrder: columnSortOrder('gross_earnings', params.sort, PAYSLIPS_DEFAULT_SORT),
                    },
                    {
                        title: 'Total Deductions',
                        dataIndex: 'total_deductions',
                        key: 'total_deductions',
                        align: 'right',
                        sorter: true,
                        sortOrder: columnSortOrder('total_deductions', params.sort, PAYSLIPS_DEFAULT_SORT),
                    },
                    {
                        title: 'Net Pay',
                        dataIndex: 'net_pay',
                        key: 'net_pay',
                        align: 'right',
                        sorter: true,
                        sortOrder: columnSortOrder('net_pay', params.sort, PAYSLIPS_DEFAULT_SORT),
                    },
                ]}
                expandable={{
                    expandedRowRender: (row) => (
                        <Table
                            scroll={{ x: 'max-content' }}
                            rowKey="id"
                            size="small"
                            dataSource={row.lines}
                            pagination={false}
                            columns={[
                                { title: 'Label', dataIndex: 'label' },
                                {
                                    title: 'Type',
                                    dataIndex: 'type',
                                    render: (type: PayslipLineType) => <Tag color={lineTypeColor[type]}>{type}</Tag>,
                                },
                                { title: 'Amount', dataIndex: 'amount', align: 'right' },
                            ]}
                        />
                    ),
                }}
            />
        </>
    );
}
