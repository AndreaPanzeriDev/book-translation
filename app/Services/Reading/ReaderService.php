<?php

namespace App\Services\Reading;

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use App\Services\Translation\TranslatedContent;
use App\Services\Translation\TranslationPrompt;
use Illuminate\Support\Collection;

class ReaderService
{
    public function __construct(private readonly TranslatedContent $content) {}

    /**
     * Every completed, current translation available for the book, grouped by
     * account, model and target language.
     *
     * @return array<int, array{
     *     account_id: int,
     *     account_name: string,
     *     account_active: bool,
     *     model: string,
     *     target_language: string,
     *     pages_count: int
     * }>
     */
    public function versions(Book $book): array
    {
        $rows = Translation::query()
            ->join('pages', 'pages.id', '=', 'translations.page_id')
            ->where('pages.book_id', $book->id)
            ->where('translations.status', 'completed')
            ->where('translations.prompt_version', TranslationPrompt::VERSION)
            ->whereColumn('translations.source_hash', 'pages.source_hash')
            ->whereNotNull('translations.ai_account_id')
            ->select([
                'translations.ai_account_id',
                'translations.model',
                'translations.target_language',
            ])
            ->selectRaw('COUNT(DISTINCT translations.page_id) as pages_count')
            ->groupBy('translations.ai_account_id', 'translations.model', 'translations.target_language')
            ->get();

        /** @var Collection<int, AiAccount> $accounts */
        $accounts = AiAccount::query()
            ->whereIn('id', $rows->pluck('ai_account_id')->unique()->values())
            ->get(['id', 'name', 'is_active'])
            ->keyBy('id');

        return $rows
            ->map(function (Translation $row) use ($accounts): ?array {
                $account = $accounts->get((int) $row->ai_account_id);

                if (! $account) {
                    return null;
                }

                return [
                    'account_id' => (int) $row->ai_account_id,
                    'account_name' => $account->name,
                    'account_active' => (bool) $account->is_active,
                    'model' => (string) $row->model,
                    'target_language' => (string) $row->target_language,
                    'pages_count' => (int) $row->pages_count,
                ];
            })
            ->filter()
            ->sortBy([
                ['pages_count', 'desc'],
                ['account_name', 'asc'],
                ['model', 'asc'],
                ['target_language', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Pick the requested version when available, otherwise the version with
     * the most translated pages in the book's target language, then overall.
     *
     * @param  array<int, array{
     *     account_id: int,
     *     account_name: string,
     *     account_active: bool,
     *     model: string,
     *     target_language: string,
     *     pages_count: int
     * }>  $versions
     * @return array{
     *     account_id: int,
     *     account_name: string,
     *     account_active: bool,
     *     model: string,
     *     target_language: string,
     *     pages_count: int
     * }|null
     */
    public function resolveVersion(
        Book $book,
        array $versions,
        ?int $accountId,
        ?string $model,
        ?string $targetLanguage,
    ): ?array {
        if ($accountId !== null && $model !== null && $targetLanguage !== null) {
            foreach ($versions as $version) {
                if (
                    $version['account_id'] === $accountId
                    && $version['model'] === $model
                    && $version['target_language'] === $targetLanguage
                ) {
                    return $version;
                }
            }
        }

        $preferred = array_values(array_filter(
            $versions,
            fn (array $version) => $version['target_language'] === $book->target_language,
        ));

        $pool = $preferred !== [] ? $preferred : $versions;

        return $pool[0] ?? null;
    }

    /**
     * Manifest of the pages that can actually be read for the given version.
     *
     * @return array{
     *     pages: array<int, array{id: int, position: int, chapter_id: int, chapter_position: int}>,
     *     chapters: array<int, array{chapter_id: int, translated: int}>,
     *     translated_pages: int
     * }
     */
    public function index(
        Book $book,
        AiAccount $account,
        string $model,
        string $targetLanguage,
    ): array {
        [$pages] = $this->readablePages($book, $account, $model, $targetLanguage);

        $counts = $pages->countBy(fn (Page $page) => (int) $page->chapter_id);

        $chapters = $book->chapters()
            ->orderBy('position')
            ->get(['id'])
            ->map(fn (Chapter $chapter) => [
                'chapter_id' => (int) $chapter->id,
                'translated' => (int) ($counts[$chapter->id] ?? 0),
            ])
            ->values()
            ->all();

        return [
            'pages' => $pages
                ->map(fn (Page $page) => [
                    'id' => (int) $page->id,
                    'position' => (int) $page->position,
                    'chapter_id' => (int) $page->chapter_id,
                    'chapter_position' => (int) $page->chapter_position,
                ])
                ->values()
                ->all(),
            'chapters' => $chapters,
            'translated_pages' => $pages->count(),
        ];
    }

    /**
     * Translated blocks for the requested pages, skipping pages that are not
     * completely translated.
     *
     * @param  array<int, int>  $pageIds
     * @return array<int, array{
     *     id: int,
     *     position: int,
     *     chapter_id: int,
     *     chapter_position: int,
     *     blocks: array<int, array{id: int, type: string, text: string}>
     * }>
     */
    public function pages(
        Book $book,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        array $pageIds,
    ): array {
        [$pages, $translations] = $this->readablePages($book, $account, $model, $targetLanguage, $pageIds);

        return $pages
            ->map(fn (Page $page) => $this->pagePayload($page, $translations->get($page->id)))
            ->values()
            ->all();
    }

    /**
     * First readable page matching the requested page or chapter.
     *
     * @param  array<int, array{id: int, chapter_id: int}>  $indexPages
     */
    public function initialPageId(array $indexPages, ?int $pageId, ?int $chapterId): ?int
    {
        if ($pageId !== null) {
            foreach ($indexPages as $page) {
                if ($page['id'] === $pageId) {
                    return $pageId;
                }
            }
        }

        if ($chapterId !== null) {
            foreach ($indexPages as $page) {
                if ($page['chapter_id'] === $chapterId) {
                    return $page['id'];
                }
            }
        }

        return $indexPages[0]['id'] ?? null;
    }

    /**
     * Initial page plus its adjacent page when they form a spread.
     *
     * @param  array<int, array{id: int, chapter_id: int}>  $indexPages
     * @return array<int, int>
     */
    public function initialPageIds(array $indexPages, ?int $initialPageId, string $scope): array
    {
        if ($initialPageId === null) {
            return [];
        }

        if ($scope === 'page') {
            return [$initialPageId];
        }

        $ids = [];
        $chapterId = null;

        foreach ($indexPages as $page) {
            if ($page['id'] === $initialPageId) {
                $ids[] = $page['id'];
                $chapterId = $page['chapter_id'];

                continue;
            }

            if ($chapterId !== null && $page['chapter_id'] === $chapterId) {
                $ids[] = $page['id'];

                break;
            }
        }

        return $ids;
    }

    public function chapterForPage(Book $book, ?int $pageId): ?int
    {
        if ($pageId === null) {
            return null;
        }

        $chapterId = Page::query()
            ->where('book_id', $book->id)
            ->whereKey($pageId)
            ->value('chapter_id');

        return $chapterId !== null ? (int) $chapterId : null;
    }

    /**
     * @param  array<int, int>|null  $pageIds
     * @return array{0: Collection<int, Page>, 1: Collection<int, Translation>}
     */
    private function readablePages(
        Book $book,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        ?array $pageIds = null,
    ): array {
        $query = Page::query()
            ->where('book_id', $book->id)
            ->with(['blocks' => fn ($query) => $query->select('id', 'page_id', 'position', 'type', 'text')])
            ->orderBy('position');

        if ($pageIds !== null) {
            $query->whereIn('id', $pageIds);
        }

        $pages = $query->get(['id', 'chapter_id', 'position', 'chapter_position', 'source_hash']);

        $translations = $this->content->translationsFor($pages, $account, $model, $targetLanguage);

        $readable = $pages
            ->filter(fn (Page $page) => $this->content->isComplete($page, $translations->get($page->id)))
            ->values();

        return [$readable, $translations];
    }

    /**
     * @return array{
     *     id: int,
     *     position: int,
     *     chapter_id: int,
     *     chapter_position: int,
     *     blocks: array<int, array{id: int, type: string, text: string}>
     * }
     */
    private function pagePayload(Page $page, ?Translation $translation): array
    {
        $translatedBlocks = $this->content->blocksById($translation);
        $blocks = [];

        foreach ($page->blocks as $block) {
            $text = $translatedBlocks[$block->id] ?? null;

            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $blocks[] = [
                'id' => (int) $block->id,
                'type' => $block->type,
                'text' => $text,
            ];
        }

        return [
            'id' => (int) $page->id,
            'position' => (int) $page->position,
            'chapter_id' => (int) $page->chapter_id,
            'chapter_position' => (int) $page->chapter_position,
            'blocks' => $blocks,
        ];
    }
}
