import {
    ArrowLeftOutlined,
    LeftOutlined,
    OrderedListOutlined,
    RightOutlined,
    ThunderboltOutlined,
    TranslationOutlined,
} from '@ant-design/icons';
import { Link, router } from '@inertiajs/react';
import { Alert, App, AutoComplete, Button, Checkbox, Divider, Empty, Select, Spin, Switch, Tag, Tooltip } from 'antd';
import axios from 'axios';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Trans, useTranslation } from 'react-i18next';

import BlockRenderer from '@/components/BlockRenderer';
import { useTranslationBatch } from '@/contexts/TranslationBatchProvider';
import AppLayout from '@/layouts/AppLayout';
import { currentLocale } from '@/i18n';
import { targetSentenceIndices, type TextSelection } from '@/lib/alignment';
import { errorMessage, LANGUAGES, languageLabel, providerLabel } from '@/lib/languages';
import type {
    AiAccount,
    BookSummary,
    ChapterSummary,
    PageDetail,
    PageIndexItem,
    RequestMode,
    Translation,
} from '@/types';

interface Props {
    book: BookSummary;
    chapters: ChapterSummary[];
    pageIndex: PageIndexItem[];
    accounts: AiAccount[];
}

type SlotKey = 'a' | 'b';

interface Slot {
    accountId: number | null;
    model: string;
}

function defaultModelFor(account: AiAccount | undefined): string {
    return account?.default_model || account?.models[0]?.model_id || '';
}

function defaultSlot(accounts: AiAccount[], excludeAccountId?: number | null): Slot {
    const active = accounts.filter((account) => account.is_active);
    const account = active.find((item) => item.id !== excludeAccountId) ?? active[0];

    if (!account) {
        return { accountId: null, model: '' };
    }

    return { accountId: account.id, model: defaultModelFor(account) };
}

function mapTranslatedBlocks(page: PageDetail, translation: Translation) {
    const translated = translation.translated_blocks ?? [];
    const byId = new Map(translated.map((item) => [item.block_id, item.text]));

    return page.blocks.map((block, index) => ({
        id: block.id,
        type: block.type,
        text: byId.get(block.id) ?? translated[index]?.text ?? '',
    }));
}

interface SourceSelection {
    pageId: number;
    blocks: Record<number, TextSelection>;
}

function translationHighlights(
    page: PageDetail,
    translation: Translation,
    selectedBlocks: Record<number, TextSelection>,
    sourceLocale: string | null,
    targetLocale: string,
): Record<number, number[]> {
    const translatedById = new Map(
        mapTranslatedBlocks(page, translation).map((block) => [block.id, block.text]),
    );
    const highlights: Record<number, number[]> = {};

    for (const block of page.blocks) {
        const selection = selectedBlocks[block.id];
        const translated = translatedById.get(block.id);

        if (!selection || !translated) {
            continue;
        }

        const indexes = targetSentenceIndices(
            block.text,
            translated,
            selection,
            sourceLocale ?? undefined,
            targetLocale,
        );

        if (indexes.length > 0) {
            highlights[block.id] = indexes;
        }
    }

    return highlights;
}

function characterOffset(element: HTMLElement, node: Node, offset: number): number {
    const range = document.createRange();
    range.selectNodeContents(element);
    range.setEnd(node, offset);

    return range.toString().length;
}

function RequestModeTag({ mode }: { mode: RequestMode }) {
    const { t } = useTranslation();
    const parallel = mode === 'parallel';

    return (
        <Tag
            color={parallel ? 'blue' : 'default'}
            icon={parallel ? <ThunderboltOutlined /> : <OrderedListOutlined />}
        >
            {parallel ? t('studio.modeParallel') : t('studio.modeSerial')}
        </Tag>
    );
}

function SlotPicker({
    label,
    slot,
    accounts,
    onChange,
}: {
    label: string;
    slot: Slot;
    accounts: AiAccount[];
    onChange: (slot: Slot) => void;
}) {
    const { t } = useTranslation();
    const account = accounts.find((item) => item.id === slot.accountId);
    const modelOptions = (account?.models ?? []).map((model) => ({
        value: model.model_id,
        label: model.model_id,
    }));

    if (account?.default_model && !modelOptions.some((option) => option.value === account.default_model)) {
        modelOptions.unshift({ value: account.default_model, label: account.default_model });
    }

    return (
        <div className="rounded-lg border border-parchment-200 bg-white p-3">
            <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">{label}</span>
                {account && (
                    <div className="flex flex-wrap items-center gap-1">
                        <Tag>{providerLabel(account.provider)}</Tag>
                        <RequestModeTag mode={account.request_mode} />
                    </div>
                )}
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <Select
                    value={slot.accountId ?? undefined}
                    placeholder={t('studio.accountPlaceholder')}
                    options={accounts.map((item) => ({
                        value: item.id,
                        label: item.name,
                        disabled: !item.is_active,
                    }))}
                    onChange={(accountId: number) => {
                        const selected = accounts.find((item) => item.id === accountId);
                        onChange({ accountId, model: defaultModelFor(selected) });
                    }}
                />
                <AutoComplete
                    value={slot.model}
                    options={modelOptions}
                    placeholder={t('studio.modelPlaceholder')}
                    onChange={(value: string) => onChange({ ...slot, model: value })}
                    filterOption={(input, option) =>
                        String(option?.value ?? '')
                            .toLowerCase()
                            .includes(input.toLowerCase())
                    }
                />
            </div>
            {account && account.models.length === 0 && (
                <p className="mt-1.5 text-[11px] text-ink-500">
                    {t('studio.noModels')}
                </p>
            )}
        </div>
    );
}

export default function Show({ book, chapters, pageIndex, accounts }: Props) {
    const { t } = useTranslation();
    const { message } = App.useApp();
    const { startBatch, batchesByBook } = useTranslationBatch();
    const bookBatch = batchesByBook[book.id];
    const batchActive =
        bookBatch?.status === 'queued' || bookBatch?.status === 'running';

    const [activeChapterId, setActiveChapterId] = useState<number | null>(() => chapters[0]?.id ?? null);
    const [targetLanguage, setTargetLanguage] = useState(book.target_language);
    const [savingTargetLanguage, setSavingTargetLanguage] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [lastSelectedIndex, setLastSelectedIndex] = useState<number | null>(null);
    const [pages, setPages] = useState<Record<number, PageDetail>>({});
    const [translationCache, setTranslationCache] = useState<Record<number, Translation[]>>({});
    const [loadingPages, setLoadingPages] = useState(false);
    const [secondColumn, setSecondColumn] = useState(false);
    const [slotA, setSlotA] = useState<Slot>(() => defaultSlot(accounts));
    const [slotB, setSlotB] = useState<Slot>(() => defaultSlot(accounts, slotA.accountId));
    const [sourceSelection, setSourceSelection] = useState<SourceSelection | null>(null);
    const [startingBatch, setStartingBatch] = useState(false);

    const translationBusy = batchActive || startingBatch;

    const selectionSignatureRef = useRef<string | null>(null);
    const completedCountRef = useRef(bookBatch?.succeeded ?? 0);

    const activeAccounts = useMemo(() => accounts.filter((account) => account.is_active), [accounts]);
    const chapterPages = useMemo(
        () => pageIndex.filter((page) => page.chapter_id === activeChapterId),
        [pageIndex, activeChapterId],
    );
    const selectedPages = useMemo(
        () => pageIndex.filter((page) => selected.has(page.id)),
        [pageIndex, selected],
    );
    const selectedChars = useMemo(
        () => selectedPages.reduce((total, page) => total + page.char_count, 0),
        [selectedPages],
    );
    const chapterFullySelected =
        chapterPages.length > 0 && chapterPages.every((page) => selected.has(page.id));

    const activeChapter = chapters.find((chapter) => chapter.id === activeChapterId) ?? null;
    const chapterIndex = chapters.findIndex((chapter) => chapter.id === activeChapterId);
    const previousChapter = chapterIndex > 0 ? chapters[chapterIndex - 1] : null;
    const nextChapter = chapterIndex >= 0 && chapterIndex < chapters.length - 1 ? chapters[chapterIndex + 1] : null;

    const activeSlots = useMemo(
        () => [
            { key: 'a' as SlotKey, slot: slotA },
            ...(secondColumn ? [{ key: 'b' as SlotKey, slot: slotB }] : []),
        ],
        [slotA, slotB, secondColumn],
    );

    const slotIsReady = (slot: Slot) => {
        if (!slot.accountId || slot.model.trim() === '') {
            return false;
        }

        return accounts.some((account) => account.id === slot.accountId && account.is_active);
    };

    const slotsReady = activeSlots.every(({ slot }) => slotIsReady(slot));

    const requestModeSummary = useMemo(() => {
        const modes = new Set(
            activeSlots
                .map(
                    ({ slot }) =>
                        accounts.find((account) => account.id === slot.accountId)?.request_mode,
                )
                .filter((mode): mode is RequestMode => mode !== undefined),
        );

        if (modes.size === 1 && modes.has('parallel')) {
            return t('studio.requestModeParallel');
        }

        if (modes.size === 1 && modes.has('serial')) {
            return t('studio.requestModeSerial');
        }

        return t('studio.requestModeMixed');
    }, [activeSlots, accounts, t]);

    const findTranslation = (pageId: number, slot: Slot): Translation | undefined => {
        if (!slot.accountId || !slot.model) {
            return undefined;
        }

        return (translationCache[pageId] ?? []).find(
            (translation) =>
                translation.status === 'completed' &&
                translation.is_current &&
                translation.ai_account_id === slot.accountId &&
                translation.model === slot.model &&
                translation.target_language === targetLanguage,
        );
    };

    const updateTargetLanguage = async (value: string) => {
        const previous = targetLanguage;
        setTargetLanguage(value);
        setSavingTargetLanguage(true);

        try {
            await axios.patch(`/books/${book.id}`, { target_language: value });
        } catch (error) {
            setTargetLanguage(previous);
            void message.error(errorMessage(error));
        } finally {
            setSavingTargetLanguage(false);
        }
    };

    useEffect(() => {
        if (!activeChapterId) {
            return;
        }

        const controller = new AbortController();
        setLoadingPages(true);

        axios
            .get<{ pages: PageDetail[] }>(`/books/${book.id}/pages`, {
                params: { chapter_id: activeChapterId },
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

                setTranslationCache((previous) => {
                    const next = { ...previous };

                    for (const page of data.pages) {
                        next[page.id] = page.translations;
                    }

                    return next;
                });
            })
            .catch((error) => {
                if (!axios.isCancel(error)) {
                    void message.error(errorMessage(error));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoadingPages(false);
                }
            });

        return () => controller.abort();
    }, [activeChapterId, book.id, message]);

    const completedCount = bookBatch?.succeeded ?? 0;

    useEffect(() => {
        if (completedCount <= completedCountRef.current) {
            completedCountRef.current = completedCount;

            return;
        }

        completedCountRef.current = completedCount;

        if (!activeChapterId) {
            return;
        }

        const controller = new AbortController();

        axios
            .get<{ pages: PageDetail[] }>(`/books/${book.id}/pages`, {
                params: { chapter_id: activeChapterId },
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

                setTranslationCache((previous) => {
                    const next = { ...previous };

                    for (const page of data.pages) {
                        next[page.id] = page.translations;
                    }

                    return next;
                });
            })
            .catch(() => {
                // Il refresh è silenzioso: la pagina verrà ricaricata al
                // prossimo aggiornamento del batch o cambio capitolo.
            });

        return () => controller.abort();
    }, [completedCount, activeChapterId, book.id]);

    useEffect(() => {
        let frame: number | null = null;

        const clearSelection = () => {
            if (selectionSignatureRef.current !== null) {
                selectionSignatureRef.current = null;
                setSourceSelection(null);
            }
        };

        const captureSelection = () => {
            const selection = window.getSelection();

            if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
                clearSelection();
                return;
            }

            const range = selection.getRangeAt(0);
            const startNode = range.startContainer;
            const endNode = range.endContainer;
            const startElement =
                startNode.nodeType === Node.ELEMENT_NODE
                    ? (startNode as Element)
                    : startNode.parentElement;
            const endElement =
                endNode.nodeType === Node.ELEMENT_NODE
                    ? (endNode as Element)
                    : endNode.parentElement;
            const startPane =
                startElement?.closest<HTMLElement>('[data-source-pane]') ?? null;
            const endPane =
                endElement?.closest<HTMLElement>('[data-source-pane]') ?? null;

            if (!startPane || startPane !== endPane) {
                clearSelection();
                return;
            }

            const pageId = Number(startPane.dataset.pageId);

            if (!Number.isFinite(pageId)) {
                clearSelection();
                return;
            }

            const blocks: Record<number, TextSelection> = {};

            for (const element of Array.from(
                startPane.querySelectorAll<HTMLElement>('[data-block-id]'),
            )) {
                if (!range.intersectsNode(element)) {
                    continue;
                }

                const blockId = Number(element.dataset.blockId);

                if (!Number.isFinite(blockId)) {
                    continue;
                }

                const textLength = (element.textContent ?? '').length;
                let start = 0;
                let end = textLength;

                if (element.contains(startNode)) {
                    start = characterOffset(element, startNode, range.startOffset);
                }

                if (element.contains(endNode)) {
                    end = characterOffset(element, endNode, range.endOffset);
                }

                start = Math.max(0, Math.min(start, textLength));
                end = Math.max(start, Math.min(end, textLength));

                if (end > start) {
                    blocks[blockId] = { start, end };
                }
            }

            const entries = Object.entries(blocks);

            if (entries.length === 0) {
                clearSelection();
                return;
            }

            const signature = `${pageId}:${entries
                .map(([blockId, blockSelection]) => {
                    return `${blockId}-${blockSelection.start}-${blockSelection.end}`;
                })
                .join('|')}`;

            if (signature === selectionSignatureRef.current) {
                return;
            }

            selectionSignatureRef.current = signature;
            setSourceSelection({ pageId, blocks });
        };

        const scheduleCapture = () => {
            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }

            frame = window.requestAnimationFrame(() => {
                frame = null;
                captureSelection();
            });
        };

        document.addEventListener('selectionchange', scheduleCapture);

        return () => {
            document.removeEventListener('selectionchange', scheduleCapture);

            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }
        };
    }, []);

    useEffect(() => {
        setLastSelectedIndex(null);
        selectionSignatureRef.current = null;
        setSourceSelection(null);
    }, [activeChapterId]);

    const togglePage = (pageId: number, index: number) => {
        setSelected((previous) => {
            const next = new Set(previous);

            if (next.has(pageId)) {
                next.delete(pageId);
            } else {
                next.add(pageId);
            }

            return next;
        });
        setLastSelectedIndex(index);
    };

    const selectRange = (index: number) => {
        const anchor = lastSelectedIndex ?? index;
        const start = Math.min(anchor, index);
        const end = Math.max(anchor, index);
        const ids = chapterPages.slice(start, end + 1).map((page) => page.id);

        setSelected((previous) => {
            const next = new Set(previous);
            ids.forEach((id) => next.add(id));

            return next;
        });
        setLastSelectedIndex(index);
    };

    const toggleChapter = () => {
        setSelected((previous) => {
            const next = new Set(previous);

            if (chapterFullySelected) {
                chapterPages.forEach((page) => next.delete(page.id));
            } else {
                chapterPages.forEach((page) => next.add(page.id));
            }

            return next;
        });
    };

    const launchBatch = async (pageIds: number[]) => {
        if (startingBatch) {
            return;
        }

        if (pageIds.length === 0) {
            void message.warning(t('studio.selectPageWarning'));
            return;
        }

        if (!slotsReady) {
            void message.warning(t('studio.slotsWarning'));
            return;
        }

        const slots = activeSlots.flatMap(({ key, slot }) =>
            slot.accountId === null
                ? []
                : [
                      {
                          slot_key: key,
                          account_id: slot.accountId,
                          model: slot.model.trim(),
                      },
                  ],
        );

        setStartingBatch(true);

        try {
            const result = await startBatch(book.id, {
                slots,
                page_ids: pageIds,
                target_language: targetLanguage,
            });

            if (result.batch) {
                void message.success(t('studio.batchStarted'));
            } else {
                void message.info(result.message ?? t('studio.allCached'));
            }
        } catch (error) {
            void message.error(errorMessage(error));
        } finally {
            setStartingBatch(false);
        }
    };

    const startTranslation = () => {
        void launchBatch(Array.from(selected));
    };

    const translatePage = (pageId: number) => {
        if (translationBusy) {
            return;
        }

        void launchBatch([pageId]);
    };

    const updateSlot = (key: SlotKey, slot: Slot) => {
        if (key === 'a') {
            setSlotA(slot);
        } else {
            setSlotB(slot);
        }
    };

    const toggleSecondColumn = (checked: boolean) => {
        setSecondColumn(checked);

        if (checked && (!slotB.accountId || !slotB.model)) {
            setSlotB(defaultSlot(accounts, slotA.accountId));
        }
    };

    const coverageByChapter = useMemo(() => {
        const coverage: Record<number, { translated: number; total: number }> = {};

        if (!slotIsReady(slotA)) {
            return coverage;
        }

        for (const chapter of chapters) {
            const pagesOfChapter = pageIndex.filter((page) => page.chapter_id === chapter.id);
            const translated = pagesOfChapter.filter((page) => findTranslation(page.id, slotA)).length;

            coverage[chapter.id] = { translated, total: pagesOfChapter.length };
        }

        return coverage;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chapters, pageIndex, translationCache, slotA, targetLanguage]);

    return (
        <AppLayout>
            <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-3">
                    <Button icon={<ArrowLeftOutlined />} onClick={() => router.visit('/')} aria-label={t('studio.backAria')} />
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="truncate font-reading text-2xl text-ink-900 md:text-3xl">
                                {book.title}
                            </h1>
                            <Tag color="gold">
                                {languageLabel(book.language)} → {languageLabel(targetLanguage)}
                            </Tag>
                        </div>
                        <p className="mt-1 text-sm text-ink-500">
                            {book.author ?? t('common.authorUnknown')} ·{' '}
                            {t('common.chapters', { count: book.chapters_count })} ·{' '}
                            {t('common.virtualPages', { count: book.pages_count })} ·{' '}
                            {t('studio.charsPerPage', { count: book.page_char_limit })}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs uppercase tracking-wide text-ink-500">
                        {t('studio.targetLanguage')}
                    </span>
                    <Select
                        value={targetLanguage}
                        options={LANGUAGES.map((language) => ({
                            value: language,
                            label: languageLabel(language),
                        }))}
                        onChange={updateTargetLanguage}
                        loading={savingTargetLanguage}
                        disabled={translationBusy}
                        style={{ width: 150 }}
                        showSearch
                        optionFilterProp="label"
                    />
                </div>
            </div>

            {activeAccounts.length === 0 && (
                <Alert
                    className="mb-4"
                    type="warning"
                    showIcon
                    title={t('studio.noAccountsTitle')}
                    description={
                        <Trans
                            i18nKey="studio.noAccountsDescription"
                            components={{ link: <Link href="/accounts" /> }}
                        />
                    }
                />
            )}

            <div className="grid gap-6 lg:grid-cols-[290px_minmax(0,1fr)]">
                <aside className="space-y-4">
                    <div className="rounded-xl border border-parchment-200 bg-white p-3 shadow-sm">
                        <div className="mb-2 flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-ink-900">
                                {t('studio.chaptersTitle')}
                            </h2>
                            <Tag>{chapters.length}</Tag>
                        </div>
                        <div className="max-h-[52vh] space-y-0.5 overflow-y-auto pr-1">
                            {chapters.map((chapter) => {
                                const active = chapter.id === activeChapterId;
                                const coverage = coverageByChapter[chapter.id];

                                return (
                                    <button
                                        key={chapter.id}
                                        type="button"
                                        onClick={() => setActiveChapterId(chapter.id)}
                                        className={`w-full rounded-md px-2 py-1.5 text-left transition ${
                                            active
                                                ? 'bg-leather-100 text-leather-700'
                                                : 'text-ink-700 hover:bg-parchment-100'
                                        }`}
                                    >
                                        <span className="flex items-center gap-2">
                                            <span
                                                className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] ${
                                                    active
                                                        ? 'bg-leather-600 text-white'
                                                        : 'bg-parchment-200 text-ink-700'
                                                }`}
                                            >
                                                {chapter.position}
                                            </span>
                                            <span className="min-w-0 flex-1 truncate text-sm">
                                                {chapter.title}
                                            </span>
                                            <span className="text-[11px] text-ink-500">
                                                {chapter.pages_count}
                                            </span>
                                        </span>
                                        {coverage && (
                                            <span className="ml-8 mt-1 block h-1 overflow-hidden rounded-full bg-parchment-200">
                                                <span
                                                    className="block h-full rounded-full bg-leather-600/80"
                                                    style={{
                                                        width: `${coverage.total === 0 ? 0 : (coverage.translated / coverage.total) * 100}%`,
                                                    }}
                                                />
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        <Divider className="my-2" />

                        <div className="grid gap-2">
                            <Button size="small" onClick={toggleChapter} disabled={chapterPages.length === 0}>
                                {chapterFullySelected
                                    ? t('studio.deselectChapter')
                                    : t('studio.selectChapter')}
                            </Button>
                            <Button size="small" onClick={() => setSelected(new Set(pageIndex.map((page) => page.id)))}>
                                {t('studio.selectAll', { count: pageIndex.length })}
                            </Button>
                            {selected.size > 0 && (
                                <Button size="small" type="text" danger onClick={() => setSelected(new Set())}>
                                    {t('studio.clearSelection')}
                                </Button>
                            )}
                        </div>
                    </div>

                    <div className="rounded-xl border border-parchment-200 bg-white p-4 shadow-sm">
                        <div className="flex items-end justify-between">
                            <span className="text-3xl font-semibold text-ink-900">{selected.size}</span>
                            <span className="pb-1 text-xs text-ink-500">
                                {t('studio.selectedPages', { count: selected.size })}
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-ink-500">
                            {t('studio.charsToTranslate', {
                                count: selectedChars,
                                value: selectedChars.toLocaleString(currentLocale()),
                            })}
                        </p>

                        <Button
                            type="primary"
                            size="large"
                            block
                            className="mt-3"
                            icon={<TranslationOutlined />}
                            onClick={startTranslation}
                            disabled={selected.size === 0 || !slotsReady || translationBusy}
                            loading={translationBusy}
                        >
                            {translationBusy ? t('studio.translating') : t('studio.translateSelection')}
                        </Button>
                        <p className="mt-2 text-[11px] leading-snug text-ink-500">
                            {requestModeSummary} {t('studio.cacheHint')}
                        </p>
                    </div>
                </aside>

                <section className="min-w-0">
                    <div className="mb-4 rounded-xl border border-parchment-200 bg-white p-4 shadow-sm">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div className="flex min-w-0 items-center gap-2">
                                {previousChapter && (
                                    <Button
                                        size="small"
                                        icon={<LeftOutlined />}
                                        onClick={() => setActiveChapterId(previousChapter.id)}
                                        aria-label={t('studio.previousChapterAria')}
                                    />
                                )}
                                <div className="min-w-0">
                                    <p className="text-[11px] uppercase tracking-wide text-ink-500">
                                        {t('studio.chapterLabel', {
                                            position: activeChapter?.position ?? '—',
                                        })}
                                    </p>
                                    <h2 className="truncate text-lg font-semibold text-ink-900">
                                        {activeChapter?.title ?? t('studio.chapterPlaceholder')}
                                    </h2>
                                </div>
                                {nextChapter && (
                                    <Button
                                        size="small"
                                        icon={<RightOutlined />}
                                        onClick={() => setActiveChapterId(nextChapter.id)}
                                        aria-label={t('studio.nextChapterAria')}
                                    />
                                )}
                            </div>

                            <div className="flex items-center gap-3">
                                <span className="text-xs text-ink-500">
                                    {t('studio.compareToggle')}
                                </span>
                                <Switch checked={secondColumn} onChange={toggleSecondColumn} />
                            </div>
                        </div>

                        <div className={secondColumn ? 'grid gap-3 md:grid-cols-2' : 'grid gap-3'}>
                            <SlotPicker
                                label={t('studio.slot1')}
                                slot={slotA}
                                accounts={accounts}
                                onChange={(slot) => updateSlot('a', slot)}
                            />
                            {secondColumn && (
                                <SlotPicker
                                    label={t('studio.slot2')}
                                    slot={slotB}
                                    accounts={accounts}
                                    onChange={(slot) => updateSlot('b', slot)}
                                />
                            )}
                        </div>
                    </div>

                    {loadingPages && chapterPages.every((page) => !pages[page.id]) ? (
                        <div className="flex justify-center rounded-xl border border-parchment-200 bg-white py-20">
                            <Spin description={t('studio.loadingPages')} size="large">
                                <div className="h-24 w-1" />
                            </Spin>
                        </div>
                    ) : chapterPages.length === 0 ? (
                        <div className="rounded-xl border border-parchment-200 bg-white py-16">
                            <Empty description={t('studio.emptyChapter')} />
                        </div>
                    ) : (
                        <div className="space-y-5">
                            {chapterPages.map((info, index) => {
                                const page = pages[info.id];

                                return (
                                    <article
                                        key={info.id}
                                        className="overflow-hidden rounded-xl border border-parchment-200 bg-white shadow-sm"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-parchment-200 bg-parchment-50/60 px-4 py-2.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Checkbox
                                                    checked={selected.has(info.id)}
                                                    onChange={(event) => {
                                                        if (!event.nativeEvent.shiftKey) {
                                                            togglePage(info.id, index);
                                                        }
                                                    }}
                                                    onClick={(event) => {
                                                        if (event.shiftKey) {
                                                            event.preventDefault();
                                                            selectRange(index);
                                                        }
                                                    }}
                                                />
                                                <span className="text-sm font-medium text-ink-900">
                                                    {t('studio.pageLabel', {
                                                        position: info.position,
                                                    })}
                                                </span>
                                                <Tag>
                                                    {t('studio.chapterTag', {
                                                        position: info.chapter_position,
                                                    })}
                                                </Tag>
                                                <span className="text-xs text-ink-500">
                                                    {t('common.characters', {
                                                        count: info.char_count,
                                                    })}
                                                </span>
                                                <span className="hidden text-[11px] text-ink-500 md:inline">
                                                    {t('studio.shiftHint')}
                                                </span>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {batchActive &&
                                                    (bookBatch?.active_page_ids.includes(info.id) ?? false) && (
                                                        <Spin size="small" />
                                                    )}
                                                <Button
                                                    size="small"
                                                    icon={<ThunderboltOutlined />}
                                                    onClick={() => translatePage(info.id)}
                                                    disabled={!slotsReady || translationBusy}
                                                >
                                                    {t('studio.translate')}
                                                </Button>
                                            </div>
                                        </div>

                                        {!page ? (
                                            <div className="flex justify-center py-16">
                                                <Spin />
                                            </div>
                                        ) : (
                                            <div
                                                className={
                                                    secondColumn
                                                        ? 'grid gap-5 p-4 xl:grid-cols-3'
                                                        : 'grid gap-5 p-4 xl:grid-cols-2'
                                                }
                                            >
                                                <div
                                                    className="rounded-lg bg-parchment-50/70 p-4"
                                                    data-source-pane
                                                    data-page-id={info.id}
                                                >
                                                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-parchment-200 pb-2">
                                                        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
                                                            {t('studio.originalLabel', {
                                                                language: languageLabel(
                                                                    book.language,
                                                                ),
                                                            })}
                                                        </span>
                                                        <span className="text-[11px] text-ink-500">
                                                            {page.source_hash.slice(0, 8)}
                                                        </span>
                                                    </div>
                                                    {activeSlots.some(({ slot }) =>
                                                        findTranslation(info.id, slot),
                                                    ) && (
                                                        <p className="mb-2 text-[11px] text-ink-500">
                                                            {t('studio.selectionHint')}
                                                        </p>
                                                    )}
                                                    <BlockRenderer
                                                        blocks={page.blocks}
                                                        locale={book.language ?? undefined}
                                                    />
                                                </div>

                                                {activeSlots.map(({ key, slot }) => {
                                                    const account = accounts.find(
                                                        (item) => item.id === slot.accountId,
                                                    );
                                                    const translation = findTranslation(info.id, slot);
                                                    const ready = slotIsReady(slot);
                                                    const highlights =
                                                        translation &&
                                                        sourceSelection?.pageId === info.id
                                                            ? translationHighlights(
                                                                  page,
                                                                  translation,
                                                                  sourceSelection.blocks,
                                                                  book.language,
                                                                  targetLanguage,
                                                              )
                                                            : undefined;

                                                    return (
                                                        <div
                                                            key={key}
                                                            className="rounded-lg border border-parchment-200 p-4"
                                                        >
                                                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-parchment-200 pb-2">
                                                                <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
                                                                    {t('studio.translationLabel', {
                                                                        number:
                                                                            key === 'a'
                                                                                ? '1'
                                                                                : '2',
                                                                    })}
                                                                </span>
                                                                <div className="flex flex-wrap items-center gap-1">
                                                                    <Tag>
                                                                        {account?.name ??
                                                                            t('studio.accountMissing')}
                                                                    </Tag>
                                                                    {translation && (
                                                                        <Tooltip title={t('studio.cachedTooltip')}>
                                                                            <Tag color="green">
                                                                                {t('studio.cacheTag')}
                                                                            </Tag>
                                                                        </Tooltip>
                                                                    )}
                                                                </div>
                                                            </div>

                                                            {translation ? (
                                                                <>
                                                                    <BlockRenderer
                                                                        blocks={mapTranslatedBlocks(page, translation)}
                                                                        locale={targetLanguage}
                                                                        sentenceHighlights={highlights}
                                                                    />
                                                                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-parchment-200 pt-2 text-[11px] text-ink-500">
                                                                        <Tag className="font-mono text-[10px]">
                                                                            {translation.model}
                                                                        </Tag>
                                                                        {translation.total_tokens != null && (
                                                                            <span>
                                                                                {t('studio.tokens', {
                                                                                    count: translation.total_tokens,
                                                                                })}
                                                                            </span>
                                                                        )}
                                                                        {translation.latency_ms != null && (
                                                                            <span>
                                                                                ·{' '}
                                                                                {(
                                                                                    translation.latency_ms / 1000
                                                                                ).toFixed(1)}{' '}
                                                                                s
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </>
                                                            ) : (
                                                                <div className="flex min-h-[180px] flex-col items-center justify-center gap-3 rounded-md border border-dashed border-parchment-300 bg-parchment-50/40 p-4 text-center">
                                                                    <TranslationOutlined className="text-2xl text-parchment-300" />
                                                                    <p className="max-w-[26ch] text-sm text-ink-500">
                                                                        {ready
                                                                            ? t('studio.emptyTranslationReady')
                                                                            : t('studio.emptyTranslationNotReady')}
                                                                    </p>
                                                                    <Button
                                                                        size="small"
                                                                        onClick={() => translatePage(info.id)}
                                                                        disabled={!ready || translationBusy}
                                                                    >
                                                                        {t('studio.translateNow')}
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
