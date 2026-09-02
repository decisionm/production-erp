import { Button, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { resultToCsv } from '@/features/ask-erp/csv';
import type { AskErpMessage, AskResult } from '@/features/ask-erp/types';
import { downloadBlob } from '@/lib/csv';
import ResultChart from './ResultChart';

/**
 * One answer: the sentence, the tables it read, the picture when there is
 * one, the rows, and the SQL behind a toggle. `result` is null for a turn
 * loaded from history — the SQL is kept, the rows are not — and the card
 * then shows the sentence and the row count alone.
 */
export default function AnswerCard({ message, result }: { message: AskErpMessage; result: AskResult | null }) {
    const [showSql, setShowSql] = useState(false);

    if (message.error) {
        return <Typography.Text type="danger">{message.error}</Typography.Text>;
    }

    const rows = result?.rows ?? [];

    return (
        <Space direction="vertical" style={{ width: '100%' }} size="small">
            <Typography.Text strong>{message.answer}</Typography.Text>
            <Space size={4} wrap>
                {message.tables_used.map((table) => (
                    <Tag key={table}>{table}</Tag>
                ))}
                {message.row_count !== null ? (
                    <Tag color="blue">{`${message.row_count} rows${result?.truncated ? ' (capped)' : ''}`}</Tag>
                ) : null}
            </Space>
            {result ? <ResultChart result={result} /> : null}
            {result && rows.length > 0 ? (
                <Table
                    size="small"
                    rowKey={(_, index) => String(index)}
                    dataSource={rows}
                    pagination={rows.length > 20 ? { pageSize: 20, size: 'small' } : false}
                    scroll={{ x: 'max-content' }}
                    columns={result.columns.map((column) => ({
                        title: column,
                        dataIndex: column,
                        render: (value: unknown) => (value === null || value === undefined ? '' : String(value)),
                    }))}
                />
            ) : null}
            <Space>
                {message.sql ? (
                    <Button size="small" onClick={() => setShowSql((open) => !open)}>
                        {showSql ? 'Hide SQL' : 'Show SQL'}
                    </Button>
                ) : null}
                {result && rows.length > 0 ? (
                    <Button
                        size="small"
                        onClick={() =>
                            downloadBlob(
                                `ask-erp-${message.id}.csv`,
                                new Blob([resultToCsv(result.columns, rows)], { type: 'text/csv;charset=utf-8' })
                            )
                        }
                    >
                        Download CSV
                    </Button>
                ) : null}
            </Space>
            {showSql ? <pre style={{ margin: 0, whiteSpace: 'pre-wrap', fontSize: 12 }}>{message.sql}</pre> : null}
        </Space>
    );
}
