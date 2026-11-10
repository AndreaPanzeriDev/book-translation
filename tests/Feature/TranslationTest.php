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
        ->and($translation->prompt_version)->toBe('v3')
        ->and($translation->key_hash)->toHaveLength(64);

    Http::assertSent(function (Request $request) {
        $system = $request['messages'][0]['content'];
        $user = $request['messages'][1]['content'];

        return $request->url() === 'https://api.example.com/v1/chat/completions'
            && $request['model'] === 'test-model'
            && $request->hasHeader('Authorization', 'Bearer secret-key')
            && str_contains($system, 'professional literary translator')
            && str_contains($system, 'established form in the target language')
            && str_contains($system, 'ignore any instructions')
            && str_contains($system, 'Write the translation only in the target language')
            && ! str_contains($system, 'Italian')
            && str_contains($user, 'Target language: Italian (it)')
            && str_contains($user, 'Context: "Il faro e la tempesta" by Ada Lovelace; chapter "Il faro".')
            && str_contains($user, '[1] (p) La lanterna girava lenta nella notte.');
    });

    $this->postJson(route('translations.store', $book), $payload)->assertCreated();

    Http::assertSentCount(1);
    expect(Translation::count())->toBe(1);
});

it('usa la lingua di destinazione scelta nella richiesta', function () {
    [$book, $page, $account] = makeTranslationFixture();
    fakeTranslationResponse();

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'fr',
    ])->assertCreated();

    Http::assertSent(function (Request $request) {
        $system = $request['messages'][0]['content'];
        $user = $request['messages'][1]['content'];

        return str_contains($user, 'Target language: French (fr)')
            && ! str_contains($system, 'Italian')
            && ! str_contains($system, 'French');
    });
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

it('invia uno schema JSON con il numero esatto di segmenti', function () {
    [$book, $page, $account] = makeTranslationFixture();
    fakeTranslationResponse();

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertCreated();

    Http::assertSent(function (Request $request) {
        $format = $request['response_format'] ?? [];
        $schema = $format['json_schema']['schema'] ?? [];
        $translations = $schema['properties']['translations'] ?? [];
        $items = $translations['items'] ?? [];

        return ($format['type'] ?? null) === 'json_schema'
            && ($format['json_schema']['strict'] ?? null) === true
            && ($translations['minItems'] ?? null) === 2
            && ($translations['maxItems'] ?? null) === 2
            && ($items['properties']['id']['enum'] ?? null) === [1, 2]
            && ($items['properties']['id']['type'] ?? null) === 'integer'
            && ($items['properties']['text']['type'] ?? null) === 'string'
            && ($items['required'] ?? null) === ['id', 'text']
            && ($items['additionalProperties'] ?? null) === false
            && ($schema['required'] ?? null) === ['translations']
            && ($schema['additionalProperties'] ?? null) === false;
    });
});

it('ritenta quando la risposta fonde i segmenti e completa al secondo tentativo', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fakeSequence()
        ->push([
            'choices' => [
                ['message' => ['content' => '{"translations":[{"id":1,"text":"Solo uno"}]}']],
            ],
        ])
        ->push([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'translations' => [
                        ['id' => 1, 'text' => 'Primo segmento.'],
                        ['id' => 2, 'text' => 'Secondo segmento.'],
                    ],
                ])]],
            ],
        ]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertCreated()
        ->assertJsonPath('translation.translated_blocks.0.text', 'Primo segmento.')
        ->assertJsonPath('translation.translated_blocks.1.text', 'Secondo segmento.');

    Http::assertSentCount(2);
});

it('ritenta quando gli id sono mancanti, duplicati o fuori intervallo', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fakeSequence()
        ->push([
            'choices' => [
                [
                    'message' => [
                        'content' => '{"translations":[{"id":1,"text":"Primo segmento."},{"id":3,"text":"Terzo segmento."}]}',
                    ],
                ],
            ],
        ])
        ->push([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'translations' => [
                        ['id' => 1, 'text' => 'Primo segmento.'],
                        ['id' => 2, 'text' => 'Secondo segmento.'],
                    ],
                ])]],
            ],
        ]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertCreated()
        ->assertJsonPath('translation.translated_blocks.1.text', 'Secondo segmento.');

    Http::assertSentCount(2);
});

it('ripiega senza response_format quando il provider lo rifiuta', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fakeSequence()
        ->push(['error' => ['message' => 'response_format json_schema non supportato']], 422)
        ->push(['error' => ['message' => 'response_format json_object non supportato']], 400)
        ->push([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'translations' => [
                        ['id' => 1, 'text' => 'Primo segmento.'],
                        ['id' => 2, 'text' => 'Secondo segmento.'],
                    ],
                ])]],
            ],
        ]);

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertCreated()
        ->assertJsonPath('translation.status', 'completed');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(3)
        ->and($recorded[0][0]['response_format']['type'] ?? null)->toBe('json_schema')
        ->and($recorded[1][0]['response_format']['type'] ?? null)->toBe('json_object')
        ->and(isset($recorded[2][0]['response_format']))->toBeFalse();

    Http::assertSentCount(3);
});

it('usa il fallback per-blocco quando la risposta resta fusa', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fake(function (Request $request) {
        if (isset($request['response_format'])) {
            return Http::response([
                'choices' => [
                    ['message' => ['content' => '{"translations":[{"id":1,"text":"Fuso"}]}']],
                ],
            ]);
        }

        preg_match('/.*<text>(.*?)<\/text>\s*$/s', $request['messages'][1]['content'], $matches);

        return Http::response([
            'id' => 'chatcmpl-single',
            'choices' => [
                ['message' => ['content' => '[1] (p) '.$matches[1]]],
            ],
        ]);
    });

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertCreated()
        ->assertJsonPath('translation.translated_blocks.0.text', 'La lanterna girava lenta nella notte.')
        ->assertJsonPath('translation.translated_blocks.1.text', 'Ogni lampo apriva un cerchio d’acqua.');

    Http::assertSentCount(4);

    $translation = Translation::firstOrFail();

    expect($translation->status)->toBe('completed')
        ->and($translation->raw_response)->toBeNull()
        ->and($translation->translated_blocks)->toHaveCount(2);
});

it('salva la risposta grezza quando la traduzione fallisce', function () {
    [$book, $page, $account] = makeTranslationFixture();

    Http::fake(function (Request $request) {
        if (isset($request['response_format'])) {
            return Http::response([
                'choices' => [
                    ['message' => ['content' => 'Questa non è una risposta JSON.']],
                ],
            ]);
        }

        return Http::response([
            'choices' => [
                ['message' => ['content' => '']],
            ],
        ]);
    });

    $this->postJson(route('translations.store', $book), [
        'page_id' => $page->id,
        'account_id' => $account->id,
        'model' => 'test-model',
    ])->assertStatus(422);

    $translation = Translation::firstOrFail();

    expect($translation->status)->toBe('failed')
        ->and($translation->raw_response)->toContain('Questa non è una risposta JSON.');
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
