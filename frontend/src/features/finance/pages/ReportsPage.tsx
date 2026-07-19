import { useQuery } from '@tanstack/react-query';
import { Table, Tabs, Typography } from 'antd';
import { getBalanceSheet, getProfitAndLoss, getReceivables, getTrialBalance } from '@/features/finance/api';
import type { ProfitAndLossLine, TrialBalanceRow } from '@/features/finance/types';

function TrialBalanceTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'trial-balance'], queryFn: getTrialBalance });

    return (
        <Table<TrialBalanceRow>
            scroll={{ x: 'max-content' }}
            rowKey="account_id"
            loading={isLoading}
            dataSource={data}
            pagination={false}
            columns={[
                { title: 'Code', dataIndex: 'code' },
                { title: 'Name', dataIndex: 'name' },
                { title: 'Type', dataIndex: 'type' },
                { title: 'Total Debit', dataIndex: 'total_debit' },
                { title: 'Total Credit', dataIndex: 'total_credit' },
                { title: 'Balance', dataIndex: 'balance' },
            ]}
        />
    );
}

function ProfitAndLossTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'profit-and-loss'], queryFn: getProfitAndLoss });

    const lineColumns = [
        { title: 'Code', dataIndex: 'code' },
        { title: 'Name', dataIndex: 'name' },
        { title: 'Amount', dataIndex: 'amount' },
    ];

    return (
        <div>
            <Typography.Title level={5}>Revenue</Typography.Title>
            <Table<ProfitAndLossLine> rowKey="account_id" loading={isLoading} dataSource={data?.revenue} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Revenue: {data?.total_revenue}</Typography.Paragraph>

            <Typography.Title level={5} style={{ marginTop: 24 }}>Expense</Typography.Title>
            <Table<ProfitAndLossLine> rowKey="account_id" loading={isLoading} dataSource={data?.expense} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Expense: {data?.total_expense}</Typography.Paragraph>

            <Typography.Title level={4} style={{ marginTop: 24 }}>Net Income: {data?.net_income}</Typography.Title>
        </div>
    );
}

function BalanceSheetTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'balance-sheet'], queryFn: getBalanceSheet });

    const lineColumns = [
        { title: 'Code', dataIndex: 'code' },
        { title: 'Name', dataIndex: 'name' },
        { title: 'Amount', dataIndex: 'amount' },
    ];

    return (
        <div>
            <Typography.Title level={5}>Assets</Typography.Title>
            <Table<ProfitAndLossLine> rowKey="account_id" loading={isLoading} dataSource={data?.assets} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Assets: {data?.total_assets}</Typography.Paragraph>

            <Typography.Title level={5} style={{ marginTop: 24 }}>Liabilities</Typography.Title>
            <Table<ProfitAndLossLine> rowKey="account_id" loading={isLoading} dataSource={data?.liabilities} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Liabilities: {data?.total_liabilities}</Typography.Paragraph>

            <Typography.Title level={5} style={{ marginTop: 24 }}>Equity</Typography.Title>
            <Table<ProfitAndLossLine> rowKey="account_id" loading={isLoading} dataSource={data?.equity} pagination={false} columns={lineColumns} />
            <Typography.Paragraph strong style={{ marginTop: 8 }}>Total Equity: {data?.total_equity}</Typography.Paragraph>

            <Typography.Paragraph type="secondary" style={{ marginTop: 16 }}>
                Net Income (not yet closed to equity): {data?.net_income}
            </Typography.Paragraph>
        </div>
    );
}

function ReceivablesTab() {
    const { data, isLoading } = useQuery({ queryKey: ['finance', 'reports', 'receivables'], queryFn: getReceivables });

    return (
        <Table
            scroll={{ x: 'max-content' }}
            rowKey="invoice_id"
            loading={isLoading}
            dataSource={data}
            pagination={false}
            columns={[
                { title: 'Invoice', render: (_, row: any) => `INV #${row.invoice_id}` },
                { title: 'Customer', render: (_, row: any) => row.customer.name },
                { title: 'Invoice Date', dataIndex: 'invoice_date' },
                { title: 'Due Date', dataIndex: 'due_date' },
                { title: 'Status', dataIndex: 'status' },
                { title: 'Amount', dataIndex: 'amount' },
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
