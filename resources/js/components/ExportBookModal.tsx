import { DownloadOutlined } from '@ant-design/icons';
import { Alert, App, Button, Checkbox, Modal, Segmented, Select, Spin, Tag } from 'antd';
import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import type {
    AiAccount,
    BookSummary,
    ChapterSummary,
    TranslationCoverageResponse,
} from '@/types';

export interface ExportSlot {
    key: 'a' | 'b';
    accountId: number | null;
    model: string;
}

interface Props {
    open: boolean;
    onClose: () => void;
    book: BookSummary;
    chapters: ChapterSummary[];
    accounts: AiAccount[];
    targetLanguage: string;
    slots: ExportSlot[];
}

type ExportFormat = 'epub' | 'pdf';

interface ReadySlot extends ExportSlot {
    accountId: number;
}

interface MissingChapter {
    id: number;
    title: string;
    translated: number;
    total: number;
}

interface ExportErrorPayload {
    message?: string;
    missing_chapters?: {
        chapter_id: number;
        title: string;
        translated: number;
        total: number;
    }[];
}

function filenameFromDisposition(header: string | undefined): string | null {
    if (!header) {
        return null;
    }

    const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);

    if (utf8Match) {
        try {
            return decodeURIComponent(utf8Match[1]);
        } catch {
            return utf8Match[1];
        }
    }

    const match = header.match(/filename="?([^";]+)"?/i);

    return match ? match[1] : null;
}

function saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

async function parseExportError(error: unknown): Promise<ExportErrorPayload | null> {
    if (!axios.isAxiosError(error)) {
        return null;
    }

    const data = error.response?.data as unknown;

    if (data instanceof Blob) {
        try {
            return JSON.parse(await data.text()) as ExportErrorPayload;
        } catch {
            return null;
        }
    }

    if (data && typeof data === 'object') {
        return data as ExportErrorPayload;
    }

    return null;
}

export default function ExportBookModal({
    open,
    onClose,
    book,
    chapters,
    accounts,
    targetLanguage,
    slots,
}: Props) {
    const { t } = useTranslation();
    const { message, modal } = App.useApp();
    const [format, setFormat] = useState<ExportFormat>('epub');
    const [slotKey, setSlotKey] = useState<'a' | 'b'>('a');
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [coverage, setCoverage] = useState<Record<number, number>>({});
    const [coverageReady, setCoverageReady] = useState(false);
    const [loadingCoverage, setLoadingCoverage] = useState(false);
    const [downloading, setDownloading] = useState(false);

    const availableSlots = useMemo<ReadySlot[]>(
        () =>
            slots.flatMap((slot) => {
                const account = accounts.find(
                    (item) => item.id === slot.accountId && item.is_active,
                );

                return slot.accountId !== null && slot.model.trim() !== '' && account
                    ? [{ ...slot, accountId: slot.accountId }]
                    : [];
            }),
        [accounts, slots],
    );

    const activeSlot =
        availableSlots.find((slot) => slot.key === slotKey) ?? availableSlots[0] ?? null;

    useEffect(() => {
        if (!open) {
            return;
        }

        setSelected(new Set(chapters.map((chapter) => chapter.id)));
        setFormat('epub');
        setSlotKey(availableSlots[0]?.key ?? 'a');
    }, [open]);

    useEffect(() => {
        if (!open || !activeSlot) {
            setCoverage({});
            setCoverageReady(false);

            return;
        }

        let cancelled = false;
        const controller = new AbortController();
        setLoadingCoverage(true);
        setCoverageReady(false);

        axios
            .get<TranslationCoverageResponse>(
                `/books/${book.id}/translation-coverage`,
                {
                    params: {
                        account_id: activeSlot.accountId,
                        model: activeSlot.model,
                        target_language: targetLanguage,
                    },
                    signal: controller.signal,
                },
            )
            .then(({ data }) => {
                if (cancelled) {
                    return;
                }

                const next: Record<number, number> = {};

                for (const row of data.coverage) {
                    next[row.chapter_id] = row.translated;
                }

                setCoverage(next);
                setCoverageReady(true);
            })
            .catch((error) => {
                if (!cancelled && !axios.isCancel(error)) {
                    setCoverage({});
                    setCoverageReady(false);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingCoverage(false);
                }
            });

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [open, book.id, targetLanguage, activeSlot]);

    const selectedChapters = useMemo(
        () => chapters.filter((chapter) => selected.has(chapter.id)),
        [chapters, selected],
    );

    const missingChapters = useMemo<MissingChapter[]>(
        () =>
            selectedChapters
                .map((chapter) => ({
                    id: chapter.id,
                    title: chapter.title,
                    translated: coverage[chapter.id] ?? 0,
                    total: chapter.pages_count,
                }))
                .filter((chapter) => chapter.translated < chapter.total),
        [coverage, selectedChapters],
    );

    const totalPages = selectedChapters.reduce(
        (total, chapter) => total + chapter.pages_count,
        0,
    );
    const translatedPages = selectedChapters.reduce(
        (total, chapter) => total + Math.min(coverage[chapter.id] ?? 0, chapter.pages_count),
        0,
    );

    const slotLabel = (slot: ReadySlot): string => {
        const account = accounts.find((item) => item.id === slot.accountId);
        const prefix = slot.key === 'a' ? t('studio.slot1') : t('studio.slot2');

        return `${prefix} · ${account?.name ?? t('studio.accountMissing')} · ${slot.model}`;
    };

    const toggleChapter = (chapterId: number, checked: boolean) => {
        setSelected((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(chapterId);
            } else {
                next.delete(chapterId);
            }

            return next;
        });
    };

    const showIncomplete = (items: MissingChapter[]) => {
        modal.warning({
            title: t('export.incompleteTitle'),
            width: 520,
            content: (
                <div className="space-y-3">
                    <p>{t('export.incompleteDescription')}</p>
                    <ul className="list-disc space-y-1 pl-5">
                        {items.map((item) => (
                            <li key={item.id}>
                                <span className="font-medium">{item.title}</span> —{' '}
                                {t('export.missingPages', {
                                    count: item.total - item.translated,
                                    total: item.total,
                                })}
                            </li>
                        ))}
                    </ul>
                </div>
            ),
            okText: t('common.close'),
        });
    };

    const download = async () => {
        if (!activeSlot) {
            message.warning(t('export.noSlot'));

            return;
        }

        if (selected.size === 0) {
            message.warning(t('export.noChapters'));

            return;
        }

        if (loadingCoverage) {
            return;
        }

        if (coverageReady && missingChapters.length > 0) {
            showIncomplete(missingChapters);

            return;
        }

        setDownloading(true);

        try {
            const response = await axios.post(
                `/books/${book.id}/export`,
                {
                    format,
                    target_language: targetLanguage,
                    account_id: activeSlot.accountId,
                    model: activeSlot.model,
                    chapter_ids: Array.from(selected),
                },
                { responseType: 'blob' },
            );

            const filename =
                filenameFromDisposition(response.headers['content-disposition']) ??
                `${book.title}.${format}`;

            saveBlob(response.data as Blob, filename);
            onClose();
        } catch (error) {
            const payload = await parseExportError(error);

            if (payload?.missing_chapters?.length) {
                showIncomplete(
                    payload.missing_chapters.map((chapter) => ({
                        id: chapter.chapter_id,
                        title: chapter.title,
                        translated: chapter.translated,
                        total: chapter.total,
                    })),
                );
            } else {
                message.error(payload?.message ?? t('export.error'));
            }
        } finally {
            setDownloading(false);
        }
    };

    return (
        <Modal
            open={open}
            onCancel={onClose}
            title={
                <span className="flex items-center gap-2">
                    <DownloadOutlined /> {t('export.title')}
                </span>
            }
            width={640}
            footer={[
                <Button key="cancel" onClick={onClose}>
                    {t('common.cancel')}
                </Button>,
                <Button
                    key="download"
                    type="primary"
                    icon={<DownloadOutlined />}
                    loading={downloading}
                    disabled={!activeSlot || selected.size === 0 || loadingCoverage}
                    onClick={download}
                >
                    {downloading
                        ? t('export.downloading')
                        : t('export.download', { format: format.toUpperCase() })}
                </Button>,
            ]}
        >
            <p className="text-sm text-ink-500">{t('export.description')}</p>

            <div className="mt-4 space-y-5">
                <div>
                    <div className="mb-1.5 text-sm font-medium text-ink-900">
                        {t('export.version')}
                    </div>

                    {availableSlots.length > 1 ? (
                        <Select
                            value={activeSlot?.key}
                            onChange={(value) => setSlotKey(value as 'a' | 'b')}
                            options={availableSlots.map((slot) => ({
                                value: slot.key,
                                label: slotLabel(slot),
                            }))}
                            style={{ width: '100%' }}
                        />
                    ) : activeSlot ? (
                        <div className="rounded-md border border-parchment-200 bg-parchment-50 px-3 py-2 text-sm text-ink-700">
                            {slotLabel(activeSlot)}
                        </div>
                    ) : (
                        <Alert type="warning" showIcon title={t('export.noSlot')} />
                    )}
                </div>

                <div>
                    <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                        <span className="flex items-center gap-2 text-sm font-medium text-ink-900">
                            {t('export.chapters')}
                            {loadingCoverage && <Spin size="small" />}
                        </span>
                        <span className="flex items-center gap-2">
                            <Button
                                size="small"
                                type="link"
                                onClick={() =>
                                    setSelected(new Set(chapters.map((chapter) => chapter.id)))
                                }
                            >
                                {t('export.selectAll')}
                            </Button>
                            <Button
                                size="small"
                                type="link"
                                onClick={() => setSelected(new Set())}
                            >
                                {t('export.deselectAll')}
                            </Button>
                        </span>
                    </div>

                    <div className="max-h-72 overflow-y-auto rounded-lg border border-parchment-200 bg-parchment-50 p-2">
                        {chapters.map((chapter) => {
                            const translated = coverage[chapter.id] ?? 0;
                            const complete = translated >= chapter.pages_count;
                            const missing = Math.max(0, chapter.pages_count - translated);

                            return (
                                <label
                                    key={chapter.id}
                                    className="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 transition hover:bg-white"
                                >
                                    <Checkbox
                                        checked={selected.has(chapter.id)}
                                        onChange={(event) =>
                                            toggleChapter(chapter.id, event.target.checked)
                                        }
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm text-ink-900">
                                            {chapter.title}
                                        </span>
                                        <span className="text-[11px] text-ink-500">
                                            {t('export.coverage', {
                                                translated,
                                                total: chapter.pages_count,
                                            })}
                                        </span>
                                    </span>
                                    {coverageReady &&
                                        (complete ? (
                                            <Tag className="border-success-green/40! bg-success-green/10! text-ink-900!">
                                                {t('export.complete')}
                                            </Tag>
                                        ) : (
                                            <Tag className="border-warning-gold/40! bg-warning-gold/10! text-ink-900!">
                                                {t('export.incomplete', { count: missing })}
                                            </Tag>
                                        ))}
                                </label>
                            );
                        })}
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-parchment-100 px-3 py-2 text-sm text-ink-700">
                    <span>
                        {coverageReady
                            ? t('export.summary', {
                                  chapters: selectedChapters.length,
                                  translated: translatedPages,
                                  total: totalPages,
                              })
                            : t('export.summaryWithoutCoverage', {
                                  chapters: selectedChapters.length,
                              })}
                    </span>
                    <span className="flex items-center gap-2">
                        <span className="text-xs uppercase tracking-wide text-ink-500">
                            {t('export.format')}
                        </span>
                        <Segmented
                            value={format}
                            onChange={(value) => setFormat(value as ExportFormat)}
                            options={[
                                { label: 'EPUB', value: 'epub' },
                                { label: 'PDF', value: 'pdf' },
                            ]}
                        />
                    </span>
                </div>
            </div>
        </Modal>
    );
}
