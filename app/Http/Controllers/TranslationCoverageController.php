<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Translation;
use App\Services\Translation\TranslationPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TranslationCoverageController extends Controller
{
    public function __invoke(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ai_accounts', 'id')],
            'model' => ['required', 'string', 'max:190'],
            'target_language' => ['required', 'string', 'max:16'],
        ]);

        $coverage = Translation::query()
            ->join('pages', 'pages.id', '=', 'translations.page_id')
            ->where('pages.book_id', $book->id)
            ->where('translations.ai_account_id', $data['account_id'])
            ->where('translations.model', $data['model'])
            ->where('translations.target_language', $data['target_language'])
            ->where('translations.status', 'completed')
            ->where('translations.prompt_version', TranslationPrompt::VERSION)
            ->selectRaw('pages.chapter_id, COUNT(DISTINCT pages.id) AS translated')
            ->groupBy('pages.chapter_id')
            ->orderBy('pages.chapter_id')
            ->get();

        return response()->json([
            'coverage' => $coverage->map(fn (Translation $translation) => [
                'chapter_id' => (int) $translation->chapter_id,
                'translated' => (int) $translation->translated,
            ])->values(),
        ]);
    }
}
