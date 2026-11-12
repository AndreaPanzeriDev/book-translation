import { BookOutlined } from '@ant-design/icons';
import { Tag } from 'antd';
import { useTranslation } from 'react-i18next';

import { languageLabel } from '@/lib/languages';
import type { BookSummary, ChapterSummary } from '@/types';

interface ReaderSidebarProps {
    book: BookSummary;
    chapters: ChapterSummary[];
    chapterCounts: Record<number, number>;
    currentChapterId: number | null;
    translatedPages: number;
    onSelectChapter: (chapterId: number) => void;
}

export default function ReaderSidebar({
    book,
    chapters,
    chapterCounts,
    currentChapterId,
    translatedPages,
    onSelectChapter,
}: ReaderSidebarProps) {
    const { t } = useTranslation();

    return (
        <div className="flex h-full min-h-0 flex-col bg-parchment-100/75">
            <div className="border-b border-parchment-200 p-5">
                <div className="flex gap-3">
                    <div className="h-24 w-16 shrink-0 overflow-hidden rounded-md border border-parchment-200 bg-gradient-to-br from-leather-600 to-leather-700 shadow-sm">
                        {book.cover_url ? (
                            <img
                                src={book.cover_url}
                                alt={book.title}
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <div className="flex h-full w-full flex-col items-center justify-center gap-2 px-2 text-white/90">
                                <BookOutlined className="text-xl" />
                                <span className="text-center font-reading text-sm leading-tight">
                                    {book.title}
                                </span>
                            </div>
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        <h1 className="font-reading text-lg leading-snug text-ink-900">
                            {book.title}
                        </h1>
                        <p className="mt-1 truncate text-xs text-ink-500">
                            {book.author ?? t('common.authorUnknown')}
                        </p>
                        <Tag className="mt-2" color="gold">
                            {languageLabel(book.target_language)}
                        </Tag>
                        <p className="mt-2 text-[11px] text-ink-500">
                            {t('reader.translatedPages', { count: translatedPages })}
                        </p>
                    </div>
                </div>
            </div>

            <div className="flex items-center justify-between px-5 pb-2 pt-4">
                <h2 className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-500">
                    {t('reader.contents')}
                </h2>
                <Tag className="m-0">{chapters.length}</Tag>
            </div>

            <nav className="min-h-0 flex-1 overflow-y-auto px-3 pb-5">
                <div className="space-y-1">
                    {chapters.map((chapter) => {
                        const translated = chapterCounts[chapter.id] ?? 0;
                        const disabled = translated === 0;
                        const active = chapter.id === currentChapterId;
                        const progress =
                            chapter.pages_count > 0
                                ? Math.min(100, (translated / chapter.pages_count) * 100)
                                : 0;

                        return (
                            <button
                                key={chapter.id}
                                type="button"
                                disabled={disabled}
                                aria-current={active ? 'page' : undefined}
                                onClick={() => onSelectChapter(chapter.id)}
                                className={`w-full rounded-md px-3 py-2 text-left transition ${
                                    active
                                        ? 'bg-leather-100 text-leather-700'
                                        : disabled
                                          ? 'cursor-not-allowed text-ink-500/50'
                                          : 'text-ink-700 hover:bg-parchment-100'
                                }`}
                            >
                                <span className="flex items-baseline justify-between gap-2">
                                    <span className="min-w-0 truncate text-sm">
                                        {chapter.title}
                                    </span>
                                    <span className="shrink-0 text-[10px] tabular-nums text-ink-500">
                                        {translated > 0
                                            ? t('reader.chapterCount', {
                                                  translated,
                                                  total: chapter.pages_count,
                                              })
                                            : t('reader.noTranslatedChapter')}
                                    </span>
                                </span>
                                <span
                                    className={`mt-1.5 block h-1 overflow-hidden rounded-full ${
                                        active ? 'bg-white/70' : 'bg-parchment-200'
                                    }`}
                                >
                                    <span
                                        className={`block h-full rounded-full ${
                                            active ? 'bg-leather-600' : 'bg-leather-600/70'
                                        }`}
                                        style={{ width: `${progress}%` }}
                                    />
                                </span>
                            </button>
                        );
                    })}
                </div>
            </nav>
        </div>
    );
}
