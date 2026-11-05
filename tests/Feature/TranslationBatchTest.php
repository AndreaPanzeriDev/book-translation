<?php

use App\Jobs\ProcessTranslationBatchItem;
use App\Models\AiAccount;
use App\Models\Block;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Translation;
use App\Models\TranslationBatch;
use App\Models\TranslationBatchItem;
use App\Services\Translation\TranslationBatchService;
use App\Services\Translation\TranslationPrompt;
use App\Services\Translation\TranslationService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: Book, 1: Collection<int, Page>, 2: AiAccount}
 */
function makeBatchFixture(int $pageCount = 1, string $requestMode = 'parallel'): array
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

    $pages = collect(range(1, $pageCount))->map(function (int $position) use ($book, $chapter) {
        $text = "Testo della pagina {$position}.";

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

        return $page;
    });

    $account = AiAccount::create([
        'name' => 'Provider batch',
        'provider' => 'custom',
        'base_url' => 'https://api.example.com/v1',
        'api_key' => 'secret-key',
        'default_model' => 'test-model',
        'is_active' => true,
        'request_mode' => $requestMode,
    ]);

    return [$book, $pages, $account];
}

function fakeBatchResponse(): void
{
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-batch',
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'translations' => [
                                ['id' => 1, 'text' => 'Testo tradotto.'],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
        ]),
    ]);
}

/**
 * @param  Collection<int, Page>  $pages
 * @return array<string, mixed>
 */
function batchPayload($pages, AiAccount $account, string $targetLanguage = 'it'): array
{
    return [
        'slots' => [
            [
                'slot_key' => 'a',
                'account_id' => $account->id,
                'model' => 'test-model',
            ],
        ],
        'page_ids' => $pages->pluck('id')->all(),
        'target_language' => $targetLanguage,
    ];
}

function makeBatchItem(TranslationBatch $batch, Page $page, AiAccount $account, string $status = 'pending'): TranslationBatchItem
{
    return $batch->items()->create([
        'page_id' => $page->id,
        'ai_account_id' => $account->id,
        'model' => 'test-model',
        'slot_key' => 'a',
        'page_position' => $page->position,
        'status' => $status,
    ]);
}

it('crea un batch parallelo e mette in coda una richiesta per ogni pagina', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(2, 'parallel');

    $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertCreated()
        ->assertJsonPath('batch.status', 'queued')
        ->assertJsonPath('batch.book_title', 'Atlante delle maree')
        ->assertJsonPath('batch.total', 2)
        ->assertJsonPath('batch.pending', 2)
        ->assertJsonPath('batch.failures', []);

    expect(TranslationBatchItem::count())->toBe(2);

    Queue::assertPushedOn('translations', ProcessTranslationBatchItem::class);
    Queue::assertPushed(ProcessTranslationBatchItem::class, 2);
});

it('con un account seriale mette in coda solo la prima pagina', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(3, 'serial');

    $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertCreated()
        ->assertJsonPath('batch.total', 3);

    Queue::assertPushed(ProcessTranslationBatchItem::class, 1);
});

it('rifiuta un secondo batch attivo sullo stesso libro', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');

    $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertCreated();

    $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'già una traduzione'));

    expect(TranslationBatch::count())->toBe(1);
});

it('permette batch attivi su libri diversi', function () {
    Queue::fake();
    [$firstBook, $firstPages, $firstAccount] = makeBatchFixture(1, 'parallel');
    [$secondBook, $secondPages, $secondAccount] = makeBatchFixture(1, 'parallel');

    $this->postJson(route('translation-batches.store', $firstBook), batchPayload($firstPages, $firstAccount))
        ->assertCreated();

    $this->postJson(route('translation-batches.store', $secondBook), batchPayload($secondPages, $secondAccount))
        ->assertCreated();

    expect(TranslationBatch::where('status', 'queued')->count())->toBe(2);
});

it('salta le pagine già tradotte in cache', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(2, 'parallel');
    $translator = app(TranslationService::class);

    Translation::create([
        'page_id' => $pages[0]->id,
        'ai_account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'it',
        'source_hash' => $pages[0]->source_hash,
        'prompt_version' => TranslationPrompt::VERSION,
        'params_hash' => str_repeat('a', 64),
        'key_hash' => $translator->keyHash($pages[0], $account, 'test-model', 'it'),
        'status' => 'completed',
    ]);

    $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertCreated()
        ->assertJsonPath('batch.total', 1);

    expect(TranslationBatchItem::firstOrFail()->page_id)->toBe($pages[1]->id);
    Queue::assertPushed(ProcessTranslationBatchItem::class, 1);
});

it('non crea un batch quando tutte le pagine sono in cache', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');
    $translator = app(TranslationService::class);

    Translation::create([
        'page_id' => $pages[0]->id,
        'ai_account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'it',
        'source_hash' => $pages[0]->source_hash,
        'prompt_version' => TranslationPrompt::VERSION,
        'params_hash' => str_repeat('a', 64),
        'key_hash' => $translator->keyHash($pages[0], $account, 'test-model', 'it'),
        'status' => 'completed',
    ]);

    $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertOk()
        ->assertJsonPath('batch', null)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'cache'));

    expect(TranslationBatch::count())->toBe(0);
});

it('annulla gli item ancora in attesa', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(2, 'serial');

    $batchId = $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertCreated()
        ->json('batch.id');

    $this->postJson(route('translation-batches.cancel', $batchId))
        ->assertOk()
        ->assertJsonPath('batch.status', 'cancelled')
        ->assertJsonPath('batch.cancelled', 2)
        ->assertJsonPath('batch.pending', 0)
        ->assertJsonPath('batch.can_cancel', false);

    expect(TranslationBatchItem::where('status', 'cancelled')->count())->toBe(2);
});

it('rimette in coda solo gli item falliti', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(2, 'serial');

    $batchId = $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertCreated()
        ->json('batch.id');

    $batch = TranslationBatch::findOrFail($batchId);
    $failedItem = $batch->items()->orderBy('id')->firstOrFail();
    $failedItem->update([
        'status' => 'failed',
        'error' => 'Errore temporaneo.',
        'finished_at' => now(),
    ]);
    $batch->forceFill([
        'status' => 'failed',
        'finished_at' => now(),
    ])->save();

    $this->postJson(route('translation-batches.retry', $batch))
        ->assertOk()
        ->assertJsonPath('batch.status', 'queued')
        ->assertJsonPath('batch.pending', 2)
        ->assertJsonPath('batch.failed', 0);

    expect($failedItem->fresh()->status)->toBe('pending');

    Queue::assertPushed(ProcessTranslationBatchItem::class, 2);
});

it('rifiuta il retry se un altro batch è già attivo sullo stesso libro', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');

    $failed = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'failed',
        'total_items' => 1,
        'finished_at' => now(),
    ]);
    makeBatchItem($failed, $pages[0], $account, 'failed')->forceFill([
        'error' => 'Errore del provider.',
        'finished_at' => now(),
    ])->save();

    TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 1,
    ]);

    $this->postJson(route('translation-batches.retry', $failed))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'altra traduzione'));

    expect($failed->fresh()->status)->toBe('failed');
});

it('restituisce solo i batch attivi e accetta la lista di id richiesta', function () {
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');

    $active = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'running',
        'total_items' => 0,
    ]);

    $finished = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'completed',
        'total_items' => 0,
        'finished_at' => now(),
    ]);

    $this->getJson(route('translation-batches.index'))
        ->assertOk()
        ->assertJsonCount(1, 'batches')
        ->assertJsonPath('batches.0.id', $active->id);

    $this->getJson(route('translation-batches.index', ['ids' => [$active->id, $finished->id]]))
        ->assertOk()
        ->assertJsonCount(2, 'batches');
});

it('esegue un item, salva la traduzione e completa il batch', function () {
    fakeBatchResponse();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 1,
    ]);
    $item = makeBatchItem($batch, $pages[0], $account);

    (new ProcessTranslationBatchItem($item->id))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    $item->refresh();

    expect($item->status)->toBe('completed')
        ->and($item->translation_id)->not->toBeNull()
        ->and($item->error)->toBeNull()
        ->and($item->batch->fresh()->status)->toBe('completed')
        ->and(Translation::firstOrFail()->status)->toBe('completed');

    Http::assertSentCount(1);
});

it('salva l’errore del provider e continua con l’item seriale successivo', function () {
    Queue::fake();
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Rate limit superato'],
        ], 429),
    ]);

    [$book, $pages, $account] = makeBatchFixture(2, 'serial');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 2,
    ]);
    $first = makeBatchItem($batch, $pages[0], $account);
    $second = makeBatchItem($batch, $pages[1], $account);

    (new ProcessTranslationBatchItem($first->id))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    $first->refresh();

    expect($first->status)->toBe('failed')
        ->and($first->error)->toContain('429')
        ->and($first->batch->fresh()->status)->toBe('queued')
        ->and(Translation::firstOrFail()->status)->toBe('failed');

    Queue::assertPushed(
        ProcessTranslationBatchItem::class,
        fn (ProcessTranslationBatchItem $job) => $job->itemId === $second->id,
    );
});

it('annulla un item quando il batch è stato interrotto', function () {
    Http::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'serial');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 1,
        'cancel_requested_at' => now(),
    ]);
    $item = makeBatchItem($batch, $pages[0], $account);

    (new ProcessTranslationBatchItem($item->id))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    $item->refresh();

    expect($item->status)->toBe('cancelled')
        ->and($item->batch->fresh()->status)->toBe('cancelled');

    Http::assertNothingSent();
});

it('riesegue un item rimasto in esecuzione dopo un crash del worker', function () {
    fakeBatchResponse();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'running',
        'total_items' => 1,
    ]);
    $item = makeBatchItem($batch, $pages[0], $account, 'running');
    $item->forceFill(['started_at' => now()->subMinutes(11)])->save();

    (new ProcessTranslationBatchItem($item->id))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    expect($item->fresh()->status)->toBe('completed');
});

it('riprende forzatamente un item fresco quando il job è un takeover', function () {
    fakeBatchResponse();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'running',
        'total_items' => 1,
    ]);
    $item = makeBatchItem($batch, $pages[0], $account, 'running');
    $item->forceFill(['started_at' => now()])->save();

    (new ProcessTranslationBatchItem($item->id, takeover: true))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    expect($item->fresh()->status)->toBe('completed')
        ->and($batch->fresh()->status)->toBe('completed');
});

it('accoda un takeover quando un retry trova l’item ancora in esecuzione', function () {
    Queue::fake();
    Http::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'running',
        'total_items' => 1,
    ]);
    $item = makeBatchItem($batch, $pages[0], $account, 'running');
    $item->forceFill(['started_at' => now()])->save();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(2);

    $retried = new ProcessTranslationBatchItem($item->id);
    $retried->job = $queueJob;
    $retried->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    Queue::assertPushed(
        ProcessTranslationBatchItem::class,
        fn (ProcessTranslationBatchItem $job) => $job->itemId === $item->id && $job->takeover,
    );
    Http::assertNothingSent();
});

it('salta la chiamata AI quando la traduzione è già in cache', function () {
    Http::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');
    $translator = app(TranslationService::class);

    $translation = Translation::create([
        'page_id' => $pages[0]->id,
        'ai_account_id' => $account->id,
        'model' => 'test-model',
        'target_language' => 'it',
        'source_hash' => $pages[0]->source_hash,
        'prompt_version' => TranslationPrompt::VERSION,
        'params_hash' => str_repeat('a', 64),
        'key_hash' => $translator->keyHash($pages[0], $account, 'test-model', 'it'),
        'status' => 'completed',
    ]);

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 1,
    ]);
    $item = makeBatchItem($batch, $pages[0], $account);

    (new ProcessTranslationBatchItem($item->id))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    expect($item->fresh()->translation_id)->toBe($translation->id);

    Http::assertNothingSent();
});

it('fallisce un item quando l’account è stato disattivato', function () {
    Http::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'serial');
    $account->update(['is_active' => false]);

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 1,
    ]);
    $item = makeBatchItem($batch, $pages[0], $account);

    (new ProcessTranslationBatchItem($item->id))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    expect($item->fresh()->status)->toBe('failed')
        ->and($item->fresh()->error)->toContain('disattivato');

    Http::assertNothingSent();
});

it('rimanda l’item quando l’account seriale è occupato da un altro libro', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'serial');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 1,
    ]);
    $item = makeBatchItem($batch, $pages[0], $account);

    $lock = Cache::lock('translation-account:'.$account->id, 60);
    expect($lock->get())->toBeTrue();

    try {
        (new ProcessTranslationBatchItem($item->id))->handle(
            app(TranslationService::class),
            app(TranslationBatchService::class),
        );
    } finally {
        $lock->release();
    }

    expect($item->fresh()->status)->toBe('pending')
        ->and($batch->fresh()->status)->toBe('queued');

    Queue::assertPushed(
        ProcessTranslationBatchItem::class,
        fn (ProcessTranslationBatchItem $job) => $job->itemId === $item->id,
    );
});

it('segna fallito l’item quando il job esaurisce i tentativi e continua la catena seriale', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(2, 'serial');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'queued',
        'total_items' => 2,
    ]);
    $first = makeBatchItem($batch, $pages[0], $account);
    $second = makeBatchItem($batch, $pages[1], $account);

    (new ProcessTranslationBatchItem($first->id))->failed(
        new RuntimeException('Timeout del worker.'),
    );

    expect($first->fresh()->status)->toBe('failed')
        ->and($first->fresh()->error)->toContain('Timeout del worker')
        ->and($batch->fresh()->status)->toBe('queued');

    Queue::assertPushed(
        ProcessTranslationBatchItem::class,
        fn (ProcessTranslationBatchItem $job) => $job->itemId === $second->id,
    );
});

it('riprende la catena seriale quando un job duplicato trova l’item già completato', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(2, 'serial');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'running',
        'total_items' => 2,
    ]);
    $first = makeBatchItem($batch, $pages[0], $account, 'completed');
    $second = makeBatchItem($batch, $pages[1], $account);

    (new ProcessTranslationBatchItem($first->id))->handle(
        app(TranslationService::class),
        app(TranslationBatchService::class),
    );

    expect($batch->fresh()->status)->toBe('queued');

    Queue::assertPushed(
        ProcessTranslationBatchItem::class,
        fn (ProcessTranslationBatchItem $job) => $job->itemId === $second->id,
    );
});

it('riprende la catena seriale se il job fallisce dopo aver completato l’item', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(2, 'serial');

    $batch = TranslationBatch::create([
        'book_id' => $book->id,
        'target_language' => 'it',
        'status' => 'running',
        'total_items' => 2,
    ]);
    $first = makeBatchItem($batch, $pages[0], $account, 'completed');
    $second = makeBatchItem($batch, $pages[1], $account);

    (new ProcessTranslationBatchItem($first->id))->failed(
        new RuntimeException('Worker interrotto dopo il salvataggio.'),
    );

    expect($first->fresh()->status)->toBe('completed')
        ->and($batch->fresh()->status)->toBe('queued');

    Queue::assertPushed(
        ProcessTranslationBatchItem::class,
        fn (ProcessTranslationBatchItem $job) => $job->itemId === $second->id,
    );
});

it('legge il timeout dei job da QUEUE_JOB_TIMEOUT', function () {
    config(['queue.translation_job_timeout' => 900]);

    expect((new ProcessTranslationBatchItem(1))->timeout)->toBe(900);
});

it('valida account attivi, pagine del libro e massimo due slot', function () {
    Queue::fake();
    [$book, $pages, $account] = makeBatchFixture(1, 'parallel');
    [$otherBook, $otherPages] = makeBatchFixture(1, 'parallel');

    $this->postJson(route('translation-batches.store', $book), [
        'slots' => [
            ['slot_key' => 'a', 'account_id' => $account->id, 'model' => 'test-model'],
            ['slot_key' => 'b', 'account_id' => $account->id, 'model' => 'test-model'],
            ['slot_key' => 'a', 'account_id' => $account->id, 'model' => 'test-model'],
        ],
        'page_ids' => $pages->pluck('id')->all(),
    ])->assertUnprocessable();

    $this->postJson(route('translation-batches.store', $book), [
        'slots' => [
            ['slot_key' => 'a', 'account_id' => $account->id, 'model' => 'test-model'],
        ],
        'page_ids' => $otherPages->pluck('id')->all(),
    ])->assertUnprocessable();

    $account->update(['is_active' => false]);

    $this->postJson(route('translation-batches.store', $book), batchPayload($pages, $account))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Uno o più account selezionati non sono attivi.');
});
