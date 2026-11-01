<?php

namespace App\Services\Epub;

use App\Models\Block;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookImporter
{
    public function __construct(private readonly EpubParser $parser) {}

    public function import(UploadedFile $file, int $charLimit = 1500, string $targetLanguage = 'it'): Book
    {
        $storedPath = Storage::disk('local')->putFile('books', $file);

        if (! $storedPath) {
            throw new InvalidEpubException('Impossibile salvare il file EPUB.');
        }

        try {
            $parsed = $this->parser->parse(
                Storage::disk('local')->path($storedPath),
                pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            );
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storedPath);

            throw $e;
        }

        return DB::transaction(function () use ($file, $storedPath, $parsed, $charLimit, $targetLanguage) {
            $coverPath = null;

            if ($parsed['cover']) {
                $coverPath = 'covers/'.Str::uuid().'.'.$parsed['cover']['extension'];
                Storage::disk('public')->put($coverPath, $parsed['cover']['contents']);
            }

            $book = Book::create([
                'title' => $parsed['title'],
                'author' => $parsed['author'],
                'language' => $parsed['language'],
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'cover_path' => $coverPath,
                'page_char_limit' => $charLimit,
                'target_language' => $targetLanguage,
            ]);

            $pagePosition = 0;

            foreach ($parsed['chapters'] as $chapterIndex => $chapterData) {
                $chapter = Chapter::create([
                    'book_id' => $book->id,
                    'position' => $chapterIndex + 1,
                    'title' => $chapterData['title'],
                    'source_href' => $chapterData['href'],
                ]);

                $chunks = $this->chunkBlocks($chapterData['blocks'], $charLimit);
                $chapterPosition = 0;

                foreach ($chunks as $chunk) {
                    $pagePosition++;
                    $chapterPosition++;

                    $texts = array_column($chunk, 'text');

                    $page = Page::create([
                        'book_id' => $book->id,
                        'chapter_id' => $chapter->id,
                        'position' => $pagePosition,
                        'chapter_position' => $chapterPosition,
                        'char_count' => mb_strlen(implode(' ', $texts)),
                        'source_hash' => hash('sha256', implode("\n\n", $texts)),
                    ]);

                    foreach ($chunk as $index => $block) {
                        Block::create([
                            'page_id' => $page->id,
                            'position' => $index,
                            'type' => $block['type'],
                            'text' => $block['text'],
                            'char_count' => mb_strlen($block['text']),
                        ]);
                    }
                }
            }

            return $book;
        });
    }

    /**
     * @param  array<int, array{type: string, text: string}>  $blocks
     * @return array<int, array<int, array{type: string, text: string}>>
     */
    private function chunkBlocks(array $blocks, int $charLimit): array
    {
        $pages = [];
        $current = [];
        $currentLength = 0;

        foreach ($blocks as $block) {
            foreach ($this->splitLongBlock($block, $charLimit) as $piece) {
                $pieceLength = mb_strlen($piece['text']);

                if ($current !== [] && $currentLength + $pieceLength + 2 > $charLimit) {
                    $pages[] = $current;
                    $current = [];
                    $currentLength = 0;
                }

                $current[] = $piece;
                $currentLength += $pieceLength + 2;
            }
        }

        if ($current !== []) {
            $pages[] = $current;
        }

        return $pages;
    }

    /**
     * @param  array{type: string, text: string}  $block
     * @return array<int, array{type: string, text: string}>
     */
    private function splitLongBlock(array $block, int $charLimit): array
    {
        $text = $block['text'];

        if (mb_strlen($text) <= $charLimit) {
            return [$block];
        }

        $pieces = [];

        while (mb_strlen($text) > $charLimit) {
            $slice = mb_substr($text, 0, $charLimit);
            $cut = mb_strrpos($slice, ' ');

            if ($cut !== false && $cut > (int) ($charLimit * 0.6)) {
                $slice = mb_substr($slice, 0, $cut);
            }

            $pieces[] = ['type' => $block['type'], 'text' => trim($slice)];
            $text = trim(mb_substr($text, mb_strlen($slice)));
        }

        if ($text !== '') {
            $pieces[] = ['type' => $block['type'], 'text' => $text];
        }

        return $pieces;
    }
}
