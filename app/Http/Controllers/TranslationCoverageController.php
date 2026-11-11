<?php

namespace App\Http\Controllers;

use App\Models\AiAccount;
use App\Models\Book;
use App\Services\Export\BookExportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TranslationCoverageController extends Controller
{
    public function __construct(private readonly BookExportBuilder $builder) {}

    public function __invoke(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ai_accounts', 'id')],
            'model' => ['required', 'string', 'max:190'],
            'target_language' => ['required', 'string', 'max:16'],
        ]);

        $account = AiAccount::query()->findOrFail($data['account_id']);

        $coverage = $this->builder->coverage(
            $book,
            $account,
            $data['model'],
            $data['target_language'],
        );

        return response()->json([
            'coverage' => $coverage['coverage'],
            'missing_page_ids' => $coverage['missing_page_ids'],
        ]);
    }
}
