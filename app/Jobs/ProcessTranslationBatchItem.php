<?php

namespace App\Jobs;

use App\Models\TranslationBatch;
use App\Models\TranslationBatchItem;
use App\Services\Translation\TranslationBatchService;
use App\Services\Translation\TranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTranslationBatchItem implements ShouldQueue
{
    use Queueable;

    /**
     * Un item può essere ritentato dalla coda dopo un crash del worker.
     * Gli errori del provider vengono invece gestiti e salvati sull'item.
     */
    public int $tries = 3;

    /**
     * Timeout del singolo job, letto da QUEUE_JOB_TIMEOUT. Deve restare
     * maggiore di AI_REQUEST_TIMEOUT.
     */
    public int $timeout = 360;

    /**
     * Un item rimasto "running" più a lungo del timeout del job appartiene a
     * un worker morto e può essere rieseguito. La soglia resta sotto
     * DB_QUEUE_RETRY_AFTER per permettere la ripresa al primo retry.
     */
    private const MIN_STALE_ITEM_MINUTES = 3;

    /**
     * Il lock impedisce a due libri diversi di usare lo stesso account seriale
     * nello stesso momento. Scade prima di DB_QUEUE_RETRY_AFTER, così un lock
     * rimasto orfano non blocca la ripresa dopo un crash del worker.
     */
    private const MIN_ACCOUNT_LOCK_SECONDS = 300;

    /**
     * Un takeover viene accodato quando un retry trova l'item ancora "running":
     * il worker precedente è morto, quindi l'item va ripreso senza attendere
     * la normale soglia di stale.
     */
    public function __construct(
        public readonly int $itemId,
        public readonly bool $takeover = false,
    ) {
        $this->timeout = max(30, (int) config('queue.translation_job_timeout', 360));
    }

    public function handle(TranslationService $translator, TranslationBatchService $batches): void
    {
        $item = TranslationBatchItem::with(['batch', 'page', 'account'])->find($this->itemId);

        if (! $item) {
            return;
        }

        if ($item->batch instanceof TranslationBatch) {
            App::setLocale($item->batch->locale ?: config('app.locale'));
        }

        if ($item->isTerminal()) {
            // Un secondo tentativo dello stesso job può arrivare dopo che
            // l'item è già stato salvato: allinea lo stato del batch e, se
            // serve, riprende la catena seriale rimasta interrotta.
            if ($item->batch instanceof TranslationBatch) {
                $batches->refresh($item->batch);
                $batches->dispatchNextSerialItem($item);
            }

            return;
        }

        $batch = $item->batch;

        if (! $batch instanceof TranslationBatch || $batch->isTerminal()) {
            $batches->markItemCancelled($item);

            return;
        }

        if ($this->isBeingProcessedByAnotherWorker($item) && ! $this->takeover) {
            // Se questo è un retry dopo un timeout, l'item potrebbe appartenere
            // a un worker morto non ancora considerato "stale": accoda un
            // takeover che lo riprende forzatamente.
            if ($this->attempts() > 1) {
                $batches->dispatchItemLater($item, 30, takeover: true);
            }

            return;
        }

        if ($batch->cancel_requested_at !== null) {
            $batches->markItemCancelled($item);

            return;
        }

        $account = $item->account;

        if (! $account) {
            $batches->markItemFailed($item->id, __('messages.batches.errors.account_missing'));

            return;
        }

        if (! $account->is_active) {
            $batches->markItemFailed($item->id, __('messages.batches.errors.account_disabled'));

            return;
        }

        $lock = null;

        if (! $account->isParallel()) {
            $lock = Cache::lock('translation-account:'.$account->id, $this->accountLockSeconds());

            if (! $lock->get()) {
                // L'account è occupato da un altro libro: accoda un nuovo job
                // invece di consumare i tentativi con release().
                $batches->dispatchItemLater($item, 15, takeover: $this->takeover);

                return;
            }
        }

        try {
            $item->refresh();

            if ($item->isTerminal()) {
                return;
            }

            $batch = $item->batch;

            if (! $batch instanceof TranslationBatch || $batch->isTerminal()) {
                $batches->markItemCancelled($item);

                return;
            }

            if ($batch->cancel_requested_at !== null) {
                $batches->markItemCancelled($item);

                return;
            }

            if (! $this->claim($item->id, force: $this->takeover)) {
                return;
            }

            $item->refresh();
            $batches->refresh($batch);

            $translation = $translator->translate(
                $item->page,
                $account,
                $item->model,
                $batch->target_language,
            );

            $item->forceFill([
                'status' => TranslationBatchItem::STATUS_COMPLETED,
                'error' => null,
                'translation_id' => $translation->id,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('Traduzione batch non riuscita.', [
                'batch_item_id' => $item->id,
                'batch_id' => $item->translation_batch_id,
                'page_id' => $item->page_id,
                'account_id' => $item->ai_account_id,
                'exception' => $e,
            ]);

            $item->forceFill([
                'status' => TranslationBatchItem::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'translation_id' => null,
                'finished_at' => now(),
            ])->save();
        } finally {
            $lock?->release();
        }

        $batches->refresh($item->batch);
        $batches->dispatchNextSerialItem($item->fresh());
    }

    /**
     * Chiamato dal worker quando il job esaurisce i tentativi (per esempio un
     * timeout ripetuto). Senza questo, l'item resterebbe "running" per sempre
     * e il batch non potrebbe mai essere interrotto o ritentato.
     */
    public function failed(?Throwable $exception): void
    {
        $item = TranslationBatchItem::with('batch')->find($this->itemId);

        if (! $item) {
            return;
        }

        if ($item->batch instanceof TranslationBatch) {
            App::setLocale($item->batch->locale ?: config('app.locale'));
        }

        $batches = app(TranslationBatchService::class);

        if ($item->isTerminal()) {
            // Il job può morire dopo aver salvato l'esito ma prima di aver
            // accodato il prossimo item seriale: riprende la catena.
            if ($item->batch instanceof TranslationBatch) {
                $batches->refresh($item->batch);
                $batches->dispatchNextSerialItem($item);
            }

            return;
        }

        $batches->markItemFailed(
            $item->id,
            $exception?->getMessage() ?: __('messages.batches.errors.interrupted'),
        );
    }

    /**
     * Reclama l'item in modo atomico: un solo job può passare da pending a
     * running; un item running può essere ripreso solo se è considerato
     * orfano di un worker morto.
     */
    private function claim(int $itemId, bool $force = false): bool
    {
        $startedAt = now();
        $attributes = [
            'status' => TranslationBatchItem::STATUS_RUNNING,
            'error' => null,
            'started_at' => $startedAt,
            'finished_at' => null,
            'updated_at' => $startedAt,
        ];

        $claimed = TranslationBatchItem::query()
            ->whereKey($itemId)
            ->where('status', TranslationBatchItem::STATUS_PENDING)
            ->update($attributes);

        if ($claimed > 0) {
            return true;
        }

        $query = TranslationBatchItem::query()
            ->whereKey($itemId)
            ->where('status', TranslationBatchItem::STATUS_RUNNING);

        if (! $force) {
            $staleBefore = now()->subMinutes($this->staleItemMinutes());

            $query->where(function ($query) use ($staleBefore) {
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<=', $staleBefore);
            });
        }

        return $query->update($attributes) > 0;
    }

    private function isBeingProcessedByAnotherWorker(TranslationBatchItem $item): bool
    {
        return $item->status === TranslationBatchItem::STATUS_RUNNING
            && $item->started_at !== null
            && $item->started_at->gt(now()->subMinutes($this->staleItemMinutes()));
    }

    private function staleItemMinutes(): int
    {
        // Timeout del job + 2 minuti di margine, con un minimo di 3 minuti.
        return max(self::MIN_STALE_ITEM_MINUTES, (int) ceil($this->timeout / 60) + 2);
    }

    private function accountLockSeconds(): int
    {
        return max(self::MIN_ACCOUNT_LOCK_SECONDS, $this->timeout + 120);
    }
}
