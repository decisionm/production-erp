import { api } from '@/lib/api';
import { compactParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import type {
    AskErpConversation,
    AskErpConversationSummary,
    AskErpMessage,
    AskResult,
    CatalogueEntry,
    ConversationListParams,
} from './types';

export async function getCatalogue(): Promise<{ data: CatalogueEntry[]; examples: string[]; configured: boolean }> {
    const { data } = await api.get<{ data: CatalogueEntry[]; examples?: string[]; configured: boolean }>(
        '/ask-erp/catalogue',
    );

    // `examples` is optional on the wire so a page served against an older
    // backend degrades to the table list rather than crashing on undefined.
    return { ...data, examples: data.examples ?? [] };
}

/** ONE PAGE of this login's conversations, searched and paged by the SERVER. */
export async function listConversations(params: ConversationListParams = {}): Promise<Paginated<AskErpConversationSummary>> {
    const { data } = await api.get<Paginated<AskErpConversationSummary>>('/ask-erp/conversations', {
        params: compactParams(params),
    });
    return data;
}

export async function createConversation(title?: string): Promise<AskErpConversation> {
    const { data } = await api.post<{ data: AskErpConversation }>('/ask-erp/conversations', { title });
    return data.data;
}

export async function getConversation(id: number): Promise<AskErpConversation> {
    const { data } = await api.get<{ data: AskErpConversation }>(`/ask-erp/conversations/${id}`);
    return data.data;
}

export async function askQuestion(id: number, question: string): Promise<{ message: AskErpMessage; result: AskResult }> {
    const { data } = await api.post<{ message: AskErpMessage; result: AskResult }>(`/ask-erp/conversations/${id}/ask`, {
        question,
    });
    return data;
}

/**
 * Run a stored answer's query again.
 *
 * History keeps the SQL and not the rows, so a reopened conversation shows a
 * sentence over an empty space until this fills it back in. The server
 * re-guards the stored SQL against the CURRENT reader's permissions rather
 * than replaying it, so this can legitimately be refused.
 */
export async function rerunMessage(conversationId: number, messageId: number): Promise<AskResult> {
    const { data } = await api.post<{ result: AskResult }>(
        `/ask-erp/conversations/${conversationId}/messages/${messageId}/rerun`,
    );
    return data.result;
}

/** Rename a conversation. Titles start as the first question, which is often not what it became. */
export async function renameConversation(id: number, title: string): Promise<AskErpConversation> {
    const { data } = await api.patch<{ data: AskErpConversation }>(`/ask-erp/conversations/${id}`, { title });
    return data.data;
}

/** Delete a conversation and its turns. A reader's own scratch space, not a record anything points at. */
export async function deleteConversation(id: number): Promise<void> {
    await api.delete(`/ask-erp/conversations/${id}`);
}
