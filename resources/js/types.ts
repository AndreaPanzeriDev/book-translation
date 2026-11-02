import type { PageProps } from '@inertiajs/core';

export interface Flash {
    success?: string | null;
    error?: string | null;
}

export interface SharedProps extends PageProps {
    flash?: Flash;
    appName?: string;
}

export interface BookSummary {
    id: number;
    title: string;
    author: string | null;
    language: string | null;
    cover_url: string | null;
    original_filename: string;
    page_char_limit: number;
    target_language: string;
    pages_count: number;
    chapters_count: number;
    created_at: string | null;
}

export interface ChapterSummary {
    id: number;
    position: number;
    title: string;
    pages_count: number;
    first_page_position: number | null;
}

export interface PageIndexItem {
    id: number;
    chapter_id: number;
    position: number;
    chapter_position: number;
    char_count: number;
}

export interface AiModel {
    id: number;
    model_id: string;
    label: string | null;
    context_length: number | null;
}

export type RequestMode = 'serial' | 'parallel';

export interface AiAccount {
    id: number;
    name: string;
    provider: string;
    base_url: string;
    default_model: string | null;
    is_active: boolean;
    request_mode: RequestMode;
    has_api_key: boolean;
    models: AiModel[];
}

export interface Block {
    id: number;
    position: number;
    type: string;
    text: string;
}

export interface TranslatedBlock {
    block_id: number;
    text: string;
}

export interface Translation {
    id: number;
    page_id: number;
    ai_account_id: number | null;
    model: string;
    target_language: string;
    status: 'running' | 'completed' | 'failed';
    translated_blocks: TranslatedBlock[] | null;
    error: string | null;
    total_tokens: number | null;
    latency_ms: number | null;
    created_at: string | null;
}

export interface PageDetail {
    id: number;
    chapter_id: number;
    position: number;
    chapter_position: number;
    char_count: number;
    source_hash: string;
    blocks: Block[];
    translations: Translation[];
}
