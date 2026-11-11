<?php

namespace App\Http\Controllers;

use App\Models\AiAccount;
use App\Models\Book;
use App\Services\Epub\BookImporter;
use App\Services\Epub\InvalidEpubException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookController extends Controller
{
    public function __construct(private readonly BookImporter $importer) {}

    public function index(): Response
    {
        $books = Book::query()
            ->withCount(['pages', 'chapters'])
            ->latest()
            ->get()
            ->map(fn (Book $book) => $book->toClientArray());

        return Inertia::render('Library', [
            'books' => $books,
            'accountsCount' => AiAccount::where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:65536'],
        ]);

        $file = $request->file('file');

        if (strtolower($file->getClientOriginalExtension()) !== 'epub') {
            throw ValidationException::withMessages([
                'file' => __('messages.books.invalid_extension'),
            ]);
        }

        try {
            $book = $this->importer->import($file);
        } catch (InvalidEpubException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'file' => __('messages.books.import_failed'),
            ]);
        }

        return redirect()
            ->route('books.show', $book)
            ->with('success', __('messages.books.imported', ['title' => $book->title]));
    }

    public function show(Book $book): Response
    {
        $chapters = $book->chapters()
            ->withCount('pages')
            ->withMin('pages', 'position')
            ->get()
            ->map(fn ($chapter) => [
                'id' => $chapter->id,
                'position' => $chapter->position,
                'title' => $chapter->title,
                'pages_count' => (int) $chapter->pages_count,
                'first_page_position' => $chapter->pages_min_position,
            ]);

        $pages = $book->pages()
            ->get(['id', 'chapter_id', 'position', 'chapter_position', 'char_count'])
            ->map(fn ($page) => [
                'id' => $page->id,
                'chapter_id' => $page->chapter_id,
                'position' => $page->position,
                'chapter_position' => $page->chapter_position,
                'char_count' => $page->char_count,
            ]);

        $accounts = AiAccount::with('models')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (AiAccount $account) => $account->toClientArray());

        return Inertia::render('Books/Show', [
            'book' => $book->toClientArray(),
            'chapters' => $chapters,
            'pageIndex' => $pages,
            'accounts' => $accounts,
            'lastUsedSlots' => $book->lastUsedSlots(),
            'lastUsedModels' => (object) $book->lastUsedModelsByAccount(),
        ]);
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'target_language' => ['required', 'string', 'max:16'],
        ]);

        $book->update(['target_language' => $data['target_language']]);

        return response()->json(['book' => $book->toClientArray()]);
    }

    public function destroy(Book $book): RedirectResponse
    {
        Storage::disk('local')->delete($book->file_path);

        if ($book->cover_path) {
            Storage::disk('public')->delete($book->cover_path);
        }

        $title = $book->title;
        $book->delete();

        return redirect()
            ->route('library')
            ->with('success', __('messages.books.deleted', ['title' => $title]));
    }
}
