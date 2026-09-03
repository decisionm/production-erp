import { Collapse, Space, Tag, Typography } from 'antd';
import { Link } from 'react-router-dom';
import { outlineNavItems } from '@/app/AppLayout';
import { useAuthStore } from '@/features/auth/store';
import { HELP_BY_ROUTE, HELP_FAQ, type HelpEntry } from '@/features/help/helpContent';

/**
 * Help — the screens THIS login can open, in the order the menu shows them.
 *
 * The page used to be a hand-written tour of every module the product has,
 * including four the factory hid (DEC-20260812-001: CRM and Finance stay
 * unadopted) and screens that no longer exist under the names it used
 * ("Work Centers", a separate Product Standards page). A help page that
 * describes a menu the reader cannot see is worse than none: it teaches
 * that the documentation is wrong.
 *
 * So the outline is not written here at all. It is `buildNavItems(user)` —
 * the same permission- and adoption-filtered list the sidebar renders — and
 * the words for each screen are keyed by ROUTE in helpContent.ts.
 * HelpPage.render.test.tsx pins both directions: every visible entry has
 * words, and no words describe a screen that is not in the menu.
 */

type NavGroup = ReturnType<typeof outlineNavItems>[number];
type NavLeaf = NonNullable<NavGroup['children']>[number];

function Entry({ leaf }: { leaf: NavLeaf }) {
    const entry: HelpEntry | undefined = HELP_BY_ROUTE[leaf.key];

    return (
        <div style={{ marginBottom: 18 }}>
            <Typography.Title level={5} style={{ marginBottom: 4 }}>
                <Link to={leaf.key}>{leaf.label}</Link>
            </Typography.Title>
            {entry ? (
                <Space direction="vertical" size={4}>
                    <Typography.Paragraph style={{ marginBottom: 0 }}>{entry.what}</Typography.Paragraph>
                    {entry.actions && entry.actions.length > 0 && (
                        <Space size={[4, 4]} wrap>
                            {entry.actions.map((action) => (
                                <Tag key={action}>{action}</Tag>
                            ))}
                        </Space>
                    )}
                    {entry.rule && (
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {entry.rule}
                        </Typography.Text>
                    )}
                </Space>
            ) : null}
        </div>
    );
}

export default function HelpPage() {
    const user = useAuthStore((state) => state.user);
    const groups = outlineNavItems(user);

    const singles = groups.filter((group) => !group.children && group.key !== '/help');
    const modules = groups.filter((group) => (group.children?.length ?? 0) > 0);

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>
                Help
            </Typography.Title>
            <Typography.Paragraph type="secondary" style={{ maxWidth: 760 }}>
                Every screen in your menu, in menu order: what you do there and the actions it offers.
            </Typography.Paragraph>

            {singles.map((group) => (
                <Entry key={group.key} leaf={{ key: group.key, label: group.label }} />
            ))}

            {/* Every section open: a help page is read by scanning and by the
                browser's Find, and an accordion defeats both. */}
            <Collapse
                defaultActiveKey={modules.map((group) => group.key)}
                style={{ marginTop: 8 }}
                items={modules.map((group) => ({
                    key: group.key,
                    label: group.label,
                    children: (group.children ?? []).map((leaf) => <Entry key={leaf.key} leaf={leaf} />),
                }))}
            />

            {HELP_FAQ.length > 0 && (
                <>
                    <Typography.Title level={4} style={{ marginTop: 32 }}>
                        Common questions
                    </Typography.Title>
                    {HELP_FAQ.map((faq) => (
                        <div key={faq.question} style={{ marginBottom: 14 }}>
                            <Typography.Text strong>{faq.question}</Typography.Text>
                            <Typography.Paragraph style={{ marginTop: 2, marginBottom: 0 }}>{faq.answer}</Typography.Paragraph>
                        </div>
                    ))}
                </>
            )}
        </>
    );
}
