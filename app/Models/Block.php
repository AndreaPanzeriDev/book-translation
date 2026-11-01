<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Block extends Model
{
    protected $fillable = [
        'page_id',
        'position',
        'type',
        'text',
        'char_count',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
