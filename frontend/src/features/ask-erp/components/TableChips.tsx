import { Space, Tag } from 'antd';
import type { CatalogueEntry } from '@/features/ask-erp/types';

/** What this login may ask about — one chip per table; clicking seeds the question. */
export default function TableChips({ entries, onPick }: { entries: CatalogueEntry[]; onPick: (entry: CatalogueEntry) => void }) {
    return (
        <Space size={4} wrap>
            {entries.map((entry) => (
                <Tag key={entry.table} style={{ cursor: 'pointer' }} onClick={() => onPick(entry)}>
                    {entry.label}
                </Tag>
            ))}
        </Space>
    );
}
