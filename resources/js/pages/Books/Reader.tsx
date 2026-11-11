import {
    AlignLeftOutlined,
    ArrowLeftOutlined,
    BookOutlined,
    LeftOutlined,
    MenuOutlined,
    RightOutlined,
} from '@ant-design/icons';
import { router } from '@inertiajs/react';
import { Alert, Button, Drawer, Empty, Segmented, Select, Spin, Tooltip } from 'antd';
import axios from 'axios';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import ReaderPageView from '@/components/ReaderPageView';
import ReaderSidebar from '@/components/ReaderSidebar';
import { languageLabel } from '@/lib/languages';
import {
    buildSpreads,
    nearestPageId,
    scopePages,
    versionKey,
} from '@/lib/reader';
import { useMediaQuery } from '@/lib/useMediaQuery';
import type {
    BookSummary,
    ChapterSummary,
    ReaderIndex,
    ReaderLayout,
    ReaderPageContent,
    ReaderPageIndexItem,
    ReaderScope,
    ReaderVersion,
} from '@/types';

const LAYOUT_KEY = 'reader:layout';

interface ReaderProps {
    book: BookSummary;
    chapters: ChapterSummary[];
    versions: ReaderVersion[];
    selectedVersion: ReaderVersion | null;
    readerIndex: ReaderIndex;
    initialPageId: number | null;
    initialChapterId: number | null;
    initialPages: ReaderPageContent[];
    initialScope: ReaderScope;
}

interface VersionParams {
    account_id: number;
    model: string;
    target_language: string;
}

function readStoredLayout(): ReaderLayout {
    if (typeof window === 'undefined') {
        return 'spread';
    }

    try {
        const stored = window.localStorage.getItem(LAYOUT_KEY);

        return stored === 'single' ? 'single' : 'spread';
    } catch {
        return 'spread';
    }
}

function versionParams(version: ReaderVersion): VersionParams {
    return {
        account_id: version.account_id,
        model: version.model,
        target_language: version.target_language,
    };
}

export default function Reader({
    book,
    chapters,
    versions,
    selectedVersion: initialVersion,
    readerIndex,
    initialPageId,
    initialPages,
    initialScope,
}: ReaderProps) {
    const { t } = useTranslation();
    const wideScreen = useMediaQuery('(min-width: 1280px)');

    const [selectedVersion, setSelectedVersion] = useState<ReaderVersion | null>(initialVersion);
    const [index, setIndex] = useState<ReaderIndex>(readerIndex);
    const [currentPageId, setCurrentPageId] = useState<number | null>(initialPageId);
    const [scope, setScope] = useState<ReaderScope>(initialScope);
    const [layout, setLayout] = useState<ReaderLayout>(readStoredLayout);
    const [pages, setPages] = useState<Record<number, ReaderPageContent>>(() => {
        const loaded: Record<number, ReaderPageContent> = {};

        for (const page of initialPages) {
            loaded[page.id] = page;
        }

        return loaded;
    });
    const [indexLoading, setIndexLoading] = useState(false);
    const [contentLoading, setContentLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [retryToken, setRetryToken] = useState(0);
    const requestedPages = useRef<Set<number>>(new Set());

    const currentPage = useMemo(
        () => index.pages.find((page) => page.id === currentPageId) ?? null,
        [index.pages, currentPageId],
    );

    const currentChapterId = currentPage?.chapter_id ?? null;

    const chapterCounts = useMemo(() => {
        const counts: Record<number, number> = {};

        for (const chapter of index.chapters) {
            counts[chapter.chapter_id] = chapter.translated;
        }

        return counts;
    }, [index.chapters]);

    const versionOptions = useMemo(
        () =>
            versions.map((version) => ({
                value: versionKey(version),
                label: `${version.account_name} · ${version.model} · ${languageLabel(
                    version.target_language,
                )}${version.account_active ? '' : ` (${t('reader.inactiveAccount')})`}`,
            })),
        [t, versions],
    );

    const scopedPages = useMemo(
        () => scopePages(index.pages, currentPage, scope),
        [currentPage, index.pages, scope],
    );

    const spreads = useMemo(() => buildSpreads(scopedPages), [scopedPages]);

    const spreadIndex = useMemo(
        () =>
            currentPage
                ? spreads.findIndex((spread) =>
                      spread.some((page) => page.id === currentPage.id),
                  )
                : -1,
        [currentPage, spreads],
    );

    const effectiveSpread = layout === 'spread' && wideScreen && scope !== 'page';
    const activeLayout: ReaderLayout = effectiveSpread ? 'spread' : 'single';

    const visiblePages = useMemo(() => {
        if (effectiveSpread && spreadIndex >= 0) {
            return spreads[spreadIndex] ?? [];
        }

        return currentPage ? [currentPage] : [];
    }, [currentPage, effectiveSpread, spreadIndex, spreads]);

    const visibleKey = visiblePages.map((page) => page.id).join(',');

    const scopedIndex = scopedPages.findIndex((page) => page.id === currentPageId);

    const previousPageId = effectiveSpread
        ? spreadIndex > 0
            ? spreads[spreadIndex - 1]?.[0]?.id
            : undefined
        : scopedIndex > 0
          ? scopedPages[scopedIndex - 1]?.id
          : undefined;

    const nextPageId = effectiveSpread
        ? spreadIndex >= 0 && spreadIndex < spreads.length - 1
            ? spreads[spreadIndex + 1]?.[0]?.id
            : undefined
        : scopedIndex >= 0 && scopedIndex < scopedPages.length - 1
          ? scopedPages[scopedIndex + 1]?.id
          : undefined;

    const positions = visiblePages.map((page) => page.position);
    const firstPosition = positions.length > 0 ? Math.min(...positions) : null;
    const lastPosition = positions.length > 0 ? Math.max(...positions) : null;
    const locationLabel =
        firstPosition !== null && lastPosition !== null
            ? t('reader.location', {
                  range:
                      firstPosition === lastPosition
                          ? String(firstPosition)
                          : `${firstPosition}–${lastPosition}`,
                  total: book.pages_count,
              })
            : t('reader.translatedPages', { count: index.translated_pages });
    const progress =
        lastPosition !== null && book.pages_count > 0
            ? Math.min(100, (lastPosition / book.pages_count) * 100)
            : 0;

    const spreadUnavailable = !wideScreen || scope === 'page';

    useEffect(() => {
        if (!selectedVersion || visiblePages.length === 0) {
            return;
        }

        const missing = visiblePages
            .map((page) => page.id)
            .filter((id) => !pages[id] && !requestedPages.current.has(id));

        if (missing.length === 0) {
            return;
        }

        for (const id of missing) {
            requestedPages.current.add(id);
        }

        const controller = new AbortController();
        setContentLoading(true);
        setError(null);

        axios
            .get<{ pages: ReaderPageContent[] }>(`/books/${book.id}/reader/pages`, {
                params: {
                    ...versionParams(selectedVersion),
                    page_ids: missing,
                },
                signal: controller.signal,
            })
            .then(({ data }) => {
                setPages((previous) => {
                    const next = { ...previous };

                    for (const page of data.pages) {
                        next[page.id] = page;
                    }

                    return next;
                });
            })
            .catch((requestError) => {
                if (axios.isCancel(requestError)) {
                    return;
                }

                for (const id of missing) {
                    requestedPages.current.delete(id);
                }

                setError(t('reader.loadError'));
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setContentLoading(false);
                }
            });

        return () => controller.abort();
    }, [book.id, pages, retryToken, selectedVersion, t, visibleKey]);

    useEffect(() => {
        const handler = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (target?.closest('input, textarea, select, [contenteditable="true"]')) {
                return;
            }

            if (event.key === 'ArrowLeft' && previousPageId !== undefined) {
                event.preventDefault();
                setCurrentPageId(previousPageId);
            }

            if (event.key === 'ArrowRight' && nextPageId !== undefined) {
                event.preventDefault();
                setCurrentPageId(nextPageId);
            }
        };

        window.addEventListener('keydown', handler);

        return () => window.removeEventListener('keydown', handler);
    }, [nextPageId, previousPageId]);

    useEffect(() => {
        if (!selectedVersion) {
            return;
        }

        const params = new URLSearchParams({
            account_id: String(selectedVersion.account_id),
            model: selectedVersion.model,
            target_language: selectedVersion.target_language,
            scope,
        });

        if (currentPageId !== null) {
            params.set('page', String(currentPageId));
        }

        if (currentChapterId !== null) {
            params.set('chapter', String(currentChapterId));
        }

        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}?${params.toString()}`,
        );
    }, [currentChapterId, currentPageId, scope, selectedVersion]);

    const switchVersion = async (key: string) => {
        const nextVersion = versions.find((version) => versionKey(version) === key);

        if (
            !nextVersion ||
            (selectedVersion && versionKey(selectedVersion) === versionKey(nextVersion))
        ) {
            return;
        }

        const previous = currentPage;
        setIndexLoading(true);
        setError(null);

        try {
            const { data } = await axios.get<{ index: ReaderIndex }>(
                `/books/${book.id}/reader/index`,
                { params: versionParams(nextVersion) },
            );

            setSelectedVersion(nextVersion);
            setIndex(data.index);
            setPages({});
            requestedPages.current.clear();
            setCurrentPageId(nearestPageId(data.index.pages, previous));
        } catch {
            setError(t('reader.loadError'));
        } finally {
            setIndexLoading(false);
        }
    };

    const chooseLayout = (nextLayout: ReaderLayout) => {
        setLayout(nextLayout);

        try {
            window.localStorage.setItem(LAYOUT_KEY, nextLayout);
        } catch {
            // La preferenza è solo un miglioramento: se il browser la blocca
            // il lettore continua a funzionare con il valore in memoria.
        }
    };

    const selectChapter = (chapterId: number) => {
        const firstPage = index.pages.find((page) => page.chapter_id === chapterId);

        if (!firstPage) {
            return;
        }

        setCurrentPageId(firstPage.id);

        if (scope === 'page') {
            setScope('chapter');
        }

        setSidebarOpen(false);
    };

    const retry = () => {
        requestedPages.current.clear();
        setPages({});
        setError(null);
        setRetryToken((token) => token + 1);
    };

    const layoutControls = (
        <div className="flex shrink-0 items-center gap-1">
            <Tooltip title={t('reader.layoutSingle')}>
                <Button
                    size="small"
                    type={activeLayout === 'single' ? 'primary' : 'text'}
                    icon={<AlignLeftOutlined />}
                    onClick={() => chooseLayout('single')}
                    aria-label={t('reader.layoutSingle')}
                    aria-pressed={activeLayout === 'single'}
                />
            </Tooltip>
            <Tooltip
                title={
                    spreadUnavailable
                        ? t('reader.layoutUnavailable')
                        : t('reader.layoutSpread')
                }
            >
                <span>
                    <Button
                        size="small"
                        type={activeLayout === 'spread' ? 'primary' : 'text'}
                        icon={<BookOutlined />}
                        onClick={() => chooseLayout('spread')}
                        disabled={spreadUnavailable}
                        aria-label={t('reader.layoutSpread')}
                        aria-pressed={activeLayout === 'spread'}
                    />
                </span>
            </Tooltip>
        </div>
    );

    const scopeControl = (
        <Segmented
            value={scope}
            size="small"
            block
            options={[
                { value: 'page', label: t('reader.scopePage') },
                { value: 'chapter', label: t('reader.scopeChapter') },
                { value: 'book', label: t('reader.scopeBook') },
            ]}
            onChange={(value) => setScope(value as ReaderScope)}
        />
    );

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-parchment-50 text-ink-900">
            <header className="shrink-0 border-b border-parchment-200 bg-white/90 backdrop-blur">
                <div className="flex items-center gap-2 px-3 pt-2 sm:px-4">
                    <Tooltip title={t('reader.backAria')}>
                        <Button
                            type="text"
                            icon={<ArrowLeftOutlined />}
                            onClick={() => router.visit(`/books/${book.id}`)}
                            aria-label={t('reader.backAria')}
                        />
                    </Tooltip>
                    <Button
                        className="lg:hidden"
                        type="text"
                        icon={<MenuOutlined />}
                        onClick={() => setSidebarOpen(true)}
                        aria-label={t('reader.menuAria')}
                    />

                    <div className="min-w-0 flex-1">
                        <h1 className="truncate font-reading text-base leading-tight text-ink-900 sm:text-lg">
                            {book.title}
                        </h1>
                        <p className="truncate text-[11px] text-ink-500">
                            {book.author ?? t('common.authorUnknown')}
                        </p>
                    </div>

                    <div className="hidden w-[320px] lg:block">{scopeControl}</div>

                    <Select
                        className="w-[150px] min-w-[120px] sm:w-[280px]"
                        value={selectedVersion ? versionKey(selectedVersion) : undefined}
                        options={versionOptions}
                        onChange={switchVersion}
                        placeholder={t('reader.versionPlaceholder')}
                        aria-label={t('reader.version')}
                        loading={indexLoading}
                        disabled={versions.length === 0}
                        showSearch
                        optionFilterProp="label"
                        popupMatchSelectWidth={false}
                    />

                    <div className="hidden lg:block">{layoutControls}</div>
                </div>

                <div className="flex items-center gap-2 px-3 pb-2 pt-2 lg:hidden">
                    <div className="min-w-0 flex-1">{scopeControl}</div>
                    {layoutControls}
                </div>
            </header>

            <div className="flex min-h-0 flex-1">
                <aside className="hidden w-[300px] shrink-0 border-r border-parchment-200 lg:block xl:w-[320px]">
                    <ReaderSidebar
                        book={book}
                        chapters={chapters}
                        chapterCounts={chapterCounts}
                        currentChapterId={currentChapterId}
                        translatedPages={index.translated_pages}
                        onSelectChapter={selectChapter}
                    />
                </aside>

                <main className="min-h-0 min-w-0 flex-1 overflow-y-auto">
                    {error && (
                        <Alert
                            className="m-3"
                            type="error"
                            showIcon
                            title={error}
                            action={
                                <Button size="small" onClick={retry}>
                                    {t('reader.retry')}
                                </Button>
                            }
                        />
                    )}

                    {indexLoading ? (
                        <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-ink-500">
                            <Spin size="large" />
                            <span>{t('reader.loading')}</span>
                        </div>
                    ) : index.pages.length === 0 ? (
                        <div className="flex h-full items-center justify-center p-6">
                            <Empty
                                description={
                                    <div className="max-w-sm">
                                        <p className="font-reading text-lg text-ink-900">
                                            {versions.length === 0
                                                ? t('reader.emptyTitle')
                                                : t('reader.emptyVersionTitle')}
                                        </p>
                                        <p className="mt-1 text-sm text-ink-500">
                                            {versions.length === 0
                                                ? t('reader.emptyDescription')
                                                : t('reader.emptyVersionDescription')}
                                        </p>
                                    </div>
                                }
                            >
                                <Button
                                    type="primary"
                                    onClick={() => router.visit(`/books/${book.id}`)}
                                >
                                    {t('reader.backToStudio')}
                                </Button>
                            </Empty>
                        </div>
                    ) : (
                        <div className="mx-auto w-full max-w-[1240px] px-3 py-6 sm:px-6 lg:px-10">
                            <div
                                className={
                                    effectiveSpread
                                        ? 'reader-spread grid grid-cols-2 divide-x divide-parchment-200'
                                        : 'mx-auto max-w-[46rem]'
                                }
                            >
                                {visiblePages.map((pageInfo: ReaderPageIndexItem) => {
                                    const page = pages[pageInfo.id];

                                    return (
                                        <div
                                            key={pageInfo.id}
                                            className={
                                                effectiveSpread
                                                    ? 'min-h-[62vh] px-5 py-8 sm:px-8'
                                                    : 'py-2'
                                            }
                                        >
                                            {page ? (
                                                <ReaderPageView
                                                    page={page}
                                                    locale={selectedVersion?.target_language}
                                                    spread={effectiveSpread}
                                                />
                                            ) : (
                                                <div className="flex min-h-[240px] items-center justify-center">
                                                    {contentLoading ? (
                                                        <Spin />
                                                    ) : (
                                                        <Empty
                                                            description={t('reader.emptyPage')}
                                                        />
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </main>
            </div>

            <footer className="flex h-12 shrink-0 items-center gap-3 border-t border-parchment-200 bg-white/90 px-3 sm:px-4">
                <span className="hidden shrink-0 text-[11px] text-ink-500 sm:inline">
                    {t('reader.onlyTranslated')}
                </span>

                <div className="flex min-w-0 flex-1 items-center gap-3">
                    <div className="h-1 min-w-[40px] flex-1 overflow-hidden rounded-full bg-parchment-200">
                        <div
                            className="h-full rounded-full bg-leather-600/70 transition-[width] duration-300"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                    <span className="whitespace-nowrap text-[11px] text-ink-500">
                        {locationLabel}
                    </span>
                </div>

                <Button
                    size="small"
                    icon={<LeftOutlined />}
                    onClick={() =>
                        previousPageId !== undefined && setCurrentPageId(previousPageId)
                    }
                    disabled={previousPageId === undefined}
                    aria-label={t('reader.previous')}
                />
                <Button
                    size="small"
                    icon={<RightOutlined />}
                    onClick={() => nextPageId !== undefined && setCurrentPageId(nextPageId)}
                    disabled={nextPageId === undefined}
                    aria-label={t('reader.next')}
                />
            </footer>

            <Drawer
                open={sidebarOpen}
                onClose={() => setSidebarOpen(false)}
                placement="left"
                width={320}
                closable={false}
                styles={{ body: { padding: 0 } }}
            >
                <ReaderSidebar
                    book={book}
                    chapters={chapters}
                    chapterCounts={chapterCounts}
                    currentChapterId={currentChapterId}
                    translatedPages={index.translated_pages}
                    onSelectChapter={selectChapter}
                />
            </Drawer>
        </div>
    );
}
