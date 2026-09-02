import { useQuery } from '@tanstack/react-query';
import { Table, Tabs, Typography } from 'antd';
import type { TableProps } from 'antd';
import { getBalanceSheet, getProfitAndLoss, getReceivables, getTrialBalance } from '@/features/finance/api';
import type { ProfitAndLossLine, Receivable, TrialBalanceRow } from '@/features/finance/types';
import { columnSorter, filterOptions, onFilterBy } from '@/lib/clientSort';
import { TABLE_STICKY } from '@/lib/tableProps';

// Every table here holds the WHOLE report in the browser (the endpoints
// answer arrays, not pages), so the sorters and filters are client-side
// (lib/clientSort) — honest over the full set.

function TrialBalanceTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'trial-balance'], queryFn: getTrialBalance });
    const rows = data ?? [];

    return (
        <Table<TrialBalanceRow>
            sticky={TABLE_STICKY}
            scroll={{ x: 'max-content' }}
            rowKey="account_id"
            loading={isLoading}
            dataSource={data}
            pagination={false}
            columns={[
                { title: 'Code', dataIndex: 'code', sorter: columnSorter((row) => row.code, 'text') },
                { title: 'Name', dataIndex: 'name', sorter: columnSorter((row) => row.name, 'text') },
                {
                    title: 'Type',
                    dataIndex: 'type',
                    sorter: columnSorter((row) => row.type, 'text'),
                    filters: filterOptions(rows, (row) => row.type),
                    onFilter: onFilterBy((row) => row.type),
                },
                { title: 'Total Debit', dataIndex: 'total_debit', sorter: columnSorter((row) => row.total_debit, 'number') },
                { title: 'Total Credit', dataIndex: 'total_credit', sorter: columnSorter((row) => row.total_credit, 'number') },
                { title: 'Balance', dataIndex: 'balance', sorter: columnSorter((row) => row.balance, 'number') },
            ]}
        />
    );
}

/** The code / name / amount columns the P&L and balance-sheet sections share. */
const lineColumns: TableProps<ProfitAndLossLine>['columns'] = [
    { title: 'Code', dataIndex: 'code', sorter: columnSorter((row) => row.code, 'text') },
    { title: 'Name', dataIndex: 'name', sorter: columnSorter((row) => row.name, 'text') },
    { title: 'Amount', dataIndex: 'amount', sorter: columnSorter((row) => row.amount, 'number') },
];

function ProfitAndLossTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'profit-and-loss'], queryFn: getProfitAndLoss });

    return (
        <div>
            <Typography.Title level={5}>Revenue</Typography.Title>
            <Table<ProfitAndLossLine> sticky={TABLE_STICKY} scroll={{ x: 'max-content' }} rowKey="account_id" loading={isLoading} dataSource={data?.revenue} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Revenue: {data?.total_revenue}</Typography.Paragraph>

            <Typography.Title level={5} style={{ marginTop: 24 }}>Expense</Typography.Title>
            <Table<ProfitAndLossLine> sticky={TABLE_STICKY} scroll={{ x: 'max-content' }} rowKey="account_id" loading={isLoading} dataSource={data?.expense} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Expense: {data?.total_expense}</Typography.Paragraph>

            <Typography.Title level={4} style={{ marginTop: 24 }}>Net Income: {data?.net_income}</Typography.Title>
        </div>
    );
}

function BalanceSheetTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'balance-sheet'], queryFn: getBalanceSheet });

    return (
        <div>
            <Typography.Title level={5}>Assets</Typography.Title>
            <Table<ProfitAndLossLine> sticky={TABLE_STICKY} scroll={{ x: 'max-content' }} rowKey="account_id" loading={isLoading} dataSource={data?.assets} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Assets: {data?.total_assets}</Typography.Paragraph>

            <Typography.Title level={5} style={{ marginTop: 24 }}>Liabilities</Typography.Title>
            <Table<ProfitAndLossLine> sticky={TABLE_STICKY} scroll={{ x: 'max-content' }} rowKey="account_id" loading={isLoading} dataSource={data?.liabilities} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Liabilities: {data?.total_liabilities}</Typography.Paragraph>

            <Typography.Title level={5} style={{ marginTop: 24 }}>Equity</Typography.Title>
            <Table<ProfitAndLossLine> sticky={TABLE_STICKY} scroll={{ x: 'max-content' }} rowKey="account_id" loading={isLoading} dataSource={data?.equity} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Equity: {data?.total_equity}</Typography.Paragraph>

            <Typography.Paragraph type="secondary" style={{ marginTop: 16 }}>
                Net Income (not yet closed to equity): {data?.net_income}
            </Typography.Paragraph>
        </div>
    );
}

function ReceivablesTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'receivables'], queryFn: getReceivables });
    const rows = data ?? [];

    return (
        <Table<Receivable>
            sticky={TABLE_STICKY}
            scroll={{ x: 'max-content' }}
            rowKey="invoice_id"
            loading={isLoading}
            dataSource={data}
            pagination={false}
            columns={[
                {
                    title: 'Invoice',
                    sorter: columnSorter((row) => row.invoice_id, 'number'),
                    render: (_, row) => `INV #${row.invoice_id}`,
                },
                {
                    title: 'Customer',
                    sorter: columnSorter((row) => row.customer.name, 'text'),
                    render: (_, row) => row.customer.name,
                },
                { title: 'Invoice Date', dataIndex: 'invoice_date', sorter: columnSorter((row) => row.invoice_date, 'date') },
                { title: 'Due Date', dataIndex: 'due_date', sorter: columnSorter((row) => row.due_date, 'date') },
                {
                    title: 'Status',
                    dataIndex: 'status',
                    sorter: columnSorter((row) => row.status, 'text'),
                    filters: filterOptions(rows, (row) => row.status),
                    onFilter: onFilterBy((row) => row.status),
                },
                { title: 'Amount', dataIndex: 'amount', sorter: columnSorter((row) => row.amount, 'number') },
            ]}
        />
    );
}

export default function ReportsPage() {
    return (
        <>
            <Typography.Title level={3}>Reports</Typography.Title>
            <Tabs
                items={[
                    { key: 'trial-balance', label: 'Trial Balance', children: <TrialBalanceTab /> },
                    { key: 'profit-and-loss', label: 'Profit & Loss', children: <ProfitAndLossTab /> },
                    { key: 'balance-sheet', label: 'Balance Sheet', children: <BalanceSheetTab /> },
                    { key: 'receivables', label: 'Receivables (AR)', children: <ReceivablesTab /> },
                ]}
            />
        </>
    );
}
