<?php

namespace App\Http\Controllers;

use App\Models\AiAccount;
use App\Models\AiModel;
use App\Services\Translation\OpenAiCompatibleClient;
use App\Services\Translation\TranslationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiAccountController extends Controller
{
    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    public function index(): Response
    {
        $accounts = AiAccount::with('models')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (AiAccount $account) => $account->toClientArray());

        return Inertia::render('Accounts', [
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['request_mode'] ??= AiAccount::REQUEST_MODE_SERIAL;

        $account = AiAccount::create($data);

        try {
            $count = $this->importModels($account);
        } catch (TranslationException $e) {
            return back()->with(
                'error',
                'Account “'.$account->name.'” creato, ma non è stato possibile importare i modelli: '
                .$e->getMessage()
                .' Potrai riprovare con “Sincronizza modelli”.'
            );
        }

        if ($count === 0) {
            return back()->with(
                'success',
                'Account “'.$account->name.'” creato. Il provider non ha restituito modelli: potrai sincronizzarli manualmente.'
            );
        }

        return back()->with(
            'success',
            'Account “'.$account->name.'” creato: '
            .($count === 1 ? '1 modello importato.' : $count.' modelli importati.')
        );
    }

    public function update(Request $request, AiAccount $account): RedirectResponse
    {
        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active');

        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        $account->update($data);

        return back()->with('success', 'Account aggiornato.');
    }

    public function destroy(AiAccount $account): RedirectResponse
    {
        $name = $account->name;
        $account->delete();

        return back()->with('success', 'Account eliminato: '.$name.'.');
    }

    public function syncModels(AiAccount $account): RedirectResponse
    {
        try {
            $count = $this->importModels($account);
        } catch (TranslationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $count === 1
                ? '1 modello sincronizzato.'
                : $count.' modelli sincronizzati.'
        );
    }

    public function test(AiAccount $account): RedirectResponse
    {
        try {
            $models = $this->client->fetchModels($account);
        } catch (TranslationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            'Connessione riuscita: '.count($models).' modelli disponibili.'
        );
    }

    /**
     * Importa i modelli esposti dal provider, aggiornando quelli esistenti e
     * rimuovendo quelli non più disponibili.
     */
    private function importModels(AiAccount $account): int
    {
        $models = $this->client->fetchModels($account);

        foreach ($models as $modelId) {
            AiModel::updateOrCreate(
                ['ai_account_id' => $account->id, 'model_id' => $modelId],
                ['fetched_at' => now()],
            );
        }

        if ($models !== []) {
            $account->models()->whereNotIn('model_id', $models)->delete();
        }

        return count($models);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'in:openai,openrouter,ollama,custom'],
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'default_model' => ['nullable', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
            'request_mode' => ['nullable', 'string', 'in:serial,parallel'],
        ], [
            'name.required' => 'Il nome dell’account è obbligatorio.',
            'base_url.required' => 'L’URL di base è obbligatorio.',
            'base_url.url' => 'L’URL di base non è valido.',
            'provider.in' => 'Provider non riconosciuto.',
            'request_mode.in' => 'Modalità richieste non riconosciuta.',
        ]);
    }
}
