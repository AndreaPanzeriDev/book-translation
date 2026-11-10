<?php

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use App\Models\TranslationBatch;
use App\Models\TranslationBatchItem;
use App\Services\Epub\BookImporter;
use App\Services\Translation\TranslationPrompt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\EpubFixture;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function uploadTestEpub(): UploadedFile
{
    $path = sys_get_temp_dir().'/epub-'.uniqid().'.epub';
    EpubFixture::create($path);

    return new UploadedFile($path, 'il-faro.epub', 'application/epub+zip', null, true);
}

/**
 * @return array{0: Book, 1: Page, 2: AiAccount, 3: AiAccount}
 */
function makeLastUsedSlotsFixture(): array
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

    $chapter = Chapter::create([
        'book_id' => $book->id,
        'position' => 1,
        'title' => 'Capitolo primo',
    ]);

    $text = 'Testo della pagina.';

    $page = Page::create([
        'book_id' => $book->id,
        'chapter_id' => $chapter->id,
        'position' => 1,
        'chapter_position' => 1,
        'char_count' => strlen($text),
        'source_hash' => hash('sha256', $text),
    ]);

    $accountA = AiAccount::create([
        'name' => 'Account A',
        'provider' => 'custom',
        'base_url' => 'https://a.example.com/v1',
        'api_key' => 'secret-key-a',
        'default_model' => 'default-a',
        'is_active' => true,
        'request_mode' => 'serial',
    ]);

    $accountB = AiAccount::create([
        'name' => 'Account B',
        'provider' => 'custom',
        'base_url' => 'https://b.example.com/v1',
        'api_key' => 'secret-key-b',
        'default_model' => 'default-b',
        'is_active' => true,
        'request_mode' => 'serial',
    ]);

    return [$book, $page, $accountA, $accountB];
}

it('importa un EPUB caricato e crea capitoli, pagine e blocchi', function () {
    $response = $this->post('/books', ['file' => uploadTestEpub()]);

    $book = Book::firstOrFail();

    $response->assertRedirect(route('books.show', $book));

    expect($book->title)->toBe('Il faro e la tempesta')
        ->and($book->author)->toBe('Ada Lovelace')
        ->and($book->language)->toBe('en')
        ->and($book->chapters()->count())->toBe(2)
        ->and($book->pages()->count())->toBeGreaterThanOrEqual(3)
        ->and($book->cover_path)->not->toBeNull()
        ->and($book->pages()->where('char_count', '>', $book->page_char_limit)->exists())->toBeFalse();

    Storage::disk('local')->assertExists($book->file_path);
    Storage::disk('public')->assertExists($book->cover_path);

    $firstPage = $book->pages()->with('blocks')->firstOrFail();
    expect($firstPage->blocks)->not->toBeEmpty()
        ->and($firstPage->source_hash)->toHaveLength(64);
});

it('divide i blocchi lunghi in più pagine virtuali', function () {
    $book = app(BookImporter::class)->import(uploadTestEpub(), 300);

    expect($book->pages()->count())->toBeGreaterThan(5)
        ->and(Page::where('book_id', $book->id)->max('char_count'))->toBeLessThanOrEqual(320);
});

it('mostra la libreria con i libri importati', function () {
    $book = Book::create([
        'title' => 'Libro esistente',
        'original_filename' => 'libro.epub',
        'file_path' => 'books/libro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Library')
            ->has('books', 1)
            ->where('books.0.id', $book->id)
            ->where('books.0.title', 'Libro esistente'));
});

it('rifiuta i file senza estensione epub', function () {
    $file = UploadedFile::fake()->create('romanzo.pdf', 20, 'application/pdf');

    $this->post('/books', ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(Book::count())->toBe(0);
});

it('aggiorna la lingua di destinazione del libro', function () {
    $book = Book::create([
        'title' => 'Libro esistente',
        'original_filename' => 'libro.epub',
        'file_path' => 'books/libro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $this->patchJson(route('books.update', $book), ['target_language' => 'fr'])
        ->assertOk()
        ->assertJsonPath('book.target_language', 'fr');

    expect($book->fresh()->target_language)->toBe('fr');
});

it('rifiuta una lingua di destinazione non valida', function () {
    $book = Book::create([
        'title' => 'Libro esistente',
        'original_filename' => 'libro.epub',
        'file_path' => 'books/libro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $this->patchJson(route('books.update', $book), ['target_language' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_language');
});

it('ripristina gli ultimi account e modelli usati per il libro', function () {
    [$book, $page, $accountA, $accountB] = makeLastUsedSlotsFixture();

    $oldBatch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => TranslationBatch::STATUS_COMPLETED,
        'total_items' => 2,
    ]);

    $oldBatch->items()->create([
        'page_id' => $page->id,
        'ai_account_id' => $accountA->id,
        'model' => 'model-a-old',
        'slot_key' => 'a',
        'page_position' => $page->position,
        'status' => TranslationBatchItem::STATUS_COMPLETED,
    ]);

    $oldBatch->items()->create([
        'page_id' => $page->id,
        'ai_account_id' => $accountB->id,
        'model' => 'model-b-old',
        'slot_key' => 'b',
        'page_position' => $page->position,
        'status' => TranslationBatchItem::STATUS_COMPLETED,
    ]);

    $newBatch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => TranslationBatch::STATUS_COMPLETED,
        'total_items' => 1,
    ]);

    $newBatch->items()->create([
        'page_id' => $page->id,
        'ai_account_id' => $accountA->id,
        'model' => 'model-a-new',
        'slot_key' => 'a',
        'page_position' => $page->position,
        'status' => TranslationBatchItem::STATUS_COMPLETED,
    ]);

    $this->get(route('books.show', $book))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Books/Show')
            ->where('lastUsedSlots.a.account_id', $accountA->id)
            ->where('lastUsedSlots.a.model', 'model-a-new')
            ->where('lastUsedSlots.b.account_id', $accountB->id)
            ->where('lastUsedSlots.b.model', 'model-b-old'));
});

it('usa l ultima traduzione come fallback quando non ci sono batch', function () {
    [$book, $page, $accountA] = makeLastUsedSlotsFixture();

    Translation::create([
        'page_id' => $page->id,
        'ai_account_id' => $accountA->id,
        'model' => 'legacy-model',
        'target_language' => 'it',
        'source_hash' => $page->source_hash,
        'prompt_version' => TranslationPrompt::VERSION,
        'params_hash' => hash('sha256', 'params'),
        'key_hash' => hash('sha256', 'legacy-key'),
        'status' => 'completed',
    ]);

    $this->get(route('books.show', $book))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Books/Show')
            ->where('lastUsedSlots.a.account_id', $accountA->id)
            ->where('lastUsedSlots.a.model', 'legacy-model')
            ->where('lastUsedSlots.b', null));
});
