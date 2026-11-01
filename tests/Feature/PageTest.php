<?php

use App\Models\Block;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;

it('restituisce le pagine di un capitolo con blocchi e traduzioni', function () {
    $book = Book::create([
        'title' => 'Libro',
        'original_filename' => 'libro.epub',
        'file_path' => 'books/libro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $chapter = Chapter::create(['book_id' => $book->id, 'position' => 1, 'title' => 'Capitolo']);
    $otherChapter = Chapter::create(['book_id' => $book->id, 'position' => 2, 'title' => 'Altro']);

    $page = Page::create([
        'book_id' => $book->id,
        'chapter_id' => $chapter->id,
        'position' => 1,
        'chapter_position' => 1,
        'char_count' => 10,
        'source_hash' => hash('sha256', 'Ciao mondo'),
    ]);

    Block::create([
        'page_id' => $page->id,
        'position' => 0,
        'type' => 'p',
        'text' => 'Ciao mondo',
        'char_count' => 10,
    ]);

    Page::create([
        'book_id' => $book->id,
        'chapter_id' => $otherChapter->id,
        'position' => 2,
        'chapter_position' => 1,
        'char_count' => 4,
        'source_hash' => hash('sha256', 'Altro'),
    ]);

    Translation::create([
        'page_id' => $page->id,
        'ai_account_id' => null,
        'model' => 'test-model',
        'target_language' => 'it',
        'source_hash' => $page->source_hash,
        'prompt_version' => 'v1',
        'params_hash' => hash('sha256', 'params'),
        'key_hash' => hash('sha256', 'key'),
        'status' => 'completed',
        'translated_blocks' => [['block_id' => 1, 'text' => 'Hello world']],
    ]);

    $this->getJson("/books/{$book->id}/pages?chapter_id={$chapter->id}")
        ->assertOk()
        ->assertJsonCount(1, 'pages')
        ->assertJsonPath('pages.0.id', $page->id)
        ->assertJsonPath('pages.0.blocks.0.text', 'Ciao mondo')
        ->assertJsonPath('pages.0.translations.0.status', 'completed')
        ->assertJsonPath('pages.0.translations.0.translated_blocks.0.text', 'Hello world');
});

it('valida che il capitolo appartenga al libro', function () {
    $book = Book::create([
        'title' => 'Libro',
        'original_filename' => 'libro.epub',
        'file_path' => 'books/libro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $otherBook = Book::create([
        'title' => 'Altro libro',
        'original_filename' => 'altro.epub',
        'file_path' => 'books/altro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $foreignChapter = Chapter::create(['book_id' => $otherBook->id, 'position' => 1, 'title' => 'Estero']);

    $this->getJson("/books/{$book->id}/pages?chapter_id={$foreignChapter->id}")
        ->assertStatus(422);
});
