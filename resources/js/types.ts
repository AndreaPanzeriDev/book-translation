import type { PageProps } from '@inertiajs/core';

export interface Flash {
    success?: string | null;
    error?: string | null;
}

export interface SharedProps extends PageProps {
    flash?: Flash;
    appName?: string;
    locale?: string;
    availableLocales?: string[];
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

export interface TranslationCoverageResponse {
    coverage: {
        chapter_id: number;
        translated: number;
    }[];
    missing_page_ids: number[];
}

export interface ReaderVersion {
    account_id: number;
    account_name: string;
    account_active: boolean;
    model: string;
    target_language: string;
    pages_count: number;
}

export interface ReaderPageIndexItem {
    id: number;
    position: number;
    chapter_id: number;
    chapter_position: number;
}

export interface ReaderChapterIndex {
    chapter_id: number;
    translated: number;
}

export interface ReaderIndex {
    pages: ReaderPageIndexItem[];
    chapters: ReaderChapterIndex[];
    translated_pages: number;
}

export interface ReaderPageBlock {
    id: number;
    type: string;
    text: string;
}

export interface ReaderPageContent {
    id: number;
    position: number;
    chapter_id: number;
    chapter_position: number;
    blocks: ReaderPageBlock[];
}

export type ReaderLayout = 'single' | 'spread';

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
    max_parallel_requests: number;
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
    prompt_version: string;
    is_current: boolean;
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

export type TranslationBatchStatus =
    | 'queued'
    | 'running'
    | 'completed'
    | 'failed'
    | 'cancelled';

export interface TranslationBatchSlot {
    slot_key: 'a' | 'b';
    account_id: number;
    model: string;
}

export interface LastUsedTranslationSlot {
    account_id: number;
    model: string;
}

export interface LastUsedTranslationSlots {
    a: LastUsedTranslationSlot | null;
    b: LastUsedTranslationSlot | null;
}

export type LastUsedTranslationModels = Record<number, string>;

export interface TranslationBatchFailure {
    item_id: number;
    page_id: number;
    page_position: number;
    slot_key: 'a' | 'b';
    model: string;
    message: string;
}

export interface TranslationBatch {
    id: number;
    book_id: number;
    book_title: string | null;
    target_language: string;
    status: TranslationBatchStatus;
    total: number;
    processed: number;
    pending: number;
    succeeded: number;
    failed: number;
    cancelled: number;
    in_flight: number;
    current_label: string | null;
    active_page_ids: number[];
    failures: TranslationBatchFailure[];
    can_cancel: boolean;
    can_retry: boolean;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    cancel_requested_at: string | null;
}
