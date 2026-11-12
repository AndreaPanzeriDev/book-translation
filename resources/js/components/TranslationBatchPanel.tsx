import { CloseOutlined, ReloadOutlined, StopOutlined } from '@ant-design/icons';
import { Link } from '@inertiajs/react';
import { App, Button, Modal, Progress, Tag, Tooltip } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { useTranslationBatch } from '@/contexts/TranslationBatchProvider';
import { errorMessage } from '@/lib/languages';
import type { TranslationBatch, TranslationBatchFailure } from '@/types';

const STATUS_KEY: Record<TranslationBatch['status'], string> = {
    queued: 'batch.statusQueued',
    running: 'batch.statusRunning',
    completed: 'batch.statusCompleted',
    failed: 'batch.statusFailed',
    cancelled: 'batch.statusCancelled',
};

const STATUS_CLASS: Record<TranslationBatch['status'], string | undefined> = {
    queued: undefined,
    running: 'border-leather-600/40! bg-leather-600/10! text-ink-900!',
    completed: 'border-success-green/40! bg-success-green/10! text-ink-900!',
    failed: 'border-danger-red/40! bg-danger-surface! text-danger-ink!',
    cancelled: 'border-warning-gold/40! bg-warning-gold/10! text-ink-900!',
};

function progressStatus(batch: TranslationBatch) {
    if (batch.status === 'failed') {
        return 'exception' as const;
    }

    if (batch.status === 'completed') {
        return 'success' as const;
    }

    if (batch.status === 'queued' || batch.status === 'running') {
        return 'active' as const;
    }

    return 'normal' as const;
}

export default function TranslationBatchPanel() {
    const { t } = useTranslation();
    const { batches, cancelBatch, retryBatch, dismissBatch } = useTranslationBatch();
    const { message } = App.useApp();
    const [detail, setDetail] = useState<TranslationBatchFailure | null>(null);
    const [busy, setBusy] = useState<Record<number, 'cancel' | 'retry' | undefined>>({});

    if (batches.length === 0) {
        return null;
    }

    const handleCancel = async (batch: TranslationBatch) => {
        setBusy((previous) => ({ ...previous, [batch.id]: 'cancel' }));

        try {
            await cancelBatch(batch.id);
            void message.info(t('batch.cancelNotification'));
        } catch (error) {
            void message.error(errorMessage(error));
        } finally {
            setBusy((previous) => ({ ...previous, [batch.id]: undefined }));
        }
    };

    const handleRetry = async (batch: TranslationBatch) => {
        setBusy((previous) => ({ ...previous, [batch.id]: 'retry' }));

        try {
            await retryBatch(batch.id);
            void message.info(t('batch.retryNotification'));
        } catch (error) {
            void message.error(errorMessage(error));
        } finally {
            setBusy((previous) => ({ ...previous, [batch.id]: undefined }));
        }
    };

    const copyDetail = () => {
        if (!detail) {
            return;
        }

        navigator.clipboard
            .writeText(detail.message)
            .then(() => message.success(t('batch.copied')))
            .catch(() => message.error(t('batch.copyFailed')));
    };

    return (
        <>
            <div className="pointer-events-none fixed bottom-4 right-4 z-50 w-[calc(100%-2rem)] max-w-md">
                <div className="pointer-events-auto max-h-[75vh] space-y-3 overflow-y-auto pr-1">
                    {batches.map((batch) => {
                        const active = batch.status === 'queued' || batch.status === 'running';
                        const percent =
                            batch.total === 0
                                ? 0
                                : Math.round((batch.processed / batch.total) * 100);
                        const action = busy[batch.id];

                        return (
                            <div
                                key={batch.id}
                                className="rounded-xl border border-parchment-200 bg-white p-4 shadow-2xl"
                            >
                                <div className="mb-2 flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-ink-900">
                                            {batch.book_title ??
                                                t('batch.bookFallback', { id: batch.book_id })}
                                        </p>
                                        <p className="mt-0.5 text-[11px] uppercase tracking-wide text-ink-500">
                                            {t('batch.translationHeader', {
                                                language: batch.target_language.toUpperCase(),
                                            })}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1">
                                        <Tag className={STATUS_CLASS[batch.status]}>
                                            {t(STATUS_KEY[batch.status])}
                                        </Tag>
                                        {!active && (
                                            <Tooltip title={t('batch.closePanel')}>
                                                <Button
                                                    type="text"
                                                    size="small"
                                                    icon={<CloseOutlined />}
                                                    onClick={() => dismissBatch(batch.id)}
                                                    aria-label={t('batch.closePanel')}
                                                />
                                            </Tooltip>
                                        )}
                                    </div>
                                </div>

                                <Progress
                                    percent={percent}
                                    status={progressStatus(batch)}
                                    size="small"
                                />

                                <div className="mt-1 flex flex-wrap items-center justify-between gap-1 text-xs text-ink-500">
                                    <span>
                                        {batch.processed} / {batch.total}
                                    </span>
                                    <span>
                                        {t('batch.succeeded', { count: batch.succeeded })} ·{' '}
                                        {t('batch.failed', { count: batch.failed })}
                                        {batch.cancelled > 0 &&
                                            ` · ${t('batch.cancelled', { count: batch.cancelled })}`}
                                    </span>
                                </div>

                                {active && (
                                    <p className="mt-2 truncate text-xs text-ink-700">
                                        {batch.cancel_requested_at
                                            ? t('batch.cancelPending')
                                            : batch.in_flight > 1
                                              ? t('batch.inFlight', { count: batch.in_flight })
                                              : batch.current_label
                                                ? t('batch.running', { label: batch.current_label })
                                                : t('batch.waitingWorker')}
                                    </p>
                                )}

                                {batch.failures.length > 0 && (
                                    <div className="mt-2 max-h-32 space-y-1 overflow-y-auto rounded-md bg-danger-surface p-2 text-[11px] text-danger-ink">
                                        {batch.failures.map((failure) => (
                                            <button
                                                key={failure.item_id}
                                                type="button"
                                                className="block w-full cursor-pointer truncate text-left hover:underline"
                                                title={t('batch.failureTooltip')}
                                                onClick={() => setDetail(failure)}
                                            >
                                                <span className="font-medium">
                                                    {t('batch.failureLabel', {
                                                        page: failure.page_position,
                                                        model: failure.model,
                                                    })}
                                                </span>{' '}
                                                {failure.message}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                <div className="mt-3 flex flex-wrap gap-2">
                                    {active && (
                                        <Button
                                            danger
                                            block
                                            icon={<StopOutlined />}
                                            loading={action === 'cancel'}
                                            disabled={!batch.can_cancel}
                                            onClick={() => void handleCancel(batch)}
                                        >
                                            {batch.can_cancel
                                                ? t('batch.cancel')
                                                : t('batch.cancelRequested')}
                                        </Button>
                                    )}

                                    {!active && (
                                        <>
                                            {batch.can_retry && (
                                                <Button
                                                    type="primary"
                                                    block
                                                    icon={<ReloadOutlined />}
                                                    loading={action === 'retry'}
                                                    onClick={() => void handleRetry(batch)}
                                                >
                                                    {t('batch.retry', { count: batch.failed })}
                                                </Button>
                                            )}
                                            <Link href={`/books/${batch.book_id}`} className="flex-1">
                                                <Button block>{t('batch.openBook')}</Button>
                                            </Link>
                                            <Button
                                                block
                                                className="flex-1"
                                                onClick={() => dismissBatch(batch.id)}
                                            >
                                                {t('common.close')}
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            <Modal
                title={
                    detail
                        ? t('batch.detailTitle', {
                              page: detail.page_position,
                              model: detail.model,
                          })
                        : t('batch.detailFallbackTitle')
                }
                open={detail !== null}
                onCancel={() => setDetail(null)}
                footer={[
                    <Button key="copy" onClick={copyDetail}>
                        {t('common.copy')}
                    </Button>,
                    <Button key="close" type="primary" onClick={() => setDetail(null)}>
                        {t('common.close')}
                    </Button>,
                ]}
                width={640}
            >
                <p className="max-h-80 overflow-y-auto whitespace-pre-wrap break-words rounded-md bg-danger-surface p-3 font-mono text-xs text-danger-ink">
                    {detail?.message}
                </p>
            </Modal>
        </>
    );
}
