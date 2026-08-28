/**
 * The rendering half of listState.ts — see that file for the rule. Drop-in
 * for a Table's `locale.emptyText`:
 *
 *     locale={{ emptyText: <ListEmpty state={query} entity="goods receipts"
 *               empty="No receipts yet." /> }}
 *
 * On a failed read it shows the failure line and a Try again button wired
 * to the query's own refetch — the operator corrects a dropped connection
 * on the spot instead of concluding the queue is empty. The page's own
 * empty wording (string or node) renders unchanged when the read genuinely
 * returned nothing.
 *
 * `ListReadAlert` is the companion for lists kept on screen with stale rows
 * (placeholderData): when a REFETCH fails there ARE rows, so emptyText
 * never shows and the failure needs its own line above the table.
 */
import { Alert, Button, Empty, Space, Typography } from 'antd';
import type { ReactNode } from 'react';
import { type ListReadState, listReadFailureLine, listReadingLine, listStateKind } from './listState';

interface ListEmptyProps {
    state: ListReadState & { refetch?: () => void };
    entity: string;
    empty: ReactNode;
}

export function ListEmpty({ state, entity, empty }: ListEmptyProps) {
    const kind = listStateKind(state);

    if (kind === 'error') {
        return (
            <Space direction="vertical" size={8} style={{ padding: '16px 0' }}>
                <Typography.Text type="danger">{listReadFailureLine(entity, state.error)}</Typography.Text>
                {state.refetch ? (
                    <Button size="small" onClick={() => state.refetch?.()}>
                        Try again
                    </Button>
                ) : null}
            </Space>
        );
    }

    if (kind === 'pending') {
        return <Typography.Text type="secondary">{listReadingLine(entity)}</Typography.Text>;
    }

    return typeof empty === 'string' ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={empty} /> : empty;
}

/** A refetch failed but stale rows are still on screen — say so above them. */
export function ListReadAlert({ state, entity }: { state: ListReadState & { refetch?: () => void }; entity: string }) {
    if (!state.isError) return null;

    return (
        <Alert
            type="error"
            showIcon
            style={{ marginBottom: 12 }}
            message={listReadFailureLine(entity, state.error)}
            action={
                state.refetch ? (
                    <Button size="small" onClick={() => state.refetch?.()}>
                        Try again
                    </Button>
                ) : undefined
            }
        />
    );
}
