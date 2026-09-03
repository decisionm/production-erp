import { useMutation } from '@tanstack/react-query';
import { Button, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { rerunMessage } from '@/features/ask-erp/api';
import { resultToCsv } from '@/features/ask-erp/csv';
import type { AskErpMessage, AskResult } from '@/features/ask-erp/types';
import { apiErrorSummary } from '@/lib/apiError';
import { downloadBlob } from '@/lib/csv';
import ResultChart from './ResultChart';

/**
 * One answer: the sentence, then the rows, then what it read and how.
 *
 * A STORED TURN KEEPS ITS SQL AND NOT ITS ROWS. That was a deliberate
 * decision — rows are re-runnable, and a frozen copy is a staler second
 * truth — but nothing re-ran them, so reopening yesterday's conversation
 * showed a sentence over an empty space. "Run again" is the missing half:
 * the same query, re-checked against what this reader may see today, with
 * today's numbers.
 *
 * The orange rule marks a turn that has its rows on screen. It is the app's
 * existing idiom for "this is the live one" — the same orange that marks the
 * active item in the nav.
 */
export default function AnswerCard({
    message,
    result,
    conversationId,
    onRerun,
}: {
    message: AskErpMessage;
    result: AskResult | null;
    conversationId: number | null;
    onRerun?: (result: AskResult) => void;
}) {
    const [showSql, setShowSql] = useState(false);

    const rerun = useMutation({
        mutationFn: () => rerunMessage(conversationId as number, message.id),
        onSuccess: (fresh) => onRerun?.(fresh),
    });

    if (message.error) {
        return <Typography.Text type="danger">{message.error}</Typography.Text>;
    }

    const rows = result?.rows ?? [];
    const canRerun = conversationId !== null && Boolean(message.sql) && result === null;

    return (
        <Space
            direction="vertical"
            style={{
                width: '100%',
                borderLeft: `3px solid ${result ? 'var(--brand-orange, #F07C1A)' : 'var(--brand-rule, #D9E0EC)'}`,
                paddingLeft: 12,
            }}
            size="small"
        >
            {/* Tabular figures: this floor compares 192,635 against 112,151,
                and proportional digits make columns of quantities harder to
                scan for no reason anyone could name. */}
            <Typography.Text strong style={{ fontSize: 16, fontVariantNumeric: 'tabular-nums' }}>
                {message.answer}
            </Typography.Text>

            {result ? <ResultChart result={result} /> : null}

            {result && rows.length > 0 ? (
                <Table
                    size="small"
                    rowKey={(_, index) => String(index)}
                    dataSource={rows}
                    pagination={rows.length > 20 ? { pageSize: 20, size: 'small' } : false}
                    scroll={{ x: 'max-content' }}
                    style={{ fontVariantNumeric: 'tabular-nums' }}
                    columns={result.columns.map((column) => ({
                        title: column,
                        dataIndex: column,
                        render: (value: unknown) => (value === null || value === undefined ? '' : String(value)),
                    }))}
                />
            ) : null}

            {rerun.isError ? <Typography.Text type="danger">{apiErrorSummary(rerun.error)}</Typography.Text> : null}

            <Space size={4} wrap>
                {message.tables_used.map((table) => (
                    <Tag key={table}>{table}</Tag>
                ))}
                {message.row_count !== null ? (
                    <Tag color="blue">{`${message.row_count} rows${result?.truncated ? ' (capped)' : ''}`}</Tag>
                ) : null}
            </Space>

            <Space wrap>
                {canRerun ? (
                    <Button size="small" type="primary" ghost loading={rerun.isPending} onClick={() => rerun.mutate()}>
                        Run again
                    </Button>
                ) : null}
                {message.sql ? (
                    <Button size="small" onClick={() => setShowSql((isOpen) => !isOpen)}>
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
