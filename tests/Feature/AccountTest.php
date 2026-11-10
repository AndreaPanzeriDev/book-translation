<?php

use App\Models\AiAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function accountPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'OpenAI principale',
        'provider' => 'openai',
        'base_url' => 'https://api.example.com/v1',
        'api_key' => 'super-secret-key',
        'default_model' => 'gpt-test',
        'is_active' => true,
    ], $overrides);
}

it('crea un account cifrando la chiave API', function () {
    Http::fake();

    $this->post('/accounts', accountPayload())->assertRedirect();

    $account = AiAccount::firstOrFail();
    $rawKey = DB::table('ai_accounts')->where('id', $account->id)->value('api_key');

    expect($rawKey)->not->toBe('super-secret-key')
        ->and($account->api_key)->toBe('super-secret-key')
        ->and($account->has_api_key)->toBeTrue();
});

it('usa la modalità in serie come default', function () {
    Http::fake();

    $this->post('/accounts', accountPayload())->assertRedirect();

    expect(AiAccount::firstOrFail()->request_mode)->toBe('serial');
});

it('salva la modalità parallela e la espone al client', function () {
    Http::fake();

    $this->post('/accounts', accountPayload(['request_mode' => 'parallel']))->assertRedirect();

    $account = AiAccount::firstOrFail();

    expect($account->request_mode)->toBe('parallel');

    $this->get('/accounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounts')
            ->where('accounts.0.request_mode', 'parallel'));
});

it('rifiuta una modalità richieste non valida', function () {
    Http::fake();

    $this->post('/accounts', accountPayload(['request_mode' => 'random']))
        ->assertSessionHasErrors('request_mode');

    expect(AiAccount::count())->toBe(0);
});

it('usa quattro richieste parallele come default', function () {
    Http::fake();

    $this->post('/accounts', accountPayload(['request_mode' => 'parallel']))->assertRedirect();

    expect(AiAccount::firstOrFail()->max_parallel_requests)->toBe(4);
});

it('salva il limite di richieste parallele e lo espone al client', function () {
    Http::fake();

    $this->post('/accounts', accountPayload([
        'request_mode' => 'parallel',
        'max_parallel_requests' => 3,
    ]))->assertRedirect();

    $account = AiAccount::firstOrFail();

    expect($account->max_parallel_requests)->toBe(3)
        ->and($account->parallelLimit())->toBe(3);

    $this->get('/accounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounts')
            ->where('accounts.0.request_mode', 'parallel')
            ->where('accounts.0.max_parallel_requests', 3));
});

it('rifiuta un limite di richieste parallele non valido', function ($limit) {
    Http::fake();

    $this->post('/accounts', accountPayload([
        'request_mode' => 'parallel',
        'max_parallel_requests' => $limit,
    ]))->assertSessionHasErrors('max_parallel_requests');

    expect(AiAccount::count())->toBe(0);
})->with([0, 51, 'molte']);

it('aggiorna la modalità richieste di un account', function () {
    Http::fake();

    $account = AiAccount::create(accountPayload());

    $this->put("/accounts/{$account->id}", accountPayload([
        'request_mode' => 'parallel',
    ]))->assertRedirect();

    expect($account->refresh()->request_mode)->toBe('parallel');
});

it('aggiorna il limite di richieste parallele di un account', function () {
    Http::fake();

    $account = AiAccount::create(accountPayload(['request_mode' => 'parallel']));

    $this->put("/accounts/{$account->id}", accountPayload([
        'request_mode' => 'parallel',
        'max_parallel_requests' => 2,
    ]))->assertRedirect();

    expect($account->refresh()->max_parallel_requests)->toBe(2);
});

it('importa automaticamente i modelli alla creazione dell’account', function () {
    Http::fake([
        'https://api.example.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'modello-b'],
                ['id' => 'modello-a'],
            ],
        ]),
    ]);

    $this->post('/accounts', accountPayload())
        ->assertRedirect()
        ->assertSessionHas('success', 'Account “OpenAI principale” creato: 2 modelli importati.');

    $account = AiAccount::firstOrFail();

    expect($account->models()->orderBy('model_id')->pluck('model_id')->all())
        ->toBe(['modello-a', 'modello-b']);
});

it('crea comunque l’account se il provider non restituisce i modelli', function () {
    Http::fake([
        'https://api.example.com/v1/models' => Http::response('errore', 500),
    ]);

    $this->post('/accounts', accountPayload())
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(AiAccount::count())->toBe(1)
        ->and(AiAccount::firstOrFail()->models()->count())->toBe(0);
});

it('crea comunque l’account se il provider non è raggiungibile', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->post('/accounts', accountPayload())
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            fn (string $message) => str_contains($message, 'Impossibile contattare il provider'),
        );

    expect(AiAccount::count())->toBe(1);
});

it('non espone mai la chiave API nella pagina account', function () {
    AiAccount::create(accountPayload());

    $this->get('/accounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounts')
            ->has('accounts', 1)
            ->where('accounts.0.name', 'OpenAI principale')
            ->where('accounts.0.has_api_key', true))
        ->assertDontSee('super-secret-key');
});

it('mantiene la chiave esistente quando il campo viene lasciato vuoto', function () {
    $account = AiAccount::create(accountPayload(['api_key' => 'vecchia-chiave']));

    $this->put("/accounts/{$account->id}", accountPayload([
        'name' => 'OpenAI rinominato',
        'api_key' => '',
    ]))->assertRedirect();

    $account->refresh();

    expect($account->name)->toBe('OpenAI rinominato')
        ->and($account->api_key)->toBe('vecchia-chiave');
});

it('sincronizza i modelli dal provider e rimuove quelli obsoleti', function () {
    $account = AiAccount::create(accountPayload());
    $account->models()->create(['model_id' => 'modello-vecchio']);

    Http::fake([
        'https://api.example.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'modello-b'],
                ['id' => 'modello-a'],
            ],
        ]),
    ]);

    $this->post("/accounts/{$account->id}/sync-models")->assertRedirect();

    expect($account->models()->orderBy('model_id')->pluck('model_id')->all())
        ->toBe(['modello-a', 'modello-b']);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.example.com/v1/models');
});

it('elimina un account', function () {
    $account = AiAccount::create(accountPayload());

    $this->delete("/accounts/{$account->id}")->assertRedirect();

    expect(AiAccount::count())->toBe(0);
});
