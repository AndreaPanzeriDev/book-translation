<?php

namespace App\Services\Export;

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use App\Services\Translation\TranslationPrompt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BookExportBuilder
{
    /**
     * @param  array<int, int>  $chapterIds
     * @return array{
     *     title: string,
     *     author: ?string,
     *     language: string,
     *     cover_path: ?string,
     *     cover_extension: ?string,
     *     cover_mime: ?string,
     *     chapters: array<int, array{
     *         id: int,
     *         position: int,
     *         title: string,
     *         blocks: array<int, array{type: string, text: string}>
     *     }>
     * }
     *
     * @throws IncompleteTranslationException
     */
    public function build(
        Book $book,
        array $chapterIds,
        AiAccount $account,
        string $model,
        string $targetLanguage,
    ): array {
        $chapters = $book->chapters()
            ->whereIn('id', $chapterIds)
            ->orderBy('position')
            ->get();

        $pages = Page::query()
            ->whereIn('chapter_id', $chapters->pluck('id'))
            ->with(['blocks' => fn ($query) => $query->orderBy('position')])
            ->orderBy('position')
            ->get();

        $translations = $this->translationsFor($pages, $account, $model, $targetLanguage);
        $missingPages = $this->missingPages($pages, $translations);

        if ($missingPages->isNotEmpty()) {
            throw $this->incompleteException($chapters, $pages, $missingPages);
        }

        $pagesByChapter = $pages->groupBy('chapter_id');
        $chaptersData = [];

        foreach ($chapters as $chapter) {
            $chapterPages = $pagesByChapter->get($chapter->id, collect());
            $blocks = [];

            foreach ($chapterPages as $page) {
                $translatedBlocks = $this->translatedBlocksById($translations->get($page->id));

                foreach ($page->blocks as $block) {
                    $text = $translatedBlocks[$block->id] ?? null;

                    if (! is_string($text)) {
                        throw $this->incompleteException(
                            $chapters,
                            $pages,
                            collect([$page]),
                        );
                    }

                    $blocks[] = [
                        'type' => $block->type,
                        'text' => $text,
                    ];
                }
            }

            $chaptersData[] = [
                'id' => $chapter->id,
                'position' => $chapter->position,
                'title' => $chapter->title,
                'blocks' => $blocks,
            ];
        }

        [$coverPath, $coverExtension, $coverMime] = $this->cover($book);

        return [
            'title' => $book->title,
            'author' => $book->author,
            'language' => $targetLanguage,
            'cover_path' => $coverPath,
            'cover_extension' => $coverExtension,
            'cover_mime' => $coverMime,
            'chapters' => $chaptersData,
        ];
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return Collection<int, Translation>
     */
    private function translationsFor(
        Collection $pages,
        AiAccount $account,
        string $model,
        string $targetLanguage,
    ): Collection {
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
     * @param  Collection<int, Page>  $pages
     * @param  Collection<int, Translation>  $translations
     * @return Collection<int, Page>
     */
    private function missingPages(Collection $pages, Collection $translations): Collection
    {
        return $pages->filter(function (Page $page) use ($translations) {
            $translation = $translations->get($page->id);

            if (! $translation) {
                return true;
            }

            $translatedBlocks = $this->translatedBlocksById($translation);

            foreach ($page->blocks as $block) {
                $text = $translatedBlocks[$block->id] ?? null;

                if (! is_string($text) || trim($text) === '') {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return array<int, string>
     */
    private function translatedBlocksById(?Translation $translation): array
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

    /**
     * @param  Collection<int, Chapter>  $chapters
     * @param  Collection<int, Page>  $pages
     * @param  Collection<int, Page>  $missingPages
     */
    private function incompleteException(
        Collection $chapters,
        Collection $pages,
        Collection $missingPages,
    ): IncompleteTranslationException {
        $pagesByChapter = $pages->groupBy('chapter_id');
        $missingByChapter = $missingPages->groupBy('chapter_id');
        $missingChapters = [];

        foreach ($chapters as $chapter) {
            $chapterMissing = $missingByChapter->get($chapter->id);

            if (! $chapterMissing || $chapterMissing->isEmpty()) {
                continue;
            }

            $total = $pagesByChapter->get($chapter->id, collect())->count();

            $missingChapters[] = [
                'chapter_id' => $chapter->id,
                'title' => $chapter->title,
                'translated' => $total - $chapterMissing->count(),
                'total' => $total,
            ];
        }

        return new IncompleteTranslationException($missingChapters, $missingPages->count());
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function cover(Book $book): array
    {
        if (! $book->cover_path || ! Storage::disk('public')->exists($book->cover_path)) {
            return [null, null, null];
        }

        $extension = strtolower(pathinfo($book->cover_path, PATHINFO_EXTENSION)) ?: 'jpg';

        $mime = match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return [
            Storage::disk('public')->path($book->cover_path),
            $extension,
            $mime,
        ];
    }
}
