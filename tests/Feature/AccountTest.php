<?php

use App\Models\AiAccount;
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
    $this->post('/accounts', accountPayload())->assertRedirect();

    $account = AiAccount::firstOrFail();
    $rawKey = DB::table('ai_accounts')->where('id', $account->id)->value('api_key');

    expect($rawKey)->not->toBe('super-secret-key')
        ->and($account->api_key)->toBe('super-secret-key')
        ->and($account->has_api_key)->toBeTrue();
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
