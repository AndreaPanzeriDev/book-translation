<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModel extends Model
{
    protected $fillable = [
        'ai_account_id',
        'model_id',
        'label',
        'context_length',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AiAccount::class, 'ai_account_id');
    }
}
