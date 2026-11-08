<?php

namespace App\Services\Translation;

use App\Jobs\ProcessTranslationBatchItem;
use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Translation;
use App\Models\TranslationBatch;
use App\Models\TranslationBatchItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TranslationBatchService
{
    /**
     * Dopo questo tempo un item "dispatched" ma mai avviato viene considerato
     * perso dalla coda e torna disponibile per un nuovo dispatch.
     */
    private const DISPATCH_STALE_MINUTES = 15;

    public function __construct(private readonly TranslationService $translator) {}

    /**
     * @param  array<int, array{slot_key: string, account_id: int, model: string}>  $slots
     * @param  array<int, int>  $pageIds
     * @return array{batch: ?TranslationBatch, message: ?string}
     */
    public function create(Book $book, array $slots, array $pageIds, string $targetLanguage): array
    {
        return DB::transaction(function () use ($book, $slots, $pageIds, $targetLanguage) {
            $lockedBook = Book::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();

            $hasActiveBatch = TranslationBatch::query()
                ->where('book_id', $lockedBook->id)
                ->whereIn('status', [TranslationBatch::STATUS_QUEUED, TranslationBatch::STATUS_RUNNING])
                ->exists();

            if ($hasActiveBatch) {
                throw new TranslationBatchException(
                    __('messages.batches.already_active'),
                    409,
                );
            }

            $accounts = AiAccount::query()
                ->whereIn('id', collect($slots)->pluck('account_id')->unique()->all())
                ->get()
                ->keyBy('id');

            $pages = $lockedBook->pages()
                ->whereIn('id', $pageIds)
                ->get(['id', 'position', 'source_hash'])
                ->keyBy('id');

            $candidates = [];

            foreach ($pageIds as $pageId) {
                $page = $pages->get($pageId);

                if (! $page) {
                    continue;
                }

                foreach ($slots as $slot) {
                    $account = $accounts->get((int) $slot['account_id']);
                    $model = trim((string) $slot['model']);

                    if (! $account || ! $account->is_active || $model === '') {
                        continue;
                    }

                    $signature = $page->id.'|'.$account->id.'|'.$model;

                    if (isset($candidates[$signature])) {
                        continue;
                    }

                    $candidates[$signature] = [
                        'page' => $page,
                        'account' => $account,
                        'model' => $model,
                        'slot_key' => $slot['slot_key'],
                        'key_hash' => $this->translator->keyHash($page, $account, $model, $targetLanguage),
                    ];
                }
            }

            $completedHashes = [];
            $candidateHashes = array_values(array_column($candidates, 'key_hash'));

            foreach (array_chunk($candidateHashes, 500) as $hashes) {
                $completedHashes = array_merge(
                    $completedHashes,
                    Translation::query()
                        ->whereIn('key_hash', $hashes)
                        ->where('status', 'completed')
                        ->pluck('key_hash')
                        ->all(),
                );
            }

            $completed = array_fill_keys($completedHashes, true);
            $items = [];

            foreach ($candidates as $candidate) {
                if (isset($completed[$candidate['key_hash']])) {
                    continue;
                }

                $items[] = [
                    'page_id' => $candidate['page']->id,
                    'ai_account_id' => $candidate['account']->id,
                    'model' => $candidate['model'],
                    'slot_key' => $candidate['slot_key'],
                    'page_position' => $candidate['page']->position,
                ];
            }

            if ($items === []) {
                return [
                    'batch' => null,
                    'message' => __('messages.batches.all_cached'),
                ];
            }

            $batch = TranslationBatch::create([
                'book_id' => $lockedBook->id,
                'target_language' => $targetLanguage,
                'locale' => app()->getLocale(),
                'status' => TranslationBatch::STATUS_QUEUED,
                'total_items' => count($items),
            ]);

            $now = now();
            $rows = [];

            foreach ($items as $item) {
                $rows[] = [
                    'translation_batch_id' => $batch->id,
                    'page_id' => $item['page_id'],
                    'ai_account_id' => $item['ai_account_id'],
                    'model' => $item['model'],
                    'slot_key' => $item['slot_key'],
                    'page_position' => $item['page_position'],
                    'status' => TranslationBatchItem::STATUS_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                TranslationBatchItem::query()->insert($chunk);
            }

            $this->dispatchPendingItems($batch, $accounts);

            return [
                'batch' => $batch->fresh(['book']),
                'message' => null,
            ];
        });
    }

    public function cancel(TranslationBatch $batch): TranslationBatch
    {
        DB::transaction(function () use ($batch) {
            $locked = TranslationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->isTerminal()) {
                return;
            }

            if ($locked->cancel_requested_at === null) {
                $locked->forceFill(['cancel_requested_at' => now()])->save();
            }

            $locked->items()
                ->where('status', TranslationBatchItem::STATUS_PENDING)
                ->update([
                    'status' => TranslationBatchItem::STATUS_CANCELLED,
                    'error' => __('messages.batches.errors.cancelled'),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return $this->refresh($batch->fresh());
    }

    public function retryFailed(TranslationBatch $batch): TranslationBatch
    {
        $bookId = $batch->book_id;

        $reset = DB::transaction(function () use ($batch, $bookId) {
            // Stesso ordine di lock di create(): prima il libro, poi il batch,
            // così due richieste concorrenti non creano due batch attivi.
            Book::query()->whereKey($bookId)->lockForUpdate()->firstOrFail();
            $locked = TranslationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->isActive()) {
                throw new TranslationBatchException(__('messages.batches.still_running'), 409);
            }

            $hasOtherActiveBatch = TranslationBatch::query()
                ->where('book_id', $bookId)
                ->whereKeyNot($locked->id)
                ->whereIn('status', [TranslationBatch::STATUS_QUEUED, TranslationBatch::STATUS_RUNNING])
                ->exists();

            if ($hasOtherActiveBatch) {
                throw new TranslationBatchException(
                    __('messages.batches.already_active_other'),
                    409,
                );
            }

            $failedIds = $locked->items()
                ->where('status', TranslationBatchItem::STATUS_FAILED)
                ->pluck('id');

            if ($failedIds->isEmpty()) {
                throw new TranslationBatchException(__('messages.batches.nothing_to_retry'));
            }

            $locked->items()
                ->whereIn('id', $failedIds)
                ->update([
                    'status' => TranslationBatchItem::STATUS_PENDING,
                    'error' => null,
                    'translation_id' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'dispatched_at' => null,
                    'updated_at' => now(),
                ]);

            $locked->forceFill([
                'cancel_requested_at' => null,
                'finished_at' => null,
                'status' => TranslationBatch::STATUS_QUEUED,
            ])->save();

            return $locked;
        });

        $batch = $this->refresh($reset);
        $this->dispatchPendingItems($batch);

        return $batch->fresh(['book']);
    }

    public function refresh(TranslationBatch $batch): TranslationBatch
    {
        DB::transaction(function () use ($batch) {
            $locked = TranslationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $counts = $locked->items()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $pending = (int) ($counts[TranslationBatchItem::STATUS_PENDING] ?? 0);
            $running = (int) ($counts[TranslationBatchItem::STATUS_RUNNING] ?? 0);
            $completed = (int) ($counts[TranslationBatchItem::STATUS_COMPLETED] ?? 0);
            $failed = (int) ($counts[TranslationBatchItem::STATUS_FAILED] ?? 0);
            $cancelled = (int) ($counts[TranslationBatchItem::STATUS_CANCELLED] ?? 0);
            $started = $running + $completed + $failed + $cancelled > 0;
            $finished = $pending === 0 && $running === 0;

            if ($finished) {
                if ($locked->cancel_requested_at !== null || $cancelled > 0) {
                    $status = TranslationBatch::STATUS_CANCELLED;
                } elseif ($failed > 0) {
                    $status = TranslationBatch::STATUS_FAILED;
                } else {
                    $status = TranslationBatch::STATUS_COMPLETED;
                }
            } elseif ($running > 0) {
                $status = TranslationBatch::STATUS_RUNNING;
            } else {
                $status = $locked->cancel_requested_at !== null
                    ? TranslationBatch::STATUS_CANCELLED
                    : TranslationBatch::STATUS_QUEUED;
            }

            $locked->forceFill([
                'status' => $status,
                'total_items' => max(
                    $locked->total_items,
                    $pending + $running + $completed + $failed + $cancelled,
                ),
                'started_at' => $locked->started_at ?? ($started ? now() : null),
                'finished_at' => $finished ? ($locked->finished_at ?? now()) : null,
            ])->save();
        });

        return $batch->fresh(['book']);
    }

    public function markItemFailed(int $itemId, string $message): void
    {
        $item = TranslationBatchItem::with('batch')->find($itemId);

        if (! $item || $item->isTerminal()) {
            return;
        }

        $item->forceFill([
            'status' => TranslationBatchItem::STATUS_FAILED,
            'error' => Str::limit($message, 2000),
            'finished_at' => now(),
        ])->save();

        if ($item->batch) {
            $this->refresh($item->batch);
        }

        $this->dispatchNextItem($item);
    }

    public function markItemCancelled(TranslationBatchItem $item): void
    {
        if ($item->isTerminal()) {
            return;
        }

        $item->forceFill([
            'status' => TranslationBatchItem::STATUS_CANCELLED,
            'error' => __('messages.batches.errors.cancelled'),
            'finished_at' => now(),
        ])->save();

        if ($item->batch) {
            $this->refresh($item->batch);
        }

        $this->dispatchNextItem($item);
    }

    public function dispatchNextItem(TranslationBatchItem $item): void
    {
        $batch = $item->batch()->first();

        if (! $batch) {
            return;
        }

        $account = $item->account()->first();

        if (! $account) {
            return;
        }

        if ($account->isParallel()) {
            // Il limite è per account e vale anche per libri diversi: quando
            // un item termina libera uno slot per qualsiasi batch in attesa.
            $this->dispatchParallelItems($account);

            return;
        }

        if ($batch->cancel_requested_at !== null) {
            return;
        }

        $next = $batch->items()
            ->where('ai_account_id', $item->ai_account_id)
            ->where('status', TranslationBatchItem::STATUS_PENDING)
            ->orderBy('page_position')
            ->orderBy('id')
            ->first();

        if ($next) {
            $this->dispatchItem($next);
        }
    }

    /**
     * @param  Collection<int, AiAccount>|null  $accounts
     */
    private function dispatchPendingItems(TranslationBatch $batch, ?Collection $accounts = null): void
    {
        $accounts ??= AiAccount::query()
            ->whereIn('id', $batch->items()
                ->where('status', TranslationBatchItem::STATUS_PENDING)
                ->whereNotNull('ai_account_id')
                ->select('ai_account_id'))
            ->get()
            ->keyBy('id');

        $items = $batch->items()
            ->where('status', TranslationBatchItem::STATUS_PENDING)
            ->orderBy('page_position')
            ->orderBy('id')
            ->get()
            ->groupBy('ai_account_id');

        foreach ($items as $accountId => $group) {
            $account = $accounts->get((int) $accountId);

            if (! $account) {
                continue;
            }

            if ($account->isParallel()) {
                $this->dispatchParallelItems($account);

                continue;
            }

            $first = $group->first();

            if ($first) {
                $this->dispatchItem($first);
            }
        }
    }

    /**
     * Accoda gli item pendenti dell'account rispettando max_parallel_requests.
     * Il conteggio è globale per account, così il limite vale anche quando
     * più libri usano lo stesso provider contemporaneamente.
     */
    private function dispatchParallelItems(AiAccount $account): void
    {
        $itemIds = DB::transaction(function () use ($account): array {
            $locked = AiAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof AiAccount || ! $locked->isParallel()) {
                return [];
            }

            $this->releaseStaleDispatches($locked);

            $inFlight = TranslationBatchItem::query()
                ->where('ai_account_id', $locked->id)
                ->whereNotNull('dispatched_at')
                ->whereNull('finished_at')
                ->count();

            $slots = $locked->parallelLimit() - $inFlight;

            if ($slots <= 0) {
                return [];
            }

            $items = TranslationBatchItem::query()
                ->where('ai_account_id', $locked->id)
                ->where('status', TranslationBatchItem::STATUS_PENDING)
                ->whereNull('dispatched_at')
                ->orderBy('id')
                ->limit($slots)
                ->lockForUpdate()
                ->get(['id']);

            if ($items->isEmpty()) {
                return [];
            }

            $ids = $items->pluck('id')->all();

            TranslationBatchItem::query()
                ->whereIn('id', $ids)
                ->update(['dispatched_at' => now()]);

            return $ids;
        });

        foreach ($itemIds as $itemId) {
            $this->dispatchItemById($itemId);
        }
    }

    private function releaseStaleDispatches(AiAccount $account): void
    {
        TranslationBatchItem::query()
            ->where('ai_account_id', $account->id)
            ->where('status', TranslationBatchItem::STATUS_PENDING)
            ->whereNotNull('dispatched_at')
            ->whereNull('started_at')
            ->where('dispatched_at', '<=', now()->subMinutes(self::DISPATCH_STALE_MINUTES))
            ->update(['dispatched_at' => null]);
    }

    public function dispatchItemLater(TranslationBatchItem $item, int $seconds, bool $takeover = false): void
    {
        ProcessTranslationBatchItem::dispatch($item->id, $takeover)
            ->onQueue('translations')
            ->delay($seconds);
    }

    private function dispatchItem(TranslationBatchItem $item): void
    {
        $this->dispatchItemById($item->id);
    }

    private function dispatchItemById(int $itemId): void
    {
        ProcessTranslationBatchItem::dispatch($itemId)
            ->onQueue('translations')
            ->afterCommit();
    }
}
