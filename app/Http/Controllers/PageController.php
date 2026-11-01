<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function index(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'chapter_id' => [
                'nullable',
                'integer',
                Rule::exists('chapters', 'id')->where('book_id', $book->id),
            ],
            'from' => ['nullable', 'integer', 'min:1'],
            'to' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $book->pages()->with(['blocks', 'translations']);

        if (! empty($data['chapter_id'])) {
            $query->where('chapter_id', $data['chapter_id']);
        }

        if (! empty($data['from'])) {
            $query->where('position', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->where('position', '<=', $data['to']);
        }

        $pages = $query
            ->orderBy('position')
            ->get()
            ->map(fn ($page) => $page->toClientArray());

        return response()->json(['pages' => $pages]);
    }
}
