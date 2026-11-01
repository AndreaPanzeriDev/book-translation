<?php

namespace App\Http\Controllers;

use App\Models\AiAccount;
use App\Models\Book;
use App\Services\Translation\TranslationException;
use App\Services\Translation\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TranslationController extends Controller
{
    public function __construct(private readonly TranslationService $translator) {}

    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'page_id' => [
                'required',
                'integer',
                Rule::exists('pages', 'id')->where('book_id', $book->id),
            ],
            'account_id' => ['required', 'integer', 'exists:ai_accounts,id'],
            'model' => ['required', 'string', 'max:190'],
            'target_language' => ['nullable', 'string', 'max:16'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
        ]);

        $account = AiAccount::where('is_active', true)->find($data['account_id']);

        if (! $account) {
            return response()->json([
                'message' => 'L’account selezionato non è attivo.',
            ], 422);
        }

        $page = $book->pages()->findOrFail($data['page_id']);
        $targetLanguage = $data['target_language'] ?? $book->target_language;

        try {
            $translation = $this->translator->translate(
                $page,
                $account,
                $data['model'],
                $targetLanguage,
                (float) ($data['temperature'] ?? TranslationService::DEFAULT_TEMPERATURE),
            );
        } catch (TranslationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'translation' => $translation->toClientArray(),
        ], 201);
    }
}
