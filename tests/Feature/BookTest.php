<?php

use App\Models\Book;
use App\Models\Page;
use App\Services\Epub\BookImporter;
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
