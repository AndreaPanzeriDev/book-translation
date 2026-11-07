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

    public function translationBatches(): HasMany
    {
        return $this->hasMany(TranslationBatch::class);
    }

    /**
     * Recupera l'ultimo account e modello usati per ogni slot di traduzione.
     *
     * @return array{
     *     a: array{account_id: int, model: string}|null,
     *     b: array{account_id: int, model: string}|null
     * }
     */
    public function lastUsedSlots(): array
    {
        $latestItemIds = TranslationBatchItem::query()
            ->selectRaw('MAX(id) as latest_id')
            ->whereIn('translation_batch_id', $this->translationBatches()->select('id'))
            ->whereIn('slot_key', ['a', 'b'])
            ->groupBy('slot_key')
            ->get()
            ->pluck('latest_id');

        $items = TranslationBatchItem::query()
            ->whereIn('id', $latestItemIds)
            ->get(['slot_key', 'ai_account_id', 'model'])
            ->keyBy('slot_key');

        $slots = [];

        foreach (['a', 'b'] as $slotKey) {
            $item = $items->get($slotKey);

            $slots[$slotKey] = $item && $item->ai_account_id !== null
                ? [
                    'account_id' => (int) $item->ai_account_id,
                    'model' => $item->model,
                ]
                : null;
        }

        if ($slots['a'] === null) {
            $translation = Translation::query()
                ->whereHas('page', fn ($query) => $query->where('book_id', $this->id))
                ->whereNotNull('ai_account_id')
                ->latest('id')
                ->first(['ai_account_id', 'model']);

            if ($translation) {
                $slots['a'] = [
                    'account_id' => (int) $translation->ai_account_id,
                    'model' => $translation->model,
                ];
            }
        }

        return $slots;
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
