import { Table, Tooltip, Typography } from 'antd';
import { itemLabel } from '@/lib/itemLabel';
import type { MaterialRequestLine } from '../types';
import { describeRequestLine } from '../words';

/**
 * A request's lines, with the states never collapsed into one number.
 *
 * Four different words for four different things: what was asked for, what
 * has been ISSUED TO PRODUCTION (and is standing in Production/WIP
 * unconsumed), what is still to issue, and what has come back to the store.
 * The wording comes from the words helper, so the store screen and the
 * production screen say the same thing, and a quantity the server did not
 * state renders as an em dash — never as 0, which would read as "there is
 * none" and stop a shift for no reason.
 *
 * The returned column appears only where the backend rolls returns up onto
 * the request line; returns are recorded against the HANDOVER, and a column
 * of permanent dashes would look like a broken figure rather than a figure
 * that lives elsewhere. The note under the table says where it lives.
 */
export default function RequestLinesTable({ lines }: { lines: MaterialRequestLine[] }) {
    const showReturned = lines.some((line) => line.returned_quantity !== undefined && line.returned_quantity !== null);

    const cellsFor = (line: MaterialRequestLine) =>
        describeRequestLine(
            {
                requested: line.quantity,
                issued: line.issued_quantity,
                remaining: line.remaining_quantity,
                returned: line.returned_quantity ?? null,
            },
            line.uom ?? line.item?.uom ?? null,
        );

    const template = describeRequestLine({ requested: null, issued: null, remaining: null, returned: null }).filter(
        (cell) => cell.key !== 'returned' || showReturned,
    );

    return (
        <>
            <Table<MaterialRequestLine>
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={lines}
                scroll={{ x: 'max-content' }}
                columns={[
                    { title: 'Material', render: (_, line) => itemLabel(line.item) },
                    ...template.map((cell) => ({
                        title: (
                            <Tooltip title={cell.help}>
                                <span>{cell.label}</span>
                            </Tooltip>
                        ),
                        key: cell.key,
                        align: 'right' as const,
                        render: (_: unknown, line: MaterialRequestLine) =>
                            cellsFor(line).find((current) => current.key === cell.key)?.value ?? '—',
                    })),
                    { title: 'Notes', dataIndex: 'notes', render: (notes: string | null) => notes || '—' },
                ]}
            />
            {showReturned ? null : (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Material handed back unused is recorded against the handover it came from — see the handovers on the
                    store screen.
                </Typography.Text>
            )}
        </>
    );
}
