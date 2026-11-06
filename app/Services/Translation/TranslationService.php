<?php

namespace App\Services\Translation;

use App\Models\AiAccount;
use App\Models\Page;
use App\Models\Translation;
use Illuminate\Support\Str;
use Throwable;

class TranslationService
{
    public const DEFAULT_TEMPERATURE = 0.3;

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
        ])->save();

        $startedAt = microtime(true);

        try {
            $response = $this->client->chat(
                $account,
                $model,
                $this->prompt->messages($page, $blocks->all(), $targetLanguage),
                $temperature,
            );

            $map = $this->extractTranslations($response['content']);
            $expectedIds = range(1, $blocks->count());

            if (count($map) !== $blocks->count()) {
                throw new TranslationException(__('messages.translation.invalid_segment_count', [
                    'actual' => count($map),
                    'expected' => $blocks->count(),
                ]));
            }

            if (array_keys($map) !== $expectedIds) {
                throw new TranslationException(__('messages.translation.invalid_segment_ids'));
            }

            $translated = [];

            foreach ($blocks as $index => $block) {
                $text = $map[$index + 1] ?? null;

                if ($text === null) {
                    throw new TranslationException(__('messages.translation.missing_segment'));
                }

                $translated[] = ['block_id' => $block->id, 'text' => trim($text)];
            }

            $translation->fill([
                'status' => 'completed',
                'translated_blocks' => $translated,
                'error' => null,
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? null,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? null,
                'total_tokens' => $response['usage']['total_tokens'] ?? null,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider_response_id' => $response['id'] ?? null,
            ])->save();

            return $translation->fresh();
        } catch (Throwable $e) {
            $translation->fill([
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 2000),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ])->save();

            if ($e instanceof TranslationException) {
                throw $e;
            }

            throw new TranslationException($e->getMessage(), 0, $e);
        }
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
            throw new TranslationException(__('messages.translation.invalid_json'));
        }

        $items = $decoded['translations'] ?? $decoded;

        if (! is_array($items)) {
            throw new TranslationException(__('messages.translation.missing_translations'));
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
