import { App } from 'antd';
import axios from 'axios';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';

import type { TranslationBatch, TranslationBatchSlot } from '@/types';

export interface StartTranslationBatchPayload {
    slots: TranslationBatchSlot[];
    page_ids: number[];
    target_language: string;
}

export interface StartTranslationBatchResult {
    batch: TranslationBatch | null;
    message: string | null;
}

interface TranslationBatchContextValue {
    batches: TranslationBatch[];
    batchesByBook: Record<number, TranslationBatch>;
    startBatch: (
        bookId: number,
        payload: StartTranslationBatchPayload,
    ) => Promise<StartTranslationBatchResult>;
    cancelBatch: (batchId: number) => Promise<TranslationBatch>;
    retryBatch: (batchId: number) => Promise<TranslationBatch>;
    dismissBatch: (batchId: number) => void;
    isBookTranslating: (bookId: number) => boolean;
}

const TranslationBatchContext = createContext<TranslationBatchContextValue | null>(null);

const POLL_INTERVAL_MS = 2000;

function isActiveStatus(status: TranslationBatch['status']): boolean {
    return status === 'queued' || status === 'running';
}

export function TranslationBatchProvider({ children }: { children: ReactNode }) {
    const { t } = useTranslation();
    const { message } = App.useApp();
    const [batchesById, setBatchesById] = useState<Record<number, TranslationBatch>>({});
    const knownStatuses = useRef<Record<number, TranslationBatch['status']>>({});

    const mergeBatches = useCallback((incoming: TranslationBatch[]) => {
        if (incoming.length === 0) {
            return;
        }

        setBatchesById((previous) => {
            const next = { ...previous };

            for (const batch of incoming) {
                // Tiene un solo batch per libro: quando ne parte uno nuovo,
                // il vecchio pannello terminale non deve riapparire.
                for (const [id, existing] of Object.entries(next)) {
                    const existingId = Number(id);

                    if (
                        existingId !== batch.id &&
                        existing.book_id === batch.book_id &&
                        !isActiveStatus(existing.status)
                    ) {
                        delete next[existingId];
                        delete knownStatuses.current[existingId];
                    }
                }

                next[batch.id] = batch;
            }

            return next;
        });
    }, []);

    useEffect(() => {
        let cancelled = false;

        axios
            .get<{ batches: TranslationBatch[] }>('/translation-batches')
            .then(({ data }) => {
                if (!cancelled) {
                    mergeBatches(data.batches);
                }
            })
            .catch(() => {
                // Al mount l'app può non avere batch attivi; il polling riproverà
                // appena ne esiste uno.
            });

        return () => {
            cancelled = true;
        };
    }, [mergeBatches]);

    const batches = useMemo(
        () => Object.values(batchesById).sort((a, b) => b.id - a.id),
        [batchesById],
    );

    useEffect(() => {
        for (const batch of batches) {
            const previous = knownStatuses.current[batch.id];
            const terminal = !isActiveStatus(batch.status);

            if (previous && isActiveStatus(previous) && terminal) {
                const book = batch.book_title ?? t('batch.libro');

                if (batch.status === 'completed') {
                    void message.success(
                        t('batch.notificationCompleted', {
                            book,
                            count: batch.succeeded,
                        }),
                    );
                } else if (batch.status === 'failed') {
                    void message.warning(
                        t('batch.notificationFailed', {
                            book,
                            count: batch.failed,
                        }),
                    );
                }
            }

            knownStatuses.current[batch.id] = batch.status;
        }
    }, [batches, message, t]);

    const activeKey = useMemo(
        () =>
            batches
                .filter((batch) => isActiveStatus(batch.status))
                .map((batch) => batch.id)
                .sort((a, b) => a - b)
                .join(','),
        [batches],
    );

    useEffect(() => {
        if (activeKey === '') {
            return;
        }

        const ids = activeKey.split(',').map(Number);
        let disposed = false;
        let timer: number | null = null;

        const schedule = () => {
            if (disposed) {
                return;
            }

            timer = window.setTimeout(() => {
                void poll();
            }, POLL_INTERVAL_MS);
        };

        const poll = async () => {
            if (disposed) {
                return;
            }

            if (document.hidden) {
                schedule();

                return;
            }

            try {
                const { data } = await axios.get<{ batches: TranslationBatch[] }>(
                    '/translation-batches',
                    { params: { ids } },
                );

                if (!disposed) {
                    mergeBatches(data.batches);
                }
            } catch {
                // Errore transitorio: il prossimo tick riprova.
            }

            schedule();
        };

        const onVisibilityChange = () => {
            if (document.hidden || disposed) {
                return;
            }

            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }

            void poll();
        };

        schedule();
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            disposed = true;

            if (timer !== null) {
                window.clearTimeout(timer);
            }

            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, [activeKey, mergeBatches]);

    const startBatch = useCallback(
        async (bookId: number, payload: StartTranslationBatchPayload) => {
            const { data } = await axios.post<StartTranslationBatchResult>(
                `/books/${bookId}/translation-batches`,
                payload,
            );

            if (data.batch) {
                mergeBatches([data.batch]);
            }

            return data;
        },
        [mergeBatches],
    );

    const cancelBatch = useCallback(
        async (batchId: number) => {
            const { data } = await axios.post<{ batch: TranslationBatch }>(
                `/translation-batches/${batchId}/cancel`,
            );

            mergeBatches([data.batch]);

            return data.batch;
        },
        [mergeBatches],
    );

    const retryBatch = useCallback(
        async (batchId: number) => {
            const { data } = await axios.post<{ batch: TranslationBatch }>(
                `/translation-batches/${batchId}/retry`,
            );

            mergeBatches([data.batch]);

            return data.batch;
        },
        [mergeBatches],
    );

    const dismissBatch = useCallback((batchId: number) => {
        setBatchesById((previous) => {
            const batch = previous[batchId];

            if (!batch || isActiveStatus(batch.status)) {
                return previous;
            }

            const next = { ...previous };
            delete next[batchId];
            delete knownStatuses.current[batchId];

            return next;
        });
    }, []);

    const batchesByBook = useMemo(() => {
        const map: Record<number, TranslationBatch> = {};

        for (const batch of batches) {
            const current = map[batch.book_id];

            if (!current || (!isActiveStatus(current.status) && isActiveStatus(batch.status))) {
                map[batch.book_id] = batch;
            }
        }

        return map;
    }, [batches]);

    const isBookTranslating = useCallback(
        (bookId: number) => {
            const batch = batchesByBook[bookId];

            return batch !== undefined && isActiveStatus(batch.status);
        },
        [batchesByBook],
    );

    const value = useMemo<TranslationBatchContextValue>(
        () => ({
            batches,
            batchesByBook,
            startBatch,
            cancelBatch,
            retryBatch,
            dismissBatch,
            isBookTranslating,
        }),
        [batches, batchesByBook, startBatch, cancelBatch, retryBatch, dismissBatch, isBookTranslating],
    );

    return (
        <TranslationBatchContext.Provider value={value}>
            {children}
        </TranslationBatchContext.Provider>
    );
}

export function useTranslationBatch(): TranslationBatchContextValue {
    const context = useContext(TranslationBatchContext);

    if (!context) {
        throw new Error('useTranslationBatch deve essere usato dentro TranslationBatchProvider.');
    }

    return context;
}
