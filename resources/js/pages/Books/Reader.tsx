import {
    AlignLeftOutlined,
    ArrowLeftOutlined,
    BookOutlined,
    LeftOutlined,
    MenuOutlined,
    RightOutlined,
} from '@ant-design/icons';
import { router } from '@inertiajs/react';
import { Alert, Button, Drawer, Empty, Select, Spin, Tooltip } from 'antd';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import ReaderCanvas from '@/components/ReaderCanvas';
import ReaderSidebar from '@/components/ReaderSidebar';
import { languageLabel } from '@/lib/languages';
import { buildSpreads, nearestPageId, versionKey } from '@/lib/reader';
import { subscribeReaderKeyboard } from '@/lib/readerKeyboard';
import { useMediaQuery } from '@/lib/useMediaQuery';
import type {
    BookSummary,
    ChapterSummary,
    ReaderIndex,
    ReaderLayout,
    ReaderPageContent,
    ReaderPageIndexItem,
    ReaderVersion,
} from '@/types';

const LAYOUT_KEY = 'reader:layout';
const SIDEBAR_KEY = 'reader:sidebar';

interface ReaderProps {
    book: BookSummary;
    chapters: ChapterSummary[];
    versions: ReaderVersion[];
    selectedVersion: ReaderVersion | null;
    readerIndex: ReaderIndex;
    initialPageId: number | null;
    initialChapterId: number | null;
    initialPages: ReaderPageContent[];
}

interface VersionParams {
    account_id: number;
    model: string;
    target_language: string;
}

type PageEdge = 'start' | 'last';

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

function readStoredSidebar(): boolean {
    if (typeof window === 'undefined') {
        return true;
    }

    try {
        return window.localStorage.getItem(SIDEBAR_KEY) !== 'closed';
    } catch {
        return true;
    }
}

function persistSidebar(open: boolean): void {
    try {
        window.localStorage.setItem(SIDEBAR_KEY, open ? 'open' : 'closed');
    } catch {
        // La preferenza è solo un miglioramento: se il browser la blocca
        // il lettore continua a funzionare con il valore in memoria.
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
}: ReaderProps) {
    const { t } = useTranslation();
    const wideScreen = useMediaQuery('(min-width: 1280px)');
    const largeScreen = useMediaQuery('(min-width: 1024px)');

    const [selectedVersion, setSelectedVersion] = useState<ReaderVersion | null>(initialVersion);
    const [index, setIndex] = useState<ReaderIndex>(readerIndex);
    const [currentPageId, setCurrentPageId] = useState<number | null>(initialPageId);
    const [layout, setLayout] = useState<ReaderLayout>(readStoredLayout);
    const [sidebarVisible, setSidebarVisible] = useState(readStoredSidebar);
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
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [retryToken, setRetryToken] = useState(0);
    const [screenIndex, setScreenIndex] = useState(0);
    const [screenCount, setScreenCount] = useState(0);
    const requestedPages = useRef<Set<number>>(new Set());
    const screenCountRef = useRef(0);
    const pendingLastScreen = useRef(false);

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

    const spreads = useMemo(() => buildSpreads(index.pages), [index.pages]);

    const spreadIndex = useMemo(
        () =>
            currentPage
                ? spreads.findIndex((spread) =>
                      spread.some((page) => page.id === currentPage.id),
                  )
                : -1,
        [currentPage, spreads],
    );

    const pageIndex = index.pages.findIndex((page) => page.id === currentPageId);

    const effectiveSpread = layout === 'spread' && wideScreen;
    const activeLayout: ReaderLayout = effectiveSpread ? 'spread' : 'single';

    const visibleIndexPages = useMemo(() => {
        if (effectiveSpread && spreadIndex >= 0) {
            return spreads[spreadIndex] ?? [];
        }

        return currentPage ? [currentPage] : [];
    }, [currentPage, effectiveSpread, spreadIndex, spreads]);

    const visibleKey = visibleIndexPages.map((page) => page.id).join(',');

    const visiblePageContents = useMemo(
        () =>
            visibleIndexPages
                .map((page) => pages[page.id])
                .filter((page): page is ReaderPageContent => page !== undefined),
        [pages, visibleIndexPages],
    );

    const visibleReady =
        visibleIndexPages.length > 0 &&
        visiblePageContents.length === visibleIndexPages.length;

    const previousTarget = useMemo<ReaderPageIndexItem | null>(() => {
        if (effectiveSpread) {
            return spreadIndex > 0 ? (spreads[spreadIndex - 1]?.[0] ?? null) : null;
        }

        return pageIndex > 0 ? (index.pages[pageIndex - 1] ?? null) : null;
    }, [effectiveSpread, index.pages, pageIndex, spreadIndex, spreads]);

    const nextTarget = useMemo<ReaderPageIndexItem | null>(() => {
        if (effectiveSpread) {
            return spreadIndex >= 0 && spreadIndex < spreads.length - 1
                ? (spreads[spreadIndex + 1]?.[0] ?? null)
                : null;
        }

        return pageIndex >= 0 && pageIndex < index.pages.length - 1
            ? (index.pages[pageIndex + 1] ?? null)
            : null;
    }, [effectiveSpread, index.pages, pageIndex, spreadIndex, spreads]);

    const positions = visibleIndexPages.map((page) => page.position);
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

    const spreadUnavailable = !wideScreen;
    const currentScreen = screenCount > 0 ? Math.min(screenIndex, screenCount - 1) + 1 : 0;

    const resetScreen = useCallback(() => {
        pendingLastScreen.current = false;
        setScreenIndex(0);
    }, []);

    const moveToPage = useCallback((target: ReaderPageIndexItem, edge: PageEdge = 'start') => {
        screenCountRef.current = 0;
        pendingLastScreen.current = edge === 'last';
        setScreenCount(0);
        setScreenIndex(0);
        setCurrentPageId(target.id);
    }, []);

    const handleScreenCountChange = useCallback((count: number) => {
        const previousCount = screenCountRef.current;

        screenCountRef.current = count;
        setScreenCount(count);

        if (count <= 0) {
            return;
        }

        setScreenIndex((current) => {
            if (pendingLastScreen.current) {
                pendingLastScreen.current = false;

                return count - 1;
            }

            if (previousCount > 1 && previousCount !== count) {
                const ratio = current / (previousCount - 1);

                return Math.max(0, Math.min(count - 1, Math.round(ratio * (count - 1))));
            }

            return Math.min(current, count - 1);
        });
    }, []);

    const canGoPrevious =
        visibleReady && screenCount > 0 && (screenIndex > 0 || previousTarget !== null);
    const canGoNext =
        visibleReady && screenCount > 0 && (screenIndex < screenCount - 1 || nextTarget !== null);

    const goPrevious = useCallback(() => {
        if (!visibleReady || screenCount <= 0) {
            return;
        }

        if (screenIndex > 0) {
            setScreenIndex((current) => Math.max(0, current - 1));

            return;
        }

        if (previousTarget) {
            moveToPage(previousTarget, 'last');
        }
    }, [moveToPage, previousTarget, screenCount, screenIndex, visibleReady]);

    const goNext = useCallback(() => {
        if (!visibleReady || screenCount <= 0) {
            return;
        }

        if (screenIndex < screenCount - 1) {
            setScreenIndex((current) => Math.min(screenCount - 1, current + 1));

            return;
        }

        if (nextTarget) {
            moveToPage(nextTarget);
        }
    }, [moveToPage, nextTarget, screenCount, screenIndex, visibleReady]);

    const navigation = useRef({
        canGoPrevious,
        canGoNext,
        goPrevious,
        goNext,
        drawerOpen,
    });

    navigation.current = {
        canGoPrevious,
        canGoNext,
        goPrevious,
        goNext,
        drawerOpen,
    };

    useEffect(() => {
        if (!selectedVersion || visibleIndexPages.length === 0) {
            return;
        }

        const missing = visibleIndexPages
            .map((page) => page.id)
            .filter((id) => !pages[id] && !requestedPages.current.has(id));

        if (missing.length === 0) {
            return;
        }

        for (const id of missing) {
            requestedPages.current.add(id);
        }

        const controller = new AbortController();
        let settled = false;
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
                settled = true;
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

                settled = true;

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

        return () => {
            controller.abort();

            if (!settled) {
                for (const id of missing) {
                    requestedPages.current.delete(id);
                }
            }
        };
    }, [book.id, pages, retryToken, selectedVersion, t, visibleIndexPages]);

    useEffect(() => subscribeReaderKeyboard(navigation), []);

    useEffect(() => {
        if (!selectedVersion) {
            return;
        }

        const params = new URLSearchParams({
            account_id: String(selectedVersion.account_id),
            model: selectedVersion.model,
            target_language: selectedVersion.target_language,
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
    }, [currentChapterId, currentPageId, selectedVersion]);

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
            resetScreen();
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

    const sidebarOpen = largeScreen ? sidebarVisible : drawerOpen;

    const openSidebar = () => {
        if (largeScreen) {
            setSidebarVisible(true);
            persistSidebar(true);

            return;
        }

        setDrawerOpen(true);
    };

    const closeSidebar = () => {
        if (largeScreen) {
            setSidebarVisible(false);
            persistSidebar(false);

            return;
        }

        setDrawerOpen(false);
    };

    const selectChapter = (chapterId: number) => {
        const firstPage = index.pages.find((page) => page.chapter_id === chapterId);

        if (!firstPage) {
            return;
        }

        resetScreen();
        setCurrentPageId(firstPage.id);
        setDrawerOpen(false);
    };

    const retry = () => {
        requestedPages.current.clear();
        resetScreen();
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

    return (
        <div className="reader-shell flex h-dvh flex-col overflow-hidden bg-parchment-50 text-ink-900">
            <header className="shrink-0 border-b border-parchment-200 bg-white/90 backdrop-blur">
                <div className="flex h-14 items-center gap-2 px-3 sm:px-4">
                    <Tooltip title={t('reader.backAria')}>
                        <Button
                            type="text"
                            icon={<ArrowLeftOutlined />}
                            onClick={() => router.visit(`/books/${book.id}`)}
                            aria-label={t('reader.backAria')}
                        />
                    </Tooltip>

                    {!sidebarOpen && (
                        <Tooltip title={t('reader.openSidebar')}>
                            <Button
                                type="text"
                                icon={<MenuOutlined />}
                                onClick={openSidebar}
                                aria-label={t('reader.openSidebar')}
                            />
                        </Tooltip>
                    )}

                    <div className="min-w-0 flex-1">
                        <h1 className="truncate font-reading text-base leading-tight text-ink-900 sm:text-lg">
                            {book.title}
                        </h1>
                        <p className="truncate text-[11px] text-ink-500">
                            {book.author ?? t('common.authorUnknown')}
                        </p>
                    </div>

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
            </header>

            <div className="flex min-h-0 flex-1 overflow-hidden">
                {sidebarVisible && (
                    <aside className="hidden w-[300px] shrink-0 border-r border-parchment-200 lg:block xl:w-[320px]">
                        <ReaderSidebar
                            book={book}
                            chapters={chapters}
                            chapterCounts={chapterCounts}
                            currentChapterId={currentChapterId}
                            translatedPages={index.translated_pages}
                            onSelectChapter={selectChapter}
                            onClose={closeSidebar}
                        />
                    </aside>
                )}

                <main className="relative min-h-0 min-w-0 flex-1 overflow-hidden">
                    {error && (
                        <Alert
                            className="absolute inset-x-3 top-3 z-20 shadow-sm"
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
                    ) : !visibleReady ? (
                        <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-sm text-ink-500">
                            {contentLoading ? (
                                <>
                                    <Spin size="large" />
                                    <span>{t('reader.loading')}</span>
                                </>
                            ) : (
                                <Empty description={t('reader.emptyPage')} />
                            )}
                        </div>
                    ) : (
                        <div className="absolute inset-0 px-12 py-4 sm:px-14 sm:py-6 lg:px-16">
                            <div
                                className={
                                    effectiveSpread
                                        ? 'mx-auto h-full w-full max-w-[1200px]'
                                        : 'mx-auto h-full w-full max-w-[46rem]'
                                }
                            >
                                <ReaderCanvas
                                    key={`${visibleKey}:${activeLayout}`}
                                    pages={visiblePageContents}
                                    columns={effectiveSpread ? 2 : 1}
                                    screenIndex={screenIndex}
                                    locale={selectedVersion?.target_language}
                                    onScreenCountChange={handleScreenCountChange}
                                />
                            </div>
                        </div>
                    )}

                    {!indexLoading && index.pages.length > 0 && visibleReady && (
                        <>
                            <Button
                                className="reader-edge-arrow absolute left-1 top-1/2 z-10 inline-flex -translate-y-1/2 sm:left-2"
                                shape="circle"
                                icon={<LeftOutlined />}
                                onClick={goPrevious}
                                disabled={!canGoPrevious}
                                aria-label={t('reader.previous')}
                            />
                            <Button
                                className="reader-edge-arrow absolute right-1 top-1/2 z-10 inline-flex -translate-y-1/2 sm:right-2"
                                shape="circle"
                                icon={<RightOutlined />}
                                onClick={goNext}
                                disabled={!canGoNext}
                                aria-label={t('reader.next')}
                            />
                        </>
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
                    <span className="hidden whitespace-nowrap text-[11px] text-ink-500 sm:inline">
                        {locationLabel}
                    </span>
                    {visibleReady && screenCount > 0 && (
                        <span className="whitespace-nowrap text-[11px] tabular-nums text-ink-500">
                            {t('reader.screenOf', {
                                current: currentScreen,
                                total: screenCount,
                            })}
                        </span>
                    )}
                </div>
            </footer>

            <Drawer
                rootClassName="reader-sidebar-drawer"
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
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
                    onClose={closeSidebar}
                />
            </Drawer>
        </div>
    );
}
