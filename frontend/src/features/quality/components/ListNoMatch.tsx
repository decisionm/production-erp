import { Button, Space, Typography } from 'antd';

/**
 * What an EMPTY page says when the person NARROWED it — kept apart from the
 * page's genuine "nothing yet" wording, which ListEmpty renders when no
 * search or filter is on. "No inspections match “zebra”" and "No incoming
 * inspections recorded yet." are different facts, and reading the second
 * over a search that simply missed sends someone to record an inspection
 * that already exists.
 *
 * One text node on purpose (a template string, not JSX interpolation): a
 * server render separates adjacent interpolations with comment nodes, and
 * the sentence is asserted whole.
 */
export function ListNoMatch({ entity, term, onClear }: { entity: string; term?: string; onClear: () => void }) {
    const sentence = term ? `No ${entity} match “${term}”` : `No ${entity} match these filters`;

    return (
        <Space direction="vertical" size={8} style={{ padding: '16px 0' }}>
            <Typography.Text type="secondary">{sentence}</Typography.Text>
            <Button size="small" onClick={onClear}>
                Clear
            </Button>
        </Space>
    );
}
