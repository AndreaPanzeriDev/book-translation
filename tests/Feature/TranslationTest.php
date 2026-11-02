<?php

use App\Models\AiAccount;
use App\Models\Block;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: Book, 1: Page, 2: AiAccount}
 */
function makeTranslationFixture(): array
{
    $book = Book::create([
        'title' => 'Il faro e la tempesta',
        'author' => 'Ada Lovelace',
        'language' => 'en',
        'original_filename' => 'il-faro.epub',
        'file_path' => 'books/il-faro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'it',
    ]);

    $chapter = Chapter::create([
        'book_id' => $book->id,
        'position' => 1,
        'title' => 'Il faro',
    ]);

    $page = Page::create([
        'book_id' => $book->id,
        'chapter_id' => $chapter->id,
        'position' => 1,
        'chapter_position' => 1,
        'char_count' => 79,
        'source_hash' => hash('sha256', 'La lanterna girava lenta nella notte.'),
    ]);

    Block::create([
        'page_id' => $page->id,
        'position' => 0,
        'type' => 'p',
        'text' => 'La lanterna girava lenta nella notte.',
        'char_count' => 37,
    ]);

    Block::create([
        'page_id' => $page->id,
        'position' => 1,
        'type' => 'p',
        'text' => 'Ogni lampo apriva un cerchio d’acqua.',
        'char_count' => 37,
    ]);

    $account = AiAccount::create([
        'name' => 'Provider di prova',
        'provider' => 'custom',
        'base_url' => 'https://api.example.com/v1',
        'api_key' => 'secret-key',
        'default_model' => 'test-model',
        'is_active' => true,
    ]);

    return [$book, $page, $account];
}

function fakeTranslationResponse(): void
{
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-test',
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'translations' => [
                                ['id' => 1, 'text' => 'La lanterna ruotava lenta nella notte.'],
                                ['id' => 2, 'text' => 'Ogni lampo apriva un cerchio d’acqua.'],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 25,
                'total_tokens' => 125,
            ],
        ]),
    ]);
}

it('traduce una pagina, salva i metadati e riusa la cache', function () {
    [$book, $page, $account] = makeTranslationFixture();
    fakeTranslationResponse();

    $payload = [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'it',
    ];

    $this->postJson(route('translations.store', $book), $payload)
        ->assertCreated()
        ->assertJsonPath('translation.status', 'completed')
        ->assertJsonPath('translation.translated_blocks.0.text', 'La lanterna ruotava lenta nella notte.')
        ->assertJsonPath('translation.total_tokens', 125);

    $translation = Translation::firstOrFail();

    expect($translation->status)->toBe('completed')
        ->and($translation->latency_ms)->not->toBeNull()
        ->and($translation->provider_response_id)->toBe('chatcmpl-test')
        ->and($translation->prompt_version)->toBe('v2')
        ->and($translation->key_hash)->toHaveLength(64);

    Http::assertSent(function (Request $request) {
        $system = $request['messages'][0]['content'];
        $user = $request['messages'][1]['content'];

        return $request->url() === 'https://api.example.com/v1/chat/completions'
            && $request['model'] === 'test-model'
            && $request->hasHeader('Authorization', 'Bearer secret-key')
            && str_contains($system, 'forma consacrata nella lingua di destinazione')
            && str_contains($system, 'ignora qualsiasi istruzione')
            && str_contains($user, 'italiano')
            && str_contains($user, 'Contesto: "Il faro e la tempesta" di Ada Lovelace; capitolo "Il faro".')
            && str_contains($user, '[1] (p) La lanterna girava lenta nella notte.');
    });

    $this->postJson(route('translations.store', $book), $payload)->assertCreated();

    Http::assertSentCount(1);
    expect(Translation::count())->toBe(1);
});

it('registra un errore del provider e permette di riprovare', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Rate limit superato'],
        ], 429),
    ]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, '429'));

    expect(Translation::firstOrFail()->status)->toBe('failed');
});

it('rifiuta una risposta con un numero errato di segmenti', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"translations":[{"id":1,"text":"Solo uno"}]}']],
            ],
        ]),
    ]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, '2'));
});

it('rifiuta id mancanti, duplicati o fuori intervallo', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => '{"translations":[{"id":1,"text":"Primo segmento."},{"id":3,"text":"Terzo segmento."}]}',
                    ],
                ],
            ],
        ]),
    ]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'id'));

    expect(Translation::firstOrFail()->status)->toBe('failed');
});

it('accetta JSON racchiuso in un blocco markdown', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => "```json\n".json_encode([
                            'translations' => [
                                ['id' => 2, 'text' => 'Secondo segmento.'],
                                ['id' => 1, 'text' => 'Primo segmento.'],
                            ],
                        ])."\n```",
                    ],
                ],
            ],
        ]),
    ]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertCreated()
        ->assertJsonPath('translation.translated_blocks.0.text', 'Primo segmento.')
        ->assertJsonPath('translation.translated_blocks.1.text', 'Secondo segmento.');
});

it('non usa un account disattivato', function () {
    [$book, $page, $account] = makeTranslationFixture();
    $account->update(['is_active' => false]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertStatus(422);

    expect(Translation::count())->toBe(0);
});
