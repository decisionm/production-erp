import { Collapse, Space, Tag, Typography } from 'antd';
import type { CatalogueEntry } from '@/features/ask-erp/types';

/**
 * What to offer someone facing an empty question box.
 *
 * IT USED TO BE THE TABLE LIST — one chip per table, which for an
 * Administrator holding every permission meant 122 grey pills filling the
 * screen before a single question had been asked. "GRN Schedule Allocations"
 * and "Store Issue Bag Scans" are schema, not questions, and clicking one
 * seeded a fragment ("How many grn schedule allocations ") that nobody would
 * type and the server could do little with.
 *
 * Now it leads with QUESTIONS the server says this login can actually get an
 * answer to, each one clickable and sendable as it stands. The table list is
 * kept — it is genuinely useful when a question comes back unanswered and you
 * want to know what is even in scope — but folded away behind a count, where
 * it informs instead of shouting.
 */
export default function TableChips({
    entries,
    examples,
    onPick,
    onAsk,
}: {
    entries: CatalogueEntry[];
    examples: string[];
    onPick: (entry: CatalogueEntry) => void;
    onAsk: (question: string) => void;
}) {
    return (
        <Space direction="vertical" size={8} style={{ width: '100%' }}>
            {examples.length > 0 && (
                <Space size={6} wrap>
                    {examples.map((question) => (
                        <Tag
                            key={question}
                            color="blue"
                            style={{ cursor: 'pointer', marginInlineEnd: 0 }}
                            onClick={() => onAsk(question)}
                        >
                            {question}
                        </Tag>
                    ))}
                </Space>
            )}

            {entries.length > 0 && (
                <Collapse
                    ghost
                    size="small"
                    items={[
                        {
                            key: 'tables',
                            label: (
                                <Typography.Text type="secondary">
                                    {`Tables you can query (${entries.length})`}
                                </Typography.Text>
                            ),
                            children: (
                                <Space size={4} wrap>
                                    {entries.map((entry) => (
                                        <Tag
                                            key={entry.table}
                                            style={{ cursor: 'pointer' }}
                                            onClick={() => onPick(entry)}
                                        >
                                            {entry.label}
                                        </Tag>
                                    ))}
                                </Space>
                            ),
                        },
                    ]}
                />
            )}
        </Space>
    );
}
