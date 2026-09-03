import { ArrowDownOutlined, HistoryOutlined, PlusOutlined, SendOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Input, Space, Typography } from 'antd';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import { isNearBottom } from '@/features/ask-erp/thread';
import { useDisplayStore } from '@/theme/store';
import { askBubbleBg } from '@/theme/tokens';

/**
 * ASK ERP — a question, its answer, and the evidence under it.
 *
 * THE SHAPE IS A CHAT WINDOW (03-Sep-2026, owner: it does "not look like a
 * modern chat window"). The thread owns the height and scrolls on its own,
 * and the composer is pinned to the floor of the page where a hand rests.
 * Before this the page put a one-line box near the TOP with a page-sized
 * void beneath it, which read as a search bar that had lost its results.
 *
 * WHAT DID NOT CHANGE, and the earlier note here was right about: an answer
 * carries a table, a chart and the SQL it ran, so the ANSWER is not a
 * bubble. Only the question is. A bubble sized for wide content is not a
 * bubble, and this factory's screens name where every figure came from —
 * so the answer keeps the full width and the evidence stays under it. That
 * is the same split every assistant that returns wide content makes.
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
    const [atBottom, setAtBottom] = useState(true);
    // The bubble's fill is contrast-tested per mode, so it is read from the
    // palette rather than written into the stylesheet.
    const themeMode = useDisplayStore((state) => state.mode);
    const bubble = { background: askBubbleBg[themeMode] };
    const queryClient = useQueryClient();
    const threadRef = useRef<HTMLDivElement>(null);
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
    const answered = useMemo(() => messages.filter((message) => message.role === 'assistant'), [messages]);

    const toBottom = useCallback(() => bottomRef.current?.scrollIntoView({ block: 'end' }), []);

    /*
     * Follow the newest turn — but only while the reader is already there.
     * Yanking someone back to the bottom while they are reading an older
     * answer is the thing every chat window gets wrong; the jump button
     * below is what they get instead.
     */
    useEffect(() => {
        if (atBottom) toBottom();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- position is read at the moment a turn arrives, not tracked.
    }, [answered.length, ask.isPending]);

    const submit = () => {
        const question = draft.trim();
        if (question && !ask.isPending) {
            setAtBottom(true);
            ask.mutate(question);
        }
    };

    const askNow = (question: string) => {
        if (ask.isPending) return;
        setDraft(question);
        setAtBottom(true);
        ask.mutate(question);
    };

    const empty = answered.length === 0 && !ask.isPending;

    return (
        <div className="ask-shell">
            <div className="ask-head">
                <Typography.Title level={3} style={{ margin: 0 }}>
                    {thread.data?.title && answered.length > 0 ? thread.data.title : 'Ask ERP'}
                </Typography.Title>
                <Space size={8}>
                    <Button icon={<HistoryOutlined />} onClick={() => setHistoryOpen(true)}>
                        Past questions
                    </Button>
                    <Button icon={<PlusOutlined />} onClick={() => create.mutate()} loading={create.isPending}>
                        New question
                    </Button>
                </Space>
            </div>

            {catalogue.data && !catalogue.data.configured ? (
                <Alert type="warning" showIcon message="Ask ERP is not configured on this server." style={{ marginBottom: 12 }} />
            ) : null}

            <div
                className="ask-thread"
                ref={threadRef}
                onScroll={(event) => setAtBottom(isNearBottom(event.currentTarget))}
            >
                {empty ? (
                    <div className="ask-empty">
                        <div className="ask-empty-mark" />
                        <Typography.Title level={4} style={{ margin: 0 }}>
                            What would you like to know?
                        </Typography.Title>
                        <Suggestions examples={catalogue.data?.examples ?? []} onAsk={askNow} />
                    </div>
                ) : (
                    <div className="ask-turns">
                        {answered.map((message) => (
                            <section key={message.id}>
                                {/* The question is the person's own turn, so it
                                    sits at the end of the row as a bubble. */}
                                <div className="ask-question">
                                    <span style={bubble}>{message.question}</span>
                                </div>
                                <div className="ask-answer" style={{ marginTop: 14 }}>
                                    <div className="ask-answer-by">Ask ERP</div>
                                    <AnswerCard
                                        message={message}
                                        result={results[message.id] ?? null}
                                        conversationId={conversationId}
                                        onRerun={(result) => setResults((current) => ({ ...current, [message.id]: result }))}
                                    />
                                </div>
                            </section>
                        ))}

                        {ask.isPending ? (
                            <section>
                                <div className="ask-question">
                                    <span style={bubble}>{ask.variables}</span>
                                </div>
                                <div className="ask-answer" style={{ marginTop: 14 }}>
                                    <div className="ask-answer-by">Ask ERP</div>
                                    {/* Said where the answer will appear, so the
                                        wait has a place rather than a spinner. */}
                                    <div className="ask-thinking">
                                        <i />
                                        <i />
                                        <i />
                                        <span>Reading the tables</span>
                                    </div>
                                </div>
                            </section>
                        ) : null}

                        <div ref={bottomRef} />

                        {!atBottom ? (
                            <div className="ask-jump">
                                <Button size="small" shape="round" icon={<ArrowDownOutlined />} onClick={toBottom}>
                                    Newest
                                </Button>
                            </div>
                        ) : null}
                    </div>
                )}
            </div>

            {ask.isError ? <Alert type="error" showIcon message={apiErrorSummary(ask.error)} style={{ marginTop: 12 }} /> : null}

            <div className="ask-composer">
                <Input.TextArea
                    autoSize={{ minRows: 1, maxRows: 6 }}
                    placeholder="Ask a question"
                    value={draft}
                    maxLength={500}
                    autoFocus
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => {
                        // Enter sends; Shift+Enter is a new line, which a
                        // one-line Input could not offer at all.
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            submit();
                        }
                    }}
                />
                <div className="ask-composer-hint">
                    <span>Enter to send · Shift+Enter for a new line</span>
                    <Button
                        type="primary"
                        shape="circle"
                        icon={<SendOutlined />}
                        aria-label="Ask"
                        onClick={submit}
                        loading={ask.isPending}
                        disabled={!draft.trim()}
                    />
                </div>
            </div>

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
        </div>
    );
}
