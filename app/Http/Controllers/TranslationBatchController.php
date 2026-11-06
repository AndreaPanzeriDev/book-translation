<?php

namespace App\Http\Controllers;

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\TranslationBatch;
use App\Services\Translation\TranslationBatchException;
use App\Services\Translation\TranslationBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TranslationBatchController extends Controller
{
    public function __construct(private readonly TranslationBatchService $batches) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer'],
        ]);

        $query = TranslationBatch::query()->with('book');

        if (array_key_exists('ids', $data)) {
            $query->whereIn('id', $data['ids']);
        } else {
            $query->whereIn('status', [
                TranslationBatch::STATUS_QUEUED,
                TranslationBatch::STATUS_RUNNING,
            ]);
        }

        $batches = $query->orderBy('id')->get()
            ->map(fn (TranslationBatch $batch) => $batch->toClientArray())
            ->values()
            ->all();

        return response()->json(['batches' => $batches]);
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'slots' => ['required', 'array', 'min:1', 'max:2'],
            'slots.*.slot_key' => ['required', 'string', 'distinct', Rule::in(['a', 'b'])],
            'slots.*.account_id' => ['required', 'integer', 'exists:ai_accounts,id'],
            'slots.*.model' => ['required', 'string', 'max:190'],
            'page_ids' => ['required', 'array', 'min:1'],
            'page_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('pages', 'id')->where('book_id', $book->id),
            ],
            'target_language' => ['nullable', 'string', 'max:16'],
        ]);

        $accountIds = collect($data['slots'])->pluck('account_id')->unique()->values();

        $activeAccountIds = AiAccount::query()
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->pluck('id');

        if ($activeAccountIds->count() !== $accountIds->count()) {
            return response()->json([
                'message' => __('messages.accounts.some_inactive'),
            ], 422);
        }

        try {
            $result = $this->batches->create(
                $book,
                $data['slots'],
                $data['page_ids'],
                $data['target_language'] ?? $book->target_language,
            );
        } catch (TranslationBatchException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json([
            'batch' => $result['batch']?->load('book')->toClientArray(),
            'message' => $result['message'],
        ], $result['batch'] ? 201 : 200);
    }

    public function show(TranslationBatch $batch): JsonResponse
    {
        return response()->json([
            'batch' => $batch->load('book')->toClientArray(),
        ]);
    }

    public function cancel(TranslationBatch $batch): JsonResponse
    {
        return response()->json([
            'batch' => $this->batches->cancel($batch)->load('book')->toClientArray(),
        ]);
    }

    public function retry(TranslationBatch $batch): JsonResponse
    {
        try {
            $retried = $this->batches->retryFailed($batch);
        } catch (TranslationBatchException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json([
            'batch' => $retried->load('book')->toClientArray(),
        ]);
    }
}
