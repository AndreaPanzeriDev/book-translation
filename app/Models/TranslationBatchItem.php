<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationBatchItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'translation_batch_id',
        'page_id',
        'ai_account_id',
        'model',
        'slot_key',
        'page_position',
        'status',
        'error',
        'translation_id',
        'started_at',
        'finished_at',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'page_position' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TranslationBatch::class, 'translation_batch_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AiAccount::class, 'ai_account_id');
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
