<?php

namespace App\Services\Translation;

use App\Models\AiAccount;
use App\Models\Block;
use App\Models\Page;
use App\Models\Translation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TranslationService
{
    public const DEFAULT_TEMPERATURE = 0.3;

    private const MAX_ATTEMPTS = 2;

    private ?string $lastRawResponse = null;

    public function __construct(
        private readonly OpenAiCompatibleClient $client,
        private readonly TranslationPrompt $prompt,
    ) {}

    public function keyHash(
        Page $page,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        float $temperature = self::DEFAULT_TEMPERATURE,
    ): string {
        return hash('sha256', implode('|', [
            $page->id,
            $page->source_hash,
            $account->id,
            $model,
            $targetLanguage,
            TranslationPrompt::VERSION,
            $this->paramsHash($temperature),
        ]));
    }

    public function findCompleted(
        Page $page,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        float $temperature = self::DEFAULT_TEMPERATURE,
    ): ?Translation {
        return Translation::query()
            ->where('key_hash', $this->keyHash($page, $account, $model, $targetLanguage, $temperature))
            ->where('status', 'completed')
            ->first();
    }

    public function translate(
        Page $page,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        float $temperature = self::DEFAULT_TEMPERATURE,
    ): Translation {
        $blocks = $page->blocks()->get();

        if ($blocks->isEmpty()) {
            throw new TranslationException(__('messages.translation.empty_page'));
        }

        $paramsHash = $this->paramsHash($temperature);
        $keyHash = $this->keyHash($page, $account, $model, $targetLanguage, $temperature);

        $translation = Translation::firstWhere('key_hash', $keyHash);

        if ($translation?->status === 'completed') {
            return $translation;
        }

        $translation ??= new Translation([
            'page_id' => $page->id,
            'ai_account_id' => $account->id,
            'model' => $model,
            'target_language' => $targetLanguage,
            'source_hash' => $page->source_hash,
            'prompt_version' => TranslationPrompt::VERSION,
            'params_hash' => $paramsHash,
            'key_hash' => $keyHash,
        ]);

        $translation->fill([
            'status' => 'running',
            'error' => null,
            'translated_blocks' => null,
            'raw_response' => null,
        ])->save();

        $startedAt = microtime(true);
        $this->lastRawResponse = null;

        try {
            $result = $this->requestTranslation(
                $page,
                $account,
                $model,
                $targetLanguage,
                $temperature,
                $blocks->all(),
            );

            $translation->fill([
                'status' => 'completed',
                'translated_blocks' => $result['translated'],
                'error' => null,
                'prompt_tokens' => $result['usage']['prompt_tokens'],
                'completion_tokens' => $result['usage']['completion_tokens'],
                'total_tokens' => $result['usage']['total_tokens'],
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider_response_id' => $result['response_id'],
                'raw_response' => null,
            ])->save();

            return $translation->fresh();
        } catch (Throwable $e) {
            $translation->fill([
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 2000),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'raw_response' => $this->lastRawResponse === null
                    ? null
                    : Str::limit($this->lastRawResponse, 10000),
            ])->save();

            if ($e instanceof TranslationException) {
                throw $e;
            }

            throw new TranslationException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<int, Block>  $blocks
     * @return array{translated: array<int, array{block_id: int, text: string}>, usage: array<string, int|null>, response_id: ?string}
     */
    private function requestTranslation(
        Page $page,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        float $temperature,
        array $blocks,
    ): array {
        $messages = $this->prompt->messages($page, $blocks, $targetLanguage);
        $responseFormat = $this->responseFormat(count($blocks));
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->client->chat(
                    $account,
                    $model,
                    $messages,
                    $temperature,
                    $responseFormat,
                );

                $this->lastRawResponse = $response['content'];

                return [
                    'translated' => $this->buildTranslatedBlocks(
                        $blocks,
                        $this->extractTranslations($response['content']),
                    ),
                    'usage' => $this->normalizeUsage($response['usage']),
                    'response_id' => $response['id'],
                ];
            } catch (TranslationException $e) {
                $lastException = $e;

                if (! $e->retryable) {
                    throw $e;
                }

                if ($attempt < self::MAX_ATTEMPTS) {
                    Log::warning('Risposta di traduzione non valida, nuovo tentativo.', [
                        'page_id' => $page->id,
                        'model' => $model,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::warning('Traduzione per-blocco di fallback.', [
            'page_id' => $page->id,
            'model' => $model,
            'blocks' => count($blocks),
            'error' => $lastException?->getMessage(),
        ]);

        return $this->translateIndividually(
            $page,
            $account,
            $model,
            $targetLanguage,
            $temperature,
            $blocks,
        );
    }

    /**
     * Fallback estremo: un blocco per richiesta, risposta in testo semplice.
     *
     * @param  array<int, Block>  $blocks
     * @return array{translated: array<int, array{block_id: int, text: string}>, usage: array<string, int|null>, response_id: ?string}
     */
    private function translateIndividually(
        Page $page,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        float $temperature,
        array $blocks,
    ): array {
        $translated = [];
        $usage = $this->normalizeUsage(null);
        $responseId = null;

        foreach ($blocks as $block) {
            $response = $this->client->chat(
                $account,
                $model,
                $this->prompt->singleBlockMessages($page, $block, $targetLanguage),
                $temperature,
            );

            $this->lastRawResponse = $response['content'];
            $text = $this->cleanSingleBlockTranslation($response['content']);

            if ($text === '') {
                throw new TranslationException(
                    __('messages.translation.missing_segment'),
                    retryable: true,
                );
            }

            $translated[] = [
                'block_id' => $block->id,
                'text' => $text,
            ];

            $usage = $this->mergeUsage($usage, $response['usage']);
            $responseId = $response['id'] ?? $responseId;
        }

        return [
            'translated' => $translated,
            'usage' => $usage,
            'response_id' => $responseId,
        ];
    }

    /**
     * @param  array<int, Block>  $blocks
     * @param  array<int, string>  $map
     * @return array<int, array{block_id: int, text: string}>
     */
    private function buildTranslatedBlocks(array $blocks, array $map): array
    {
        $expectedIds = range(1, count($blocks));

        if (count($map) !== count($blocks)) {
            throw new TranslationException(__('messages.translation.invalid_segment_count', [
                'actual' => count($map),
                'expected' => count($blocks),
            ]), retryable: true);
        }

        if (array_keys($map) !== $expectedIds) {
            throw new TranslationException(
                __('messages.translation.invalid_segment_ids'),
                retryable: true,
            );
        }

        $translated = [];

        foreach ($blocks as $index => $block) {
            $text = $map[$index + 1] ?? null;

            if ($text === null) {
                throw new TranslationException(
                    __('messages.translation.missing_segment'),
                    retryable: true,
                );
            }

            $translated[] = ['block_id' => $block->id, 'text' => trim($text)];
        }

        return $translated;
    }

    private function responseFormat(int $count): array
    {
        $ids = range(1, $count);

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'translation_response',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'translations' => [
                            'type' => 'array',
                            'minItems' => $count,
                            'maxItems' => $count,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer', 'enum' => $ids],
                                    'text' => ['type' => 'string'],
                                ],
                                'required' => ['id', 'text'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['translations'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, int|null>|null  $usage
     * @return array<string, int|null>
     */
    private function normalizeUsage(?array $usage): array
    {
        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
        ];
    }

    /**
     * @param  array<string, int|null>  $total
     * @param  array<string, int|null>  $usage
     * @return array<string, int|null>
     */
    private function mergeUsage(array $total, array $usage): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            $value = $usage[$key] ?? null;

            if ($value !== null) {
                $total[$key] = (int) ($total[$key] ?? 0) + (int) $value;
            }
        }

        return $total;
    }

    private function cleanSingleBlockTranslation(string $content): string
    {
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            $items = $decoded['translations'] ?? $decoded;

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }

                    if (is_array($item) && is_string($item['text'] ?? null) && trim($item['text']) !== '') {
                        return trim($item['text']);
                    }
                }
            }
        }

        $content = preg_replace('/^\s*\[\d+\]\s*(?:\([^)]*\)\s*)?/u', '', $content) ?? $content;
        $content = preg_replace('/^\s*\((?:p|h[1-6]|li|blockquote|pre|td|th)\)\s*/iu', '', $content) ?? $content;

        return trim($content);
    }

    private function paramsHash(float $temperature): string
    {
        return hash('sha256', json_encode(['temperature' => $temperature], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int, string>
     */
    private function extractTranslations(string $content): array
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(.*?)```/s', $content, $matches)) {
            $content = trim($matches[1]);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new TranslationException(
                __('messages.translation.invalid_json'),
                retryable: true,
            );
        }

        $items = $decoded['translations'] ?? $decoded;

        if (! is_array($items)) {
            throw new TranslationException(
                __('messages.translation.missing_translations'),
                retryable: true,
            );
        }

        $map = [];

        foreach (array_values($items) as $index => $item) {
            if (is_string($item)) {
                $map[$index + 1] = $item;

                continue;
            }

            if (! is_array($item) || ! array_key_exists('text', $item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? $index + 1);
            $text = $item['text'];

            if (is_string($text)) {
                $map[$id] = $text;
            }
        }

        ksort($map);

        return $map;
    }
}
