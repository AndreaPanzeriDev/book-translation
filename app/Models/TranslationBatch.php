<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranslationBatch extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'book_id',
        'target_language',
        'locale',
        'status',
        'total_items',
        'cancel_requested_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'total_items' => 'integer',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TranslationBatchItem::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        $counts = $this->items()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pending = (int) ($counts[TranslationBatchItem::STATUS_PENDING] ?? 0);
        $running = (int) ($counts[TranslationBatchItem::STATUS_RUNNING] ?? 0);
        $completed = (int) ($counts[TranslationBatchItem::STATUS_COMPLETED] ?? 0);
        $failed = (int) ($counts[TranslationBatchItem::STATUS_FAILED] ?? 0);
        $cancelled = (int) ($counts[TranslationBatchItem::STATUS_CANCELLED] ?? 0);
        $total = max($this->total_items, $pending + $running + $completed + $failed + $cancelled);

        $current = $this->items()
            ->where('status', TranslationBatchItem::STATUS_RUNNING)
            ->orderBy('page_position')
            ->first(['page_position', 'model', 'slot_key']);

        $activePageIds = $this->items()
            ->where('status', TranslationBatchItem::STATUS_RUNNING)
            ->distinct()
            ->pluck('page_id')
            ->map(fn ($pageId) => (int) $pageId)
            ->values()
            ->all();

        $failures = $this->items()
            ->where('status', TranslationBatchItem::STATUS_FAILED)
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'page_id', 'page_position', 'slot_key', 'model', 'error'])
            ->map(fn (TranslationBatchItem $item) => [
                'item_id' => $item->id,
                'page_id' => $item->page_id,
                'page_position' => $item->page_position,
                'slot_key' => $item->slot_key,
                'model' => $item->model,
                'message' => $item->error ?? __('messages.batches.errors.unknown'),
            ])
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'book_title' => $this->book?->title,
            'target_language' => $this->target_language,
            'status' => $this->status,
            'total' => $total,
            'processed' => $total - $pending - $running,
            'pending' => $pending,
            'succeeded' => $completed,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'in_flight' => $running,
            'current_label' => $current
                ? __('messages.batches.current_label', [
                    'page' => $current->page_position,
                    'model' => $current->model,
                ])
                : null,
            'active_page_ids' => $activePageIds,
            'failures' => $failures,
            'can_cancel' => $this->isActive() && $this->cancel_requested_at === null,
            'can_retry' => $failed > 0 && ! $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'cancel_requested_at' => $this->cancel_requested_at?->toIso8601String(),
        ];
    }
}
