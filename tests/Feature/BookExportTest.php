<?php

use App\Models\AiAccount;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use App\Services\Epub\BookImporter;
use App\Services\Translation\TranslationPrompt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\EpubFixture;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function makeExportBookFixture(): Book
{
    $path = sys_get_temp_dir().'/export-epub-'.uniqid().'.epub';
    EpubFixture::create($path);

    try {
        return app(BookImporter::class)->import(
            new UploadedFile($path, 'il-faro.epub', 'application/epub+zip', null, true),
            1500,
            'it',
        );
    } finally {
        @unlink($path);
    }
}

function makeExportAccount(string $name = 'Account export'): AiAccount
{
    return AiAccount::create([
        'name' => $name,
        'provider' => 'custom',
        'base_url' => 'https://export.example.com/v1',
        'api_key' => 'secret-key',
        'default_model' => 'model-a',
        'is_active' => true,
        'request_mode' => 'serial',
    ]);
}

function translateExportBook(
    Book $book,
    AiAccount $account,
    string $model,
    string $prefix = 'IT',
    ?string $promptVersion = null,
    ?callable $filter = null,
    string $targetLanguage = 'it',
): void {
    $pages = $book->pages()->with('blocks')->orderBy('position')->get();

    foreach ($pages as $page) {
        if ($filter !== null && ! $filter($page)) {
            continue;
        }

        Translation::create([
            'page_id' => $page->id,
            'ai_account_id' => $account->id,
            'model' => $model,
            'target_language' => $targetLanguage,
            'source_hash' => $page->source_hash,
            'prompt_version' => $promptVersion ?? TranslationPrompt::VERSION,
            'params_hash' => hash('sha256', 'params-'.uniqid('', true)),
            'key_hash' => hash('sha256', 'translation-'.uniqid('', true)),
            'status' => 'completed',
            'translated_blocks' => $page->blocks
                ->map(fn ($block) => [
                    'block_id' => $block->id,
                    'text' => $prefix.' '.$block->text,
                ])
                ->values()
                ->all(),
        ]);
    }
}

function openExportZip(TestResponse $response): ZipArchive
{
    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue();

    return $zip;
}

/**
 * @param  array<int, int>  $chapterIds
 * @return array<string, mixed>
 */
function exportPayload(
    AiAccount $account,
    array $chapterIds,
    string $model = 'model-a',
    string $format = 'epub',
    string $targetLanguage = 'it',
): array {
    return [
        'format' => $format,
        'target_language' => $targetLanguage,
        'account_id' => $account->id,
        'model' => $model,
        'chapter_ids' => $chapterIds,
    ];
}

it('esporta un EPUB valido con il testo tradotto dei soli capitoli selezionati', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    translateExportBook($book, $account, 'model-a');

    $chapter = $book->chapters()->orderBy('position')->firstOrFail();

    $response = $this->post(route('books.export', $book), exportPayload($account, [$chapter->id]));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/epub+zip')
        ->assertDownload('il-faro-e-la-tempesta (it).epub');

    $zip = openExportZip($response);

    expect($zip->locateName('mimetype'))->toBe(0)
        ->and($zip->getFromName('mimetype'))->toBe('application/epub+zip')
        ->and($zip->locateName('OEBPS/chapter-2.xhtml'))->toBeFalse()
        ->and($zip->getFromName('OEBPS/chapter-1.xhtml'))->toContain('IT La lanterna girava lenta nella notte.')
        ->and($zip->getFromName('OEBPS/nav.xhtml'))->toContain('Il faro')
        ->and($zip->getFromName('OEBPS/nav.xhtml'))->toContain('<h1>Indice</h1>')
        ->and($zip->getFromName('OEBPS/content.opf'))->toContain('<dc:language>it</dc:language>');

    $zip->close();
});

it('usa l’indice nella lingua di destinazione', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    $chapter = $book->chapters()->orderBy('position')->firstOrFail();

    $book->update(['target_language' => 'en']);
    translateExportBook($book, $account, 'model-a', prefix: 'EN', targetLanguage: 'en');

    $response = $this->post(route('books.export', $book), exportPayload(
        $account,
        [$chapter->id],
        targetLanguage: 'en',
    ));

    $response->assertOk()->assertDownload('il-faro-e-la-tempesta (en).epub');

    $zip = openExportZip($response);
    $nav = $zip->getFromName('OEBPS/nav.xhtml');

    expect($nav)->toContain('<h1>Table of contents</h1>');
    expect($nav)->not->toContain('Indice');

    $zip->close();
});

it('blocca l’intero libro quando un capitolo selezionato non è completamente tradotto', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    $chapters = $book->chapters()->orderBy('position')->get();
    $firstChapter = $chapters->first();

    translateExportBook(
        $book,
        $account,
        'model-a',
        filter: fn (Page $page) => $page->chapter_id === $firstChapter->id,
    );

    $response = $this->postJson(route('books.export', $book), exportPayload(
        $account,
        $chapters->pluck('id')->all(),
    ));

    $response
        ->assertStatus(422)
        ->assertJsonPath('missing_pages', 3)
        ->assertJsonPath('missing_chapters.0.title', 'La tempesta')
        ->assertJsonPath('missing_chapters.0.translated', 0)
        ->assertJsonPath('missing_chapters.0.total', 3);
});

it('permette di esportare un singolo capitolo completamente tradotto', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    $firstChapter = $book->chapters()->orderBy('position')->firstOrFail();

    translateExportBook(
        $book,
        $account,
        'model-a',
        filter: fn (Page $page) => $page->chapter_id === $firstChapter->id,
    );

    $this->post(route('books.export', $book), exportPayload($account, [$firstChapter->id]))
        ->assertOk()
        ->assertDownload('il-faro-e-la-tempesta (it).epub');
});

it('usa la versione di traduzione scelta e ignora le altre', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    $firstChapter = $book->chapters()->orderBy('position')->firstOrFail();
    $filter = fn (Page $page) => $page->chapter_id === $firstChapter->id;

    translateExportBook($book, $account, 'model-a', 'VERSIONE-A', filter: $filter);
    translateExportBook($book, $account, 'model-b', 'VERSIONE-B', filter: $filter);

    $response = $this->post(route('books.export', $book), exportPayload(
        $account,
        [$firstChapter->id],
        'model-b',
    ));

    $zip = openExportZip($response->assertOk());

    $chapterHtml = $zip->getFromName('OEBPS/chapter-1.xhtml');

    expect($chapterHtml)->toContain('VERSIONE-B La lanterna');
    expect($chapterHtml)->not->toContain('VERSIONE-A');

    $zip->close();
});

it('ignora le traduzioni con prompt o testo di origine obsoleti', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    $firstChapter = $book->chapters()->orderBy('position')->firstOrFail();

    translateExportBook(
        $book,
        $account,
        'model-a',
        promptVersion: 'v1',
        filter: fn (Page $page) => $page->chapter_id === $firstChapter->id,
    );

    $this->postJson(route('books.export', $book), exportPayload(
        $account,
        [$firstChapter->id],
    ))
        ->assertStatus(422)
        ->assertJsonPath('missing_chapters.0.title', 'Il faro');
});

it('genera un PDF con il testo tradotto', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    translateExportBook($book, $account, 'model-a');
    $chapterIds = $book->chapters()->pluck('id')->all();

    $response = $this->post(route('books.export', $book), exportPayload(
        $account,
        $chapterIds,
        format: 'pdf',
    ));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload('il-faro-e-la-tempesta (it).pdf');

    $path = $response->baseResponse->getFile()->getPathname();

    expect(file_get_contents($path, false, null, 0, 5))->toBe('%PDF-');
});

it('rifiuta capitoli che appartengono a un altro libro', function () {
    $book = makeExportBookFixture();
    $account = makeExportAccount();
    $otherBook = makeExportBookFixture();
    $otherChapter = Chapter::query()->where('book_id', $otherBook->id)->firstOrFail();

    $this->postJson(route('books.export', $book), exportPayload($account, [$otherChapter->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('chapter_ids.0');
});
