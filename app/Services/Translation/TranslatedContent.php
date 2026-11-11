<?php

namespace App\Services\Translation;

use App\Models\AiAccount;
use App\Models\Block;
use App\Models\Page;
use App\Models\Translation;
use Illuminate\Support\Collection;

class TranslatedContent
{
    /**
     * Latest completed, current-version translation for each page, matching
     * the page's source hash.
     *
     * @param  Collection<int, Page>  $pages
     * @return Collection<int, Translation>
     */
    public function translationsFor(
        Collection $pages,
        AiAccount $account,
        string $model,
        string $targetLanguage,
    ): Collection {
        if ($pages->isEmpty()) {
            return collect();
        }

        $pageById = $pages->keyBy('id');

        return Translation::query()
            ->whereIn('page_id', $pages->pluck('id'))
            ->where('status', 'completed')
            ->where('prompt_version', TranslationPrompt::VERSION)
            ->where('target_language', $targetLanguage)
            ->where('ai_account_id', $account->id)
            ->where('model', $model)
            ->orderBy('id')
            ->get(['id', 'page_id', 'source_hash', 'translated_blocks'])
            ->keyBy('page_id')
            ->filter(
                fn (Translation $translation) => isset($pageById[$translation->page_id])
                    && $pageById[$translation->page_id]->source_hash === $translation->source_hash,
            );
    }

    /**
     * Pages whose translation exists and covers every non-empty source block.
     *
     * @param  Collection<int, Page>  $pages
     * @param  Collection<int, Translation>  $translations
     * @return Collection<int, Page>
     */
    public function missingPages(Collection $pages, Collection $translations): Collection
    {
        return $pages->filter(
            fn (Page $page) => ! $this->isComplete($page, $translations->get($page->id)),
        );
    }

    /**
     * A page is complete when every non-empty source block has translated text.
     */
    public function isComplete(Page $page, ?Translation $translation): bool
    {
        if (! $translation) {
            return false;
        }

        $translatedBlocks = $this->blocksById($translation);

        foreach ($page->blocks as $block) {
            if (! $this->isTextValid($block, $translatedBlocks[$block->id] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function isTextValid(Block $block, ?string $text): bool
    {
        if (! is_string($text)) {
            return false;
        }

        return trim($text) !== '' || trim($block->text) === '';
    }

    /**
     * @return array<int, string>
     */
    public function blocksById(?Translation $translation): array
    {
        $blocks = [];

        foreach ($translation?->translated_blocks ?? [] as $item) {
            if (! is_array($item) || ! isset($item['block_id'], $item['text']) || ! is_string($item['text'])) {
                continue;
            }

            $blocks[(int) $item['block_id']] = $item['text'];
        }

        return $blocks;
    }
}
