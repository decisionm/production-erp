import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Col, Input, List, Row, Space, Typography } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { askQuestion, createConversation, getCatalogue, getConversation, listConversations } from '@/features/ask-erp/api';
import AnswerCard from '@/features/ask-erp/components/AnswerCard';
import TableChips from '@/features/ask-erp/components/TableChips';
import type { AskErpMessage, AskResult } from '@/features/ask-erp/types';
import { apiErrorSummary } from '@/lib/apiError';

/**
 * ASK ERP. Left: this login's conversations, searched and paged by the
 * server. Right: the thread. A question POSTs to the server, which decides
 * from the login's permissions which tables may even be looked at; the page
 * shows what came back and computes nothing of its own.
 */
export default function AskErpPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const conversationId = Number(searchParams.get('conversation')) || null;
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [draft, setDraft] = useState('');
    const [results, setResults] = useState<Record<number, AskResult>>({});
    const queryClient = useQueryClient();
    const bottomRef = useRef<HTMLDivElement>(null);

    const catalogue = useQuery({ queryKey: ['ask-erp', 'catalogue'], queryFn: getCatalogue });
    const conversations = useQuery({
        queryKey: ['ask-erp', 'conversations', { q, page }],
        queryFn: () => listConversations({ q: q || undefined, page }),
        placeholderData: (previous) => previous,
    });
    const thread = useQuery({
        queryKey: ['ask-erp', 'conversation', conversationId],
        queryFn: () => getConversation(conversationId as number),
        enabled: conversationId !== null,
    });

    const open = (id: number) => setSearchParams({ conversation: String(id) });

    const create = useMutation({
        mutationFn: () => createConversation(),
        onSuccess: (conversation) => {
            queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversations'] });
            open(conversation.id);
        },
    });

    const ask = useMutation({
        mutationFn: async (question: string) => {
            const id = conversationId ?? (await createConversation(question)).id;
            if (id !== conversationId) open(id);
            return { id, ...(await askQuestion(id, question)) };
        },
        onSuccess: ({ id, message, result }) => {
            setResults((current) => ({ ...current, [message.id]: result }));
            setDraft('');
            queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversation', id] });
            queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversations'] });
        },
        onError: () => {
            if (conversationId) queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversation', conversationId] });
        },
    });

    const messages: AskErpMessage[] = useMemo(() => thread.data?.messages ?? [], [thread.data]);
    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length, ask.isPending]);

    const submit = () => {
        const question = draft.trim();
        if (question && !ask.isPending) ask.mutate(question);
    };

    return (
        <Row gutter={16} style={{ minHeight: 'calc(100vh - 140px)' }}>
            <Col xs={24} md={7} lg={6}>
                <Space direction="vertical" style={{ width: '100%' }}>
                    <Button type="primary" block onClick={() => create.mutate()} loading={create.isPending}>
                        New question
                    </Button>
                    <Input.Search
                        allowClear
                        placeholder="Search conversations"
                        onSearch={(value) => {
                            setQ(value.trim());
                            setPage(1);
                        }}
                    />
                    <List
                        size="small"
                        loading={conversations.isFetching}
                        dataSource={conversations.data?.data ?? []}
                        pagination={{
                            current: page,
                            pageSize: conversations.data?.meta.per_page ?? 20,
                            total: conversations.data?.meta.total ?? 0,
                            size: 'small',
                            onChange: setPage,
                            hideOnSinglePage: true,
                        }}
                        renderItem={(conversation) => (
                            <List.Item
                                onClick={() => open(conversation.id)}
                                style={{ cursor: 'pointer', background: conversation.id === conversationId ? '#e6f4ff' : undefined }}
                            >
                                <List.Item.Meta title={conversation.title} description={`${conversation.message_count} messages`} />
                            </List.Item>
                        )}
                    />
                </Space>
            </Col>
            <Col xs={24} md={17} lg={18}>
                <Space direction="vertical" style={{ width: '100%' }} size="middle">
                    <Typography.Title level={3} style={{ margin: 0 }}>
                        Ask ERP
                    </Typography.Title>
                    {catalogue.data && !catalogue.data.configured ? (
                        <Alert type="warning" showIcon message="Ask ERP is not configured on this server." />
                    ) : null}
                    <TableChips
                        entries={catalogue.data?.data ?? []}
                        onPick={(entry) => setDraft((current) => (current ? `${current} ${entry.label}` : `How many ${entry.label.toLowerCase()} `))}
                    />
                    <div style={{ maxHeight: 'calc(100vh - 360px)', overflowY: 'auto', paddingRight: 8 }}>
                        <List
                            dataSource={messages}
                            locale={{ emptyText: conversationId ? 'No messages yet.' : 'Ask a question below.' }}
                            renderItem={(message) => (
                                <List.Item style={{ display: 'block', border: 0 }}>
                                    {message.role === 'user' ? (
                                        <div style={{ textAlign: 'right' }}>
                                            <Typography.Text
                                                style={{
                                                    background: '#1677ff',
                                                    color: '#fff',
                                                    padding: '6px 12px',
                                                    borderRadius: 12,
                                                    display: 'inline-block',
                                                }}
                                            >
                                                {message.question}
                                            </Typography.Text>
                                        </div>
                                    ) : (
                                        <div style={{ background: 'var(--app-inset)', padding: 12, borderRadius: 8 }}>
                                            <AnswerCard message={message} result={results[message.id] ?? null} />
                                        </div>
                                    )}
                                </List.Item>
                            )}
                        />
                        {ask.isError ? <Alert type="error" showIcon message={apiErrorSummary(ask.error)} /> : null}
                        <div ref={bottomRef} />
                    </div>
                    <Space.Compact style={{ width: '100%' }}>
                        <Input
                            value={draft}
                            maxLength={500}
                            disabled={ask.isPending}
                            placeholder="e.g. how many purchase orders are open per vendor"
                            onChange={(event) => setDraft(event.target.value)}
                            onPressEnter={submit}
                        />
                        <Button type="primary" onClick={submit} loading={ask.isPending} disabled={!draft.trim()}>
                            Ask
                        </Button>
                    </Space.Compact>
                </Space>
            </Col>
        </Row>
    );
}
