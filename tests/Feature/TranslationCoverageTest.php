<?php

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use App\Services\Translation\TranslationPrompt;

/**
 * @return array{0: Book, 1: Chapter, 2: Chapter, 3: array<int, Page>, 4: AiAccount}
 */
function makeCoverageFixture(): array
{
    $book = Book::create([
        'title' => 'Atlante delle maree',
        'author' => 'Ada Lovelace',
        'language' => 'en',
        'original_filename' => 'atlante.epub',
        'file_path' => 'books/atlante.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $chapterOne = Chapter::create([
        'book_id' => $book->id,
        'position' => 1,
        'title' => 'Capitolo primo',
    ]);

    $chapterTwo = Chapter::create([
        'book_id' => $book->id,
        'position' => 2,
        'title' => 'Capitolo secondo',
    ]);

    $pages = [];

    foreach ([[$chapterOne, 1], [$chapterOne, 2], [$chapterTwo, 3], [$chapterTwo, 4]] as [$chapter, $position]) {
        $text = "Testo della pagina {$position}.";

        $pages[] = Page::create([
            'book_id' => $book->id,
            'chapter_id' => $chapter->id,
            'position' => $position,
            'chapter_position' => $position,
            'char_count' => strlen($text),
            'source_hash' => hash('sha256', $text),
        ]);
    }

    $account = AiAccount::create([
        'name' => 'Account copertura',
        'provider' => 'custom',
        'base_url' => 'https://api.example.com/v1',
        'api_key' => 'secret-key',
        'default_model' => 'test-model',
        'is_active' => true,
        'request_mode' => 'serial',
    ]);

    return [$book, $chapterOne, $chapterTwo, $pages, $account];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeCoverageTranslation(Page $page, AiAccount $account, array $overrides = []): Translation
{
    return Translation::create(array_merge([
        'page_id' => $page->id,
        'ai_account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'it',
        'source_hash' => $page->source_hash,
        'prompt_version' => TranslationPrompt::VERSION,
        'params_hash' => hash('sha256', 'params-'.uniqid('', true)),
        'key_hash' => hash('sha256', 'translation-'.uniqid('', true)),
        'status' => 'completed',
    ], $overrides));
}

it('calcola la copertura per capitolo usando solo le traduzioni correnti e completate', function () {
    [$book, $chapterOne, $chapterTwo, $pages, $account] = makeCoverageFixture();

    makeCoverageTranslation($pages[0], $account);
    makeCoverageTranslation($pages[1], $account);
    makeCoverageTranslation($pages[1], $account, [
        'params_hash' => hash('sha256', 'altri-parametri'),
    ]);
    makeCoverageTranslation($pages[2], $account);
    makeCoverageTranslation($pages[3], $account, ['status' => 'failed']);
    makeCoverageTranslation($pages[3], $account, ['prompt_version' => 'v1']);

    $this->getJson(route('books.translation-coverage', [
        'book' => $book,
        'account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'it',
    ]))
        ->assertOk()
        ->assertJson([
            'coverage' => [
                ['chapter_id' => $chapterOne->id, 'translated' => 2],
                ['chapter_id' => $chapterTwo->id, 'translated' => 1],
            ],
        ]);
});

it('ignora le traduzioni di altri account, modelli o lingue', function () {
    [$book, $chapterOne, $chapterTwo, $pages, $account] = makeCoverageFixture();

    $otherAccount = AiAccount::create([
        'name' => 'Altro account',
        'provider' => 'custom',
        'base_url' => 'https://other.example.com/v1',
        'api_key' => 'other-secret-key',
        'default_model' => 'other-model',
        'is_active' => true,
        'request_mode' => 'serial',
    ]);

    makeCoverageTranslation($pages[0], $account);
    makeCoverageTranslation($pages[0], $otherAccount);
    makeCoverageTranslation($pages[0], $account, ['model' => 'other-model']);
    makeCoverageTranslation($pages[0], $account, ['target_language' => 'fr']);

    $this->getJson(route('books.translation-coverage', [
        'book' => $book,
        'account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'it',
    ]))
        ->assertOk()
        ->assertJson([
            'coverage' => [
                ['chapter_id' => $chapterOne->id, 'translated' => 1],
            ],
        ]);
});

it('valida i parametri della copertura', function () {
    [$book] = makeCoverageFixture();

    $this->getJson(route('books.translation-coverage', $book))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account_id', 'model', 'target_language']);
});
