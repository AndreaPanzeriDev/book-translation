<?php

namespace App\Services\Translation;

use App\Models\AiAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenAiCompatibleClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, usage: array<string, int|null>, id: ?string, finish_reason: ?string}
     */
    public function chat(
        AiAccount $account,
        string $model,
        array $messages,
        float $temperature = 0.3,
        ?array $responseFormat = null,
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => false,
        ];

        if ($responseFormat !== null) {
            $payload['response_format'] = $responseFormat;
        }

        $response = $this->send($account, $payload);

        if ($response->failed() && $responseFormat !== null && $this->responseFormatUnsupported($response)) {
            Log::warning('Il provider non supporta response_format: nuovo tentativo senza schema.', [
                'account_id' => $account->id,
                'model' => $model,
                'status' => $response->status(),
                'response_format' => $responseFormat['type'] ?? null,
            ]);

            if (($responseFormat['type'] ?? null) === 'json_schema') {
                $payload['response_format'] = ['type' => 'json_object'];
                $response = $this->send($account, $payload);
            }

            if ($response->failed() && isset($payload['response_format']) && $this->responseFormatUnsupported($response)) {
                unset($payload['response_format']);
                $response = $this->send($account, $payload);
            }
        }

        if ($response->failed()) {
            $detail = trim(Str::limit($response->body(), 400));

            throw new TranslationException(__(
                $detail !== ''
                    ? 'messages.translation.provider_http_error_with_detail'
                    : 'messages.translation.provider_http_error',
                [
                    'status' => $response->status(),
                    'detail' => $detail,
                ],
            ));
        }

        $data = $response->json();
        $content = data_get($data, 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new TranslationException(__('messages.translation.provider_empty_response'), retryable: true);
        }

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => data_get($data, 'usage.prompt_tokens'),
                'completion_tokens' => data_get($data, 'usage.completion_tokens'),
                'total_tokens' => data_get($data, 'usage.total_tokens'),
            ],
            'id' => data_get($data, 'id'),
            'finish_reason' => data_get($data, 'choices.0.finish_reason'),
        ];
    }

    private function send(AiAccount $account, array $payload): Response
    {
        return Http::withHeaders($this->headers($account))
            ->connectTimeout((int) config('ai.connect_timeout', 15))
            ->timeout((int) config('ai.request_timeout', 300))
            ->post($this->endpoint($account->base_url), $payload);
    }

    private function responseFormatUnsupported(Response $response): bool
    {
        if (! in_array($response->status(), [400, 422], true)) {
            return false;
        }

        $body = Str::lower($response->body());

        foreach (['response_format', 'response format', 'json_schema', 'structured output', 'json mode'] as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function fetchModels(AiAccount $account): array
    {
        try {
            $response = Http::withHeaders($this->headers($account))
                ->connectTimeout(10)
                ->timeout(30)
                ->get($this->modelsEndpoint($account->base_url));
        } catch (ConnectionException $e) {
            throw new TranslationException(
                __('messages.translation.provider_contact_failed', ['error' => $e->getMessage()]),
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw new TranslationException(__('messages.translation.provider_models_failed', [
                'status' => $response->status(),
                'detail' => Str::limit($response->body(), 300),
            ]));
        }

        $payload = $response->json();
        $items = data_get($payload, 'data', data_get($payload, 'models', []));

        if (! is_array($items)) {
            return [];
        }

        $models = [];

        foreach ($items as $item) {
            $id = is_array($item)
                ? ($item['id'] ?? $item['name'] ?? null)
                : (is_string($item) ? $item : null);

            if (is_string($id) && trim($id) !== '') {
                $models[] = trim($id);
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }

    /**
     * @return array<string, string>
     */
    private function headers(AiAccount $account): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (filled($account->api_key)) {
            $headers['Authorization'] = 'Bearer '.$account->api_key;
        }

        foreach (($account->extra_headers ?? []) as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    private function endpoint(string $baseUrl): string
    {
        $base = rtrim(trim($baseUrl), '/');

        if (str_ends_with($base, '/chat/completions')) {
            return $base;
        }

        return $base.'/chat/completions';
    }

    private function modelsEndpoint(string $baseUrl): string
    {
        $base = rtrim(trim($baseUrl), '/');

        if (str_ends_with($base, '/chat/completions')) {
            $base = substr($base, 0, -strlen('/chat/completions'));
        }

        return $base.'/models';
    }
}
