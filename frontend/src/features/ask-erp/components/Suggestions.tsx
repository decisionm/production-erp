import { Button, Space, Typography } from 'antd';

/** How many to offer. Four is a prompt; twenty-two is a menu, and 122 was a wall. */
const SHOWN = 4;

/**
 * What to offer someone facing an empty question box.
 *
 * THE PAGE USED TO OPEN WITH THE SCHEMA — one chip per table, 122 of them for
 * anyone holding every permission. "GRN Schedule Allocations" is not a
 * question, and clicking it seeded a fragment nobody would type. That became
 * 22 question chips, which was better and still a wall.
 *
 * Four questions, and only while the thread is empty. Once a conversation has
 * started, the answers get the space and the suggestions are gone — a reader
 * who needs them again is one refusal away from a list, because a question
 * that matches nothing now comes back naming a few that would.
 */
export default function Suggestions({ examples, onAsk }: { examples: string[]; onAsk: (question: string) => void }) {
    if (examples.length === 0) return null;

    return (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <Typography.Text type="secondary">
                Ask about stock, orders, production or attendance.
            </Typography.Text>
            <Space size={8} wrap>
                {examples.slice(0, SHOWN).map((question) => (
                    <Button key={question} shape="round" onClick={() => onAsk(question)}>
                        {question}
                    </Button>
                ))}
            </Space>
        </Space>
    );
}
