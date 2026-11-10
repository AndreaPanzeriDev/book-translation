<?php

namespace App\Http\Controllers;

use App\Models\AiAccount;
use App\Models\Book;
use App\Services\Export\BookExportBuilder;
use App\Services\Export\EpubExporter;
use App\Services\Export\IncompleteTranslationException;
use App\Services\Export\PdfExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BookExportController extends Controller
{
    public function __construct(
        private readonly BookExportBuilder $builder,
        private readonly EpubExporter $epubExporter,
        private readonly PdfExporter $pdfExporter,
    ) {}

    public function __invoke(Request $request, Book $book): BinaryFileResponse|JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(['epub', 'pdf'])],
            'target_language' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})?$/'],
            'account_id' => ['required', 'integer', Rule::exists('ai_accounts', 'id')],
            'model' => ['required', 'string', 'max:190'],
            'chapter_ids' => ['required', 'array', 'min:1'],
            'chapter_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('chapters', 'id')->where('book_id', $book->id),
            ],
        ]);

        $account = AiAccount::query()->findOrFail($data['account_id']);

        try {
            $export = $this->builder->build(
                $book,
                $data['chapter_ids'],
                $account,
                $data['model'],
                $data['target_language'],
            );
        } catch (IncompleteTranslationException $e) {
            return response()->json([
                'message' => __('messages.export.incomplete'),
                'missing_pages' => $e->missingPages,
                'missing_chapters' => $e->missingChapters,
            ], 422);
        }

        $extension = $data['format'];
        $path = tempnam(sys_get_temp_dir(), 'book-export-');

        if ($path === false) {
            return response()->json(['message' => __('messages.export.failed')], 500);
        }

        try {
            if ($extension === 'epub') {
                $this->epubExporter->export($export, $path);
            } else {
                $this->pdfExporter->export($export, $path);
            }
        } catch (Throwable $e) {
            @unlink($path);
            report($e);

            return response()->json(['message' => __('messages.export.failed')], 500);
        }

        $filename = sprintf(
            '%s (%s).%s',
            Str::slug($book->title) ?: 'book',
            $data['target_language'],
            $extension,
        );

        return response()
            ->download($path, $filename, [
                'Content-Type' => $extension === 'epub' ? 'application/epub+zip' : 'application/pdf',
            ])
            ->deleteFileAfterSend(true);
    }
}
