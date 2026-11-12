import { BookOutlined, CloseOutlined, SearchOutlined } from '@ant-design/icons';
import { Button, Empty, Input, Tag, Tooltip } from 'antd';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import ChapterRow from '@/components/ChapterRow';
import { languageLabel } from '@/lib/languages';
import type { BookSummary, ChapterSummary } from '@/types';

interface ReaderSidebarProps {
    book: BookSummary;
    chapters: ChapterSummary[];
    chapterCounts: Record<number, number>;
    currentChapterId: number | null;
    translatedPages: number;
    onSelectChapter: (chapterId: number) => void;
    onClose: () => void;
}

export default function ReaderSidebar({
    book,
    chapters,
    chapterCounts,
    currentChapterId,
    translatedPages,
    onSelectChapter,
    onClose,
}: ReaderSidebarProps) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');

    const filteredChapters = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase();

        if (needle === '') {
            return chapters;
        }

        return chapters.filter(
            (chapter) =>
                chapter.title.toLocaleLowerCase().includes(needle) ||
                String(chapter.position).includes(needle),
        );
    }, [chapters, query]);

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
                        <p className="font-reading text-lg leading-snug text-ink-900">
                            {book.title}
                        </p>
                        <p className="mt-1 truncate text-xs text-ink-500">
                            {book.author ?? t('common.authorUnknown')}
                        </p>
                        <Tag className="mt-2 border-warning-gold/40! bg-warning-gold/10! text-ink-900!">
                            {languageLabel(book.target_language)}
                        </Tag>
                        <p className="mt-2 text-[11px] text-ink-500">
                            {t('reader.translatedPages', { count: translatedPages })}
                        </p>
                    </div>

                    <Tooltip title={t('reader.closeSidebar')}>
                        <Button
                            className="-mr-2 -mt-1 h-11! w-11! shrink-0"
                            type="text"
                            icon={<CloseOutlined />}
                            onClick={onClose}
                            aria-label={t('reader.closeSidebar')}
                        />
                    </Tooltip>
                </div>
            </div>

            <div className="px-5 pb-2 pt-4">
                <div className="flex items-center justify-between">
                    <h2 className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-500">
                        {t('reader.contents')}
                    </h2>
                    <Tag className="m-0">{chapters.length}</Tag>
                </div>
                <Input
                    className="mt-3 min-h-11"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={t('reader.searchChapters')}
                    aria-label={t('reader.searchChapters')}
                    prefix={<SearchOutlined className="text-ink-500" />}
                    allowClear
                />
            </div>

            <nav
                className="min-h-0 flex-1 overflow-y-auto px-3 pb-5"
                aria-label={t('reader.contents')}
            >
                {filteredChapters.length === 0 ? (
                    <Empty
                        className="px-3 py-10"
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description={t('reader.noChapterResults')}
                    />
                ) : (
                    <div className="space-y-0.5">
                        {filteredChapters.map((chapter) => {
                            const translated = chapterCounts[chapter.id] ?? 0;
                            const active = chapter.id === currentChapterId;

                            return (
                                <ChapterRow
                                    key={chapter.id}
                                    position={chapter.position}
                                    title={chapter.title}
                                    active={active}
                                    disabled={translated === 0}
                                    meta={
                                        translated > 0
                                            ? t('reader.chapterCount', {
                                                  translated,
                                                  total: chapter.pages_count,
                                              })
                                            : t('reader.noTranslatedChapter')
                                    }
                                    tracks={[
                                        {
                                            key: 'translated',
                                            label: t('reader.coverageAria', {
                                                translated,
                                                total: chapter.pages_count,
                                            }),
                                            translated,
                                            total: chapter.pages_count,
                                        },
                                    ]}
                                    onSelect={() => onSelectChapter(chapter.id)}
                                />
                            );
                        })}
                    </div>
                )}
            </nav>
        </div>
    );
}
