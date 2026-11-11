<?php

namespace App\Http\Controllers;

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Chapter;
use App\Services\Reading\ReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BookReaderController extends Controller
{
    public function __construct(private readonly ReaderService $reader) {}

    public function show(Request $request, Book $book): Response
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:190'],
            'target_language' => ['nullable', 'string', 'max:16'],
            'page' => ['nullable', 'integer', 'min:1'],
            'chapter' => ['nullable', 'integer', 'min:1'],
            'scope' => ['nullable', Rule::in(['page', 'chapter', 'book'])],
        ]);

        $chapters = $book->chapters()
            ->withCount('pages')
            ->withMin('pages', 'position')
            ->get()
            ->map(fn (Chapter $chapter) => [
                'id' => $chapter->id,
                'position' => $chapter->position,
                'title' => $chapter->title,
                'pages_count' => (int) $chapter->pages_count,
                'first_page_position' => $chapter->pages_min_position,
            ]);

        $versions = $this->reader->versions($book);

        $selectedVersion = $this->reader->resolveVersion(
            $book,
            $versions,
            isset($data['account_id']) ? (int) $data['account_id'] : null,
            $data['model'] ?? null,
            $data['target_language'] ?? null,
        );

        $account = $selectedVersion
            ? AiAccount::query()->find($selectedVersion['account_id'])
            : null;

        if (! $account) {
            $selectedVersion = null;
        }

        $readerIndex = $account && $selectedVersion
            ? $this->reader->index(
                $book,
                $account,
                $selectedVersion['model'],
                $selectedVersion['target_language'],
            )
            : ['pages' => [], 'chapters' => [], 'translated_pages' => 0];

        $requestedPageId = isset($data['page']) ? (int) $data['page'] : null;
        $requestedChapterId = isset($data['chapter']) ? (int) $data['chapter'] : null;
        $chapterId = $requestedChapterId ?? $this->reader->chapterForPage($book, $requestedPageId);

        $initialPageId = $this->reader->initialPageId(
            $readerIndex['pages'],
            $requestedPageId,
            $chapterId,
        );

        $initialChapterId = null;

        if ($initialPageId !== null) {
            foreach ($readerIndex['pages'] as $page) {
                if ($page['id'] === $initialPageId) {
                    $initialChapterId = $page['chapter_id'];

                    break;
                }
            }
        }

        $initialScope = $data['scope'] ?? ($requestedPageId !== null ? 'page' : 'chapter');

        $initialPageIds = $this->reader->initialPageIds(
            $readerIndex['pages'],
            $initialPageId,
            $initialScope,
        );

        $initialPages = $account && $selectedVersion && $initialPageIds !== []
            ? $this->reader->pages(
                $book,
                $account,
                $selectedVersion['model'],
                $selectedVersion['target_language'],
                $initialPageIds,
            )
            : [];

        return Inertia::render('Books/Reader', [
            'book' => $book->toClientArray(),
            'chapters' => $chapters,
            'versions' => $versions,
            'selectedVersion' => $selectedVersion,
            'readerIndex' => $readerIndex,
            'initialPageId' => $initialPageId,
            'initialChapterId' => $initialChapterId,
            'initialPages' => $initialPages,
            'initialScope' => $initialScope,
        ]);
    }

    public function index(Request $request, Book $book): JsonResponse
    {
        [$account, $model, $targetLanguage] = $this->versionRequest($request);

        return response()->json([
            'index' => $this->reader->index($book, $account, $model, $targetLanguage),
        ]);
    }

    public function pages(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ai_accounts', 'id')],
            'model' => ['required', 'string', 'max:190'],
            'target_language' => ['required', 'string', 'max:16'],
            'page_ids' => ['required', 'array', 'min:1', 'max:20'],
            'page_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('pages', 'id')->where('book_id', $book->id),
            ],
        ]);

        $account = AiAccount::query()->findOrFail($data['account_id']);

        return response()->json([
            'pages' => $this->reader->pages(
                $book,
                $account,
                $data['model'],
                $data['target_language'],
                array_map('intval', $data['page_ids']),
            ),
        ]);
    }

    /**
     * @return array{0: AiAccount, 1: string, 2: string}
     */
    private function versionRequest(Request $request): array
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ai_accounts', 'id')],
            'model' => ['required', 'string', 'max:190'],
            'target_language' => ['required', 'string', 'max:16'],
        ]);

        return [
            AiAccount::query()->findOrFail($data['account_id']),
            $data['model'],
            $data['target_language'],
        ];
    }
}
