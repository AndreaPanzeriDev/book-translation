<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Book extends Model
{
    protected $fillable = [
        'title',
        'author',
        'language',
        'original_filename',
        'file_path',
        'cover_path',
        'page_char_limit',
        'target_language',
    ];

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('position');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('position');
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'language' => $this->language,
            'cover_url' => $this->cover_url,
            'original_filename' => $this->original_filename,
            'page_char_limit' => $this->page_char_limit,
            'target_language' => $this->target_language,
            'pages_count' => (int) ($this->pages_count ?? $this->pages()->count()),
            'chapters_count' => (int) ($this->chapters_count ?? $this->chapters()->count()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function getCoverUrlAttribute(): ?string
    {
        if (! $this->cover_path) {
            return null;
        }

        return Storage::disk('public')->url($this->cover_path);
    }

    public function getPagesCountAttribute(): int
    {
        return $this->pages()->count();
    }
}
