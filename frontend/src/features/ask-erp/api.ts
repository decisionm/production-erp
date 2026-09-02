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

export async function getCatalogue(): Promise<{ data: CatalogueEntry[]; configured: boolean }> {
    const { data } = await api.get<{ data: CatalogueEntry[]; configured: boolean }>('/ask-erp/catalogue');
    return data;
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
