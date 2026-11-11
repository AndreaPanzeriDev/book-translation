<?php

namespace App\Services\Export;

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Services\Translation\TranslatedContent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BookExportBuilder
{
    public function __construct(private readonly TranslatedContent $content) {}

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

        $translations = $this->content->translationsFor($pages, $account, $model, $targetLanguage);
        $missingPages = $this->content->missingPages($pages, $translations);

        if ($missingPages->isNotEmpty()) {
            throw $this->incompleteException($chapters, $pages, $missingPages);
        }

        $pagesByChapter = $pages->groupBy('chapter_id');
        $chaptersData = [];

        foreach ($chapters as $chapter) {
            $chapterPages = $pagesByChapter->get($chapter->id, collect());
            $blocks = [];

            foreach ($chapterPages as $page) {
                $translatedBlocks = $this->content->blocksById($translations->get($page->id));

                foreach ($page->blocks as $block) {
                    $text = $translatedBlocks[$block->id] ?? null;

                    if (! $this->content->isTextValid($block, $text)) {
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
     * @return array{
     *     coverage: array<int, array{chapter_id: int, translated: int}>,
     *     missing_page_ids: array<int, int>
     * }
     */
    public function coverage(
        Book $book,
        AiAccount $account,
        string $model,
        string $targetLanguage,
    ): array {
        $pages = Page::query()
            ->where('book_id', $book->id)
            ->with(['blocks' => fn ($query) => $query->select('id', 'page_id', 'position', 'text')])
            ->orderBy('position')
            ->get(['id', 'chapter_id', 'position', 'source_hash']);

        $translations = $this->content->translationsFor($pages, $account, $model, $targetLanguage);
        $missing = $this->content->missingPages($pages, $translations)->keyBy('id');

        return [
            'coverage' => $pages
                ->groupBy('chapter_id')
                ->map(function (Collection $chapterPages, int|string $chapterId) use ($missing): array {
                    $missingCount = $chapterPages
                        ->filter(fn (Page $page) => $missing->has($page->id))
                        ->count();

                    return [
                        'chapter_id' => (int) $chapterId,
                        'translated' => $chapterPages->count() - $missingCount,
                    ];
                })
                ->sortKeys()
                ->filter(fn (array $row) => $row['translated'] > 0)
                ->values()
                ->all(),
            'missing_page_ids' => $missing
                ->keys()
                ->map(fn (int|string $pageId) => (int) $pageId)
                ->values()
                ->all(),
        ];
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
