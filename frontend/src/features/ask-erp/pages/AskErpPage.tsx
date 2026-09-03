import { HistoryOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Input, Space, Typography } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    askQuestion,
    createConversation,
    deleteConversation,
    getCatalogue,
    getConversation,
    listConversations,
    renameConversation,
} from '@/features/ask-erp/api';
import AnswerCard from '@/features/ask-erp/components/AnswerCard';
import HistoryDrawer from '@/features/ask-erp/components/HistoryDrawer';
import Suggestions from '@/features/ask-erp/components/Suggestions';
import type { AskErpMessage, AskResult } from '@/features/ask-erp/types';
import { apiErrorSummary } from '@/lib/apiError';

/**
 * ASK ERP — a question, its answer, and the evidence under it.
 *
 * NOT A CHAT, and the layout says so. Bubbles imply somebody on the other
 * end; there is nobody. Each turn is a RESULT SLIP: the question as its
 * heading, the answer beneath it, the rows beneath that, and last the tables
 * it read and the query it ran. That order is this factory's own habit — its
 * screens name where every figure came from — and it is the order a person
 * checks a number in.
 *
 * One column. Past questions moved into a drawer, because a permanent list
 * took a quarter of the width and, on a phone, sat ABOVE the box you came to
 * type in.
 *
 * The page computes nothing. The server decides from the login's permissions
 * which tables may be looked at, and every figure here came back from it.
 */
export default function AskErpPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const conversationId = Number(searchParams.get('conversation')) || null;
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [draft, setDraft] = useState('');
    const [historyOpen, setHistoryOpen] = useState(false);
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
    const refreshLists = () => queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversations'] });

    const create = useMutation({
        mutationFn: () => createConversation(),
        onSuccess: (conversation) => {
            refreshLists();
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
            refreshLists();
        },
        onError: () => {
            if (conversationId) queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversation', conversationId] });
        },
    });

    const rename = useMutation({
        mutationFn: ({ id, title }: { id: number; title: string }) => renameConversation(id, title),
        onSuccess: (conversation) => {
            refreshLists();
            queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversation', conversation.id] });
        },
    });

    const remove = useMutation({
        mutationFn: (id: number) => deleteConversation(id),
        onSuccess: (_data, id) => {
            refreshLists();
            // The open thread just stopped existing; go back to the empty
            // state rather than leaving a dead conversation on screen.
            if (id === conversationId) setSearchParams({});
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

    const askNow = (question: string) => {
        if (ask.isPending) return;
        setDraft(question);
        ask.mutate(question);
    };

    const empty = messages.length === 0;

    return (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
            <Space style={{ width: '100%', justifyContent: 'space-between' }} align="center">
                <Typography.Title level={3} style={{ margin: 0 }}>
                    {thread.data?.title && !empty ? thread.data.title : 'Ask ERP'}
                </Typography.Title>
                <Space size={8}>
                    <Button icon={<HistoryOutlined />} onClick={() => setHistoryOpen(true)}>
                        Past questions
                    </Button>
                    <Button icon={<PlusOutlined />} onClick={() => create.mutate()} loading={create.isPending}>
                        New question
                    </Button>
                </Space>
            </Space>

            {catalogue.data && !catalogue.data.configured ? (
                <Alert type="warning" showIcon message="Ask ERP is not configured on this server." />
            ) : null}

            {empty ? (
                <Suggestions examples={catalogue.data?.examples ?? []} onAsk={askNow} />
            ) : null}

            <div style={{ maxHeight: 'calc(100vh - 320px)', overflowY: 'auto', paddingRight: 8 }}>
                <Space direction="vertical" size={24} style={{ width: '100%' }}>
                    {messages
                        .filter((message) => message.role === 'assistant')
                        .map((message) => (
                            <section key={message.id}>
                                {/* The question IS the heading of its slip — not
                                    a bubble addressed to anyone. */}
                                <Typography.Title level={5} style={{ margin: '0 0 8px' }}>
                                    {message.question}
                                </Typography.Title>
                                <AnswerCard
                                    message={message}
                                    result={results[message.id] ?? null}
                                    conversationId={conversationId}
                                    onRerun={(result) => setResults((current) => ({ ...current, [message.id]: result }))}
                                />
                            </section>
                        ))}
                    <div ref={bottomRef} />
                </Space>
            </div>

            {ask.isError ? <Alert type="error" showIcon message={apiErrorSummary(ask.error)} /> : null}

            <Space.Compact style={{ width: '100%' }}>
                <Input
                    size="large"
                    placeholder="Ask a question"
                    value={draft}
                    maxLength={500}
                    onChange={(event) => setDraft(event.target.value)}
                    onPressEnter={submit}
                />
                <Button size="large" type="primary" onClick={submit} loading={ask.isPending} disabled={!draft.trim()}>
                    Ask
                </Button>
            </Space.Compact>

            <HistoryDrawer
                open={historyOpen}
                onClose={() => setHistoryOpen(false)}
                conversations={conversations.data?.data ?? []}
                loading={conversations.isFetching}
                activeId={conversationId}
                page={page}
                perPage={conversations.data?.meta.per_page ?? 20}
                total={conversations.data?.meta.total ?? 0}
                onPage={setPage}
                onSearch={(term) => {
                    setQ(term);
                    setPage(1);
                }}
                onOpen={(id) => {
                    open(id);
                    setHistoryOpen(false);
                }}
                onRename={(id, title) => rename.mutate({ id, title })}
                onDelete={(id) => remove.mutate(id)}
            />
        </Space>
    );
}
