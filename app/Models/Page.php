<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $fillable = [
        'book_id',
        'chapter_id',
        'position',
        'chapter_position',
        'char_count',
        'source_hash',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('position');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class)->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'chapter_id' => $this->chapter_id,
            'chapter_position' => $this->chapter_position,
            'char_count' => $this->char_count,
            'source_hash' => $this->source_hash,
            'blocks' => $this->relationLoaded('blocks')
                ? $this->blocks->map(fn (Block $block) => [
                    'id' => $block->id,
                    'position' => $block->position,
                    'type' => $block->type,
                    'text' => $block->text,
                ])->values()->all()
                : null,
            'translations' => $this->relationLoaded('translations')
                ? $this->translations
                    ->map(fn (Translation $translation) => $translation->toClientArray())
                    ->values()
                    ->all()
                : null,
        ];
    }
}
