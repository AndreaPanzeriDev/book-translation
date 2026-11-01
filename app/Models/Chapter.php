<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chapter extends Model
{
    protected $fillable = [
        'book_id',
        'position',
        'title',
        'source_href',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('chapter_position');
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'title' => $this->title,
            'pages_count' => (int) ($this->pages_count ?? $this->pages()->count()),
            'first_page_position' => $this->pages()->min('position'),
        ];
    }
}
