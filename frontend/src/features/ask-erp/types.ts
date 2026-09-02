import type { ListParams } from '@/lib/listParams';

export interface ChartSpec {
    type: 'bar' | 'line';
    x: string;
    y: string;
}

/** What one answered question returned. Held in page state only; the SQL is what the server keeps. */
export interface AskResult {
    columns: string[];
    rows: Record<string, unknown>[];
    truncated: boolean;
    chart: ChartSpec | null;
}

export interface AskErpMessage {
    id: number;
    role: 'user' | 'assistant';
    question: string | null;
    sql: string | null;
    answer: string | null;
    tables_used: string[];
    row_count: number | null;
    error: string | null;
    created_at: string | null;
}

export interface AskErpConversationSummary {
    id: number;
    title: string;
    message_count: number;
    updated_at: string | null;
}

export interface AskErpConversation extends AskErpConversationSummary {
    messages: AskErpMessage[];
}

/** One table this login may ask about — the chips above the input. */
export interface CatalogueEntry {
    table: string;
    label: string;
    module: string;
}

export type ConversationListParams = ListParams;
