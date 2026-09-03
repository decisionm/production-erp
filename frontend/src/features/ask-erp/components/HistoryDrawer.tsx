import { DeleteOutlined, EditOutlined } from '@ant-design/icons';
import { Button, Drawer, Input, List, Popconfirm, Space, Typography } from 'antd';
import { useState } from 'react';
import type { AskErpConversationSummary } from '@/features/ask-erp/types';

/**
 * Past conversations, on demand.
 *
 * IT USED TO BE A PERMANENT COLUMN taking a quarter of the page, and on a
 * phone it stacked ABOVE the thread — so a supervisor scrolled past every old
 * question to reach the box for a new one. Behind a button, the answers get
 * the full width at every size, which is what a table of machines and
 * kilograms actually needs.
 *
 * Rename and delete live here rather than in the thread because they are
 * about the list, not about an answer.
 */
export default function HistoryDrawer({
    open,
    onClose,
    conversations,
    loading,
    activeId,
    page,
    perPage,
    total,
    onPage,
    onSearch,
    onOpen,
    onRename,
    onDelete,
}: {
    open: boolean;
    onClose: () => void;
    conversations: AskErpConversationSummary[];
    loading: boolean;
    activeId: number | null;
    page: number;
    perPage: number;
    total: number;
    onPage: (page: number) => void;
    onSearch: (term: string) => void;
    onOpen: (id: number) => void;
    onRename: (id: number, title: string) => void;
    onDelete: (id: number) => void;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editingTitle, setEditingTitle] = useState('');

    const startEditing = (conversation: AskErpConversationSummary) => {
        setEditingId(conversation.id);
        setEditingTitle(conversation.title);
    };

    const commit = () => {
        const title = editingTitle.trim();
        if (editingId !== null && title) onRename(editingId, title);
        setEditingId(null);
    };

    return (
        <Drawer title="Past questions" open={open} onClose={onClose} width={380} placement="left">
            <Space direction="vertical" size={12} style={{ width: '100%' }}>
                <Input.Search placeholder="Search past questions" allowClear onSearch={(value) => onSearch(value.trim())} />
                <List
                    size="small"
                    loading={loading}
                    dataSource={conversations}
                    locale={{ emptyText: 'No past questions yet.' }}
                    pagination={{
                        current: page,
                        pageSize: perPage,
                        total,
                        size: 'small',
                        onChange: onPage,
                        hideOnSinglePage: true,
                    }}
                    renderItem={(conversation) => (
                        <List.Item
                            style={{
                                cursor: 'pointer',
                                paddingInline: 8,
                                background: conversation.id === activeId ? 'var(--brand-orange-soft, #FFF4EA)' : undefined,
                            }}
                            actions={[
                                <Button
                                    key="rename"
                                    type="text"
                                    size="small"
                                    aria-label={`Rename ${conversation.title}`}
                                    icon={<EditOutlined />}
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        startEditing(conversation);
                                    }}
                                />,
                                <Popconfirm
                                    key="delete"
                                    title="Delete this conversation?"
                                    okText="Delete"
                                    okButtonProps={{ danger: true }}
                                    onConfirm={() => onDelete(conversation.id)}
                                >
                                    <Button
                                        type="text"
                                        size="small"
                                        danger
                                        aria-label={`Delete ${conversation.title}`}
                                        icon={<DeleteOutlined />}
                                        onClick={(event) => event.stopPropagation()}
                                    />
                                </Popconfirm>,
                            ]}
                        >
                            {editingId === conversation.id ? (
                                <Input
                                    autoFocus
                                    size="small"
                                    value={editingTitle}
                                    maxLength={120}
                                    onChange={(event) => setEditingTitle(event.target.value)}
                                    onPressEnter={commit}
                                    onBlur={commit}
                                    onClick={(event) => event.stopPropagation()}
                                />
                            ) : (
                                <div style={{ flex: 1, minWidth: 0 }} onClick={() => onOpen(conversation.id)}>
                                    <List.Item.Meta
                                        title={<Typography.Text ellipsis>{conversation.title}</Typography.Text>}
                                        description={`${conversation.message_count} messages`}
                                    />
                                </div>
                            )}
                        </List.Item>
                    )}
                />
            </Space>
        </Drawer>
    );
}
