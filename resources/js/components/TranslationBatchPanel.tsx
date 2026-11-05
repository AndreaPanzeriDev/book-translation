import {
    CloseOutlined,
    ReloadOutlined,
    StopOutlined,
} from '@ant-design/icons';
import { Link } from '@inertiajs/react';
import { App, Button, Modal, Progress, Tag, Tooltip } from 'antd';
import { useState } from 'react';

import { useTranslationBatch } from '@/contexts/TranslationBatchProvider';
import { errorMessage } from '@/lib/languages';
import type { TranslationBatch, TranslationBatchFailure } from '@/types';

const STATUS_LABEL: Record<TranslationBatch['status'], string> = {
    queued: 'In coda',
    running: 'In corso',
    completed: 'Completata',
    failed: 'Con errori',
    cancelled: 'Interrotta',
};

const STATUS_COLOR: Record<TranslationBatch['status'], string> = {
    queued: 'default',
    running: 'processing',
    completed: 'success',
    failed: 'error',
    cancelled: 'warning',
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
            void message.info('Interruzione richiesta: le richieste già partite verranno salvate.');
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
            void message.info('Traduzioni fallite rimesse in coda.');
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
            .then(() => message.success('Errore copiato negli appunti.'))
            .catch(() => message.error('Impossibile copiare l’errore.'));
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
                                            {batch.book_title ?? `Libro #${batch.book_id}`}
                                        </p>
                                        <p className="mt-0.5 text-[11px] uppercase tracking-wide text-ink-500">
                                            Traduzione · {batch.target_language.toUpperCase()}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1">
                                        <Tag color={STATUS_COLOR[batch.status]}>
                                            {STATUS_LABEL[batch.status]}
                                        </Tag>
                                        {!active && (
                                            <Tooltip title="Chiudi pannello">
                                                <Button
                                                    type="text"
                                                    size="small"
                                                    icon={<CloseOutlined />}
                                                    onClick={() => dismissBatch(batch.id)}
                                                    aria-label="Chiudi pannello"
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
                                        {batch.succeeded}{' '}
                                        {batch.succeeded === 1 ? 'riuscita' : 'riuscite'} ·{' '}
                                        {batch.failed}{' '}
                                        {batch.failed === 1 ? 'errore' : 'errori'}
                                        {batch.cancelled > 0 && ` · ${batch.cancelled} annullate`}
                                    </span>
                                </div>

                                {active && (
                                    <p className="mt-2 truncate text-xs text-ink-700">
                                        {batch.cancel_requested_at
                                            ? 'Interruzione richiesta: attendo le richieste in corso.'
                                            : batch.in_flight > 1
                                              ? `${batch.in_flight} richieste in corso`
                                              : batch.current_label
                                                ? `In corso: ${batch.current_label}`
                                                : 'In attesa del worker…'}
                                    </p>
                                )}

                                {batch.failures.length > 0 && (
                                    <div className="mt-2 max-h-32 space-y-1 overflow-y-auto rounded-md bg-red-50 p-2 text-[11px] text-red-700">
                                        {batch.failures.map((failure) => (
                                            <button
                                                key={failure.item_id}
                                                type="button"
                                                className="block w-full cursor-pointer truncate text-left hover:underline"
                                                title="Clicca per vedere l’errore completo"
                                                onClick={() => setDetail(failure)}
                                            >
                                                <span className="font-medium">
                                                    Pagina {failure.page_position} · {failure.model}:
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
                                            {batch.can_cancel ? 'Interrompi' : 'Interruzione richiesta'}
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
                                                    Riprova {batch.failed}
                                                </Button>
                                            )}
                                            <Link href={`/books/${batch.book_id}`} className="flex-1">
                                                <Button block>Apri libro</Button>
                                            </Link>
                                            <Button
                                                block
                                                className="flex-1"
                                                onClick={() => dismissBatch(batch.id)}
                                            >
                                                Chiudi
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
                    detail ? `Pagina ${detail.page_position} · ${detail.model}` : 'Dettaglio errore'
                }
                open={detail !== null}
                onCancel={() => setDetail(null)}
                footer={[
                    <Button key="copy" onClick={copyDetail}>
                        Copia
                    </Button>,
                    <Button key="close" type="primary" onClick={() => setDetail(null)}>
                        Chiudi
                    </Button>,
                ]}
                width={640}
            >
                <p className="max-h-80 overflow-y-auto whitespace-pre-wrap break-words rounded-md bg-red-50 p-3 font-mono text-xs text-red-700">
                    {detail?.message}
                </p>
            </Modal>
        </>
    );
}
