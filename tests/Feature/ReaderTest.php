<?php

use App\Models\AiAccount;
use App\Models\Block;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use App\Services\Translation\TranslationPrompt;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: Book, 1: Chapter, 2: Chapter, 3: array<int, Page>, 4: AiAccount}
 */
function makeReaderFixture(): array
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

    foreach ([
        [$chapterOne, 1, 'Pagina uno.'],
        [$chapterOne, 2, 'Pagina due.'],
        [$chapterOne, 3, 'Pagina tre.'],
        [$chapterTwo, 4, 'Pagina quattro.'],
    ] as [$chapter, $position, $text]) {
        $page = Page::create([
            'book_id' => $book->id,
            'chapter_id' => $chapter->id,
            'position' => $position,
            'chapter_position' => $position,
            'char_count' => strlen($text),
            'source_hash' => hash('sha256', $text),
        ]);

        Block::create([
            'page_id' => $page->id,
            'position' => 0,
            'type' => 'p',
            'text' => $text,
            'char_count' => strlen($text),
        ]);

        $pages[] = $page;
    }

    $account = AiAccount::create([
        'name' => 'Account lettore',
        'provider' => 'custom',
        'base_url' => 'https://reader.example.com/v1',
        'api_key' => 'secret-key',
        'default_model' => 'model-a',
        'is_active' => true,
        'request_mode' => 'serial',
    ]);

    return [$book, $chapterOne, $chapterTwo, $pages, $account];
}

function makeReaderAccount(string $name, bool $active = true): AiAccount
{
    return AiAccount::create([
        'name' => $name,
        'provider' => 'custom',
        'base_url' => 'https://'.md5($name).'.example.com/v1',
        'api_key' => 'secret-key',
        'default_model' => 'model-b',
        'is_active' => $active,
        'request_mode' => 'serial',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function translateReaderPage(
    Page $page,
    AiAccount $account,
    string $prefix = 'IT',
    array $overrides = [],
): Translation {
    $blocks = $page->blocks()->get();

    return Translation::create(array_merge([
        'page_id' => $page->id,
        'ai_account_id' => $account->id,
        'model' => 'model-a',
        'target_language' => 'it',
        'source_hash' => $page->source_hash,
        'prompt_version' => TranslationPrompt::VERSION,
        'params_hash' => hash('sha256', 'params-'.uniqid('', true)),
        'key_hash' => hash('sha256', 'translation-'.uniqid('', true)),
        'status' => 'completed',
        'translated_blocks' => $blocks
            ->map(fn (Block $block) => [
                'block_id' => $block->id,
                'text' => $prefix.' '.$block->text,
            ])
            ->values()
            ->all(),
    ], $overrides));
}

it('carica il lettore con indice, versione selezionata e prime pagine tradotte', function () {
    [$book, $chapterOne, , $pages, $account] = makeReaderFixture();

    foreach ($pages as $page) {
        translateReaderPage($page, $account);
    }

    $this->get(route('books.reader', $book))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Books/Reader')
            ->where('book.id', $book->id)
            ->has('chapters', 2)
            ->has('versions', 1)
            ->where('versions.0.account_id', $account->id)
            ->where('versions.0.pages_count', 4)
            ->where('selectedVersion.account_id', $account->id)
            ->where('selectedVersion.model', 'model-a')
            ->where('selectedVersion.target_language', 'it')
            ->where('readerIndex.translated_pages', 4)
            ->has('readerIndex.pages', 4)
            ->where('readerIndex.chapters.0.chapter_id', $chapterOne->id)
            ->where('readerIndex.chapters.0.translated', 3)
            ->where('initialPageId', $pages[0]->id)
            ->where('initialChapterId', $chapterOne->id)
            ->where('initialScope', 'chapter')
            ->has('initialPages', 2)
            ->where('initialPages.0.id', $pages[0]->id)
            ->where('initialPages.0.blocks.0.type', 'p')
            ->where('initialPages.0.blocks.0.text', 'IT Pagina uno.')
            ->where('initialPages.1.id', $pages[1]->id));
});

it('elenca solo le versioni complete e correnti e include gli account inattivi', function () {
    [$book, , , $pages, $account] = makeReaderFixture();

    $inactiveAccount = makeReaderAccount('Zeta account inattivo', false);
    $deletedAccount = makeReaderAccount('Account eliminato');

    translateReaderPage($pages[0], $account);
    translateReaderPage($pages[1], $account, overrides: ['status' => 'failed']);
    translateReaderPage($pages[1], $account, overrides: ['prompt_version' => 'v1']);
    translateReaderPage($pages[1], $account, overrides: ['source_hash' => hash('sha256', 'stale')]);
    translateReaderPage($pages[1], $inactiveAccount);
    translateReaderPage($pages[2], $deletedAccount);

    $deletedAccount->delete();

    $this->get(route('books.reader', $book))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('versions', 2)
            ->where('versions.0.account_id', $account->id)
            ->where('versions.0.pages_count', 1)
            ->where('versions.1.account_id', $inactiveAccount->id)
            ->where('versions.1.account_active', false)
            ->where('versions.1.pages_count', 1));
});

it('include nell’indice solo le pagine completamente tradotte', function () {
    [$book, $chapterOne, $chapterTwo, $pages, $account] = makeReaderFixture();

    translateReaderPage($pages[0], $account);
    translateReaderPage($pages[1], $account, overrides: ['translated_blocks' => []]);

    $thirdBlock = $pages[2]->blocks()->firstOrFail();
    translateReaderPage($pages[2], $account, overrides: [
        'translated_blocks' => [['block_id' => $thirdBlock->id, 'text' => '']],
    ]);

    translateReaderPage($pages[3], $account);

    $this->getJson(route('books.reader.index', [
        'book' => $book,
        'account_id' => $account->id,
        'model' => 'model-a',
        'target_language' => 'it',
    ]))
        ->assertOk()
        ->assertJsonPath('index.translated_pages', 2)
        ->assertJsonCount(2, 'index.pages')
        ->assertJsonPath('index.pages.0.id', $pages[0]->id)
        ->assertJsonPath('index.pages.1.id', $pages[3]->id)
        ->assertJsonPath('index.chapters.0.chapter_id', $chapterOne->id)
        ->assertJsonPath('index.chapters.0.translated', 1)
        ->assertJsonPath('index.chapters.1.chapter_id', $chapterTwo->id)
        ->assertJsonPath('index.chapters.1.translated', 1);
});

it('restituisce i blocchi tradotti delle pagine richieste e scarta quelle esterne al libro', function () {
    [$book, , , $pages, $account] = makeReaderFixture();

    $secondBlock = Block::create([
        'page_id' => $pages[0]->id,
        'position' => 1,
        'type' => 'h2',
        'text' => 'Sottotitolo.',
        'char_count' => 11,
    ]);

    translateReaderPage($pages[0], $account);
    translateReaderPage($pages[1], $account, overrides: ['translated_blocks' => []]);

    $otherBook = Book::create([
        'title' => 'Altro libro',
        'original_filename' => 'altro.epub',
        'file_path' => 'books/altro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $otherChapter = Chapter::create([
        'book_id' => $otherBook->id,
        'position' => 1,
        'title' => 'Altro capitolo',
    ]);

    $otherPage = Page::create([
        'book_id' => $otherBook->id,
        'chapter_id' => $otherChapter->id,
        'position' => 1,
        'chapter_position' => 1,
        'char_count' => 5,
        'source_hash' => hash('sha256', 'Altro'),
    ]);

    $this->getJson(route('books.reader.pages', [
        'book' => $book,
        'account_id' => $account->id,
        'model' => 'model-a',
        'target_language' => 'it',
        'page_ids' => [$pages[0]->id, $pages[1]->id],
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'pages')
        ->assertJsonPath('pages.0.id', $pages[0]->id)
        ->assertJsonPath('pages.0.chapter_id', $pages[0]->chapter_id)
        ->assertJsonCount(2, 'pages.0.blocks')
        ->assertJsonPath('pages.0.blocks.0.id', $pages[0]->blocks()->firstOrFail()->id)
        ->assertJsonPath('pages.0.blocks.0.type', 'p')
        ->assertJsonPath('pages.0.blocks.0.text', 'IT Pagina uno.')
        ->assertJsonPath('pages.0.blocks.1.id', $secondBlock->id)
        ->assertJsonPath('pages.0.blocks.1.type', 'h2')
        ->assertJsonPath('pages.0.blocks.1.text', 'IT Sottotitolo.');

    $this->getJson(route('books.reader.pages', [
        'book' => $book,
        'account_id' => $account->id,
        'model' => 'model-a',
        'target_language' => 'it',
        'page_ids' => [$pages[0]->id, $otherPage->id],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page_ids.1');
});

it('rispetta pagina, capitolo, scope e versione richiesti', function () {
    [$book, $chapterOne, $chapterTwo, $pages, $account] = makeReaderFixture();

    $otherAccount = makeReaderAccount('Account B');

    translateReaderPage($pages[0], $account);
    translateReaderPage($pages[1], $account);
    translateReaderPage($pages[3], $account);
    translateReaderPage($pages[1], $otherAccount, 'EN', ['model' => 'model-b']);
    translateReaderPage($pages[3], $otherAccount, 'EN', ['model' => 'model-b']);

    $this->get(route('books.reader', [
        'book' => $book,
        'account_id' => $otherAccount->id,
        'model' => 'model-b',
        'target_language' => 'it',
        'page' => $pages[3]->id,
        'scope' => 'page',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedVersion.account_id', $otherAccount->id)
            ->where('selectedVersion.model', 'model-b')
            ->where('initialPageId', $pages[3]->id)
            ->where('initialChapterId', $chapterTwo->id)
            ->where('initialScope', 'page')
            ->has('initialPages', 1)
            ->where('initialPages.0.id', $pages[3]->id));

    $this->get(route('books.reader', [
        'book' => $book,
        'account_id' => $account->id,
        'model' => 'model-a',
        'target_language' => 'it',
        'chapter' => $chapterTwo->id,
        'scope' => 'book',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialPageId', $pages[3]->id)
            ->where('initialChapterId', $chapterTwo->id)
            ->where('initialScope', 'book')
            ->has('initialPages', 1));
});

it('torna alla prima pagina tradotta del capitolo quando la pagina richiesta non è leggibile', function () {
    [$book, $chapterOne, , $pages, $account] = makeReaderFixture();

    translateReaderPage($pages[0], $account);

    $this->get(route('books.reader', [
        'book' => $book,
        'account_id' => $account->id,
        'model' => 'model-a',
        'target_language' => 'it',
        'page' => $pages[2]->id,
        'scope' => 'page',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialPageId', $pages[0]->id)
            ->where('initialChapterId', $chapterOne->id)
            ->has('initialPages', 1));
});

it('preferisce la lingua di destinazione del libro per la versione predefinita', function () {
    [$book, , , $pages, $account] = makeReaderFixture();

    $frenchAccount = makeReaderAccount('Account francese');

    translateReaderPage($pages[0], $account);
    translateReaderPage($pages[1], $account);

    foreach ($pages as $page) {
        translateReaderPage($page, $frenchAccount, 'FR', [
            'model' => 'model-fr',
            'target_language' => 'fr',
        ]);
    }

    $this->get(route('books.reader', $book))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedVersion.account_id', $account->id)
            ->where('selectedVersion.target_language', 'it')
            ->where('readerIndex.translated_pages', 2));

    $book->update(['target_language' => 'de']);

    $this->get(route('books.reader', $book))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedVersion.account_id', $frenchAccount->id)
            ->where('selectedVersion.target_language', 'fr')
            ->where('readerIndex.translated_pages', 4));
});
