<?php

namespace App\Models;

use App\Services\Translation\TranslationPrompt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    protected $fillable = [
        'page_id',
        'ai_account_id',
        'model',
        'target_language',
        'source_hash',
        'prompt_version',
        'params_hash',
        'key_hash',
        'status',
        'translated_blocks',
        'error',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'provider_response_id',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'translated_blocks' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AiAccount::class, 'ai_account_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'page_id' => $this->page_id,
            'ai_account_id' => $this->ai_account_id,
            'model' => $this->model,
            'target_language' => $this->target_language,
            'prompt_version' => $this->prompt_version,
            'is_current' => $this->prompt_version === TranslationPrompt::VERSION,
            'status' => $this->status,
            'translated_blocks' => $this->translated_blocks,
            'error' => $this->error,
            'total_tokens' => $this->total_tokens,
            'latency_ms' => $this->latency_ms,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
