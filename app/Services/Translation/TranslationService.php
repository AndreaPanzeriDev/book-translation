<?php

namespace App\Services\Translation;

use App\Models\AiAccount;
use App\Models\Block;
use App\Models\Page;
use App\Models\Translation;
use Illuminate\Support\Str;
use Throwable;

class TranslationService
{
    public const PROMPT_VERSION = 'v1';

    public const DEFAULT_TEMPERATURE = 0.3;

    private const LANGUAGE_NAMES = [
        'it' => 'italiano',
        'en' => 'inglese',
        'fr' => 'francese',
        'de' => 'tedesco',
        'es' => 'spagnolo',
        'pt' => 'portoghese',
        'nl' => 'olandese',
        'ru' => 'russo',
        'ja' => 'giapponese',
        'zh' => 'cinese',
        'la' => 'latino',
        'pl' => 'polacco',
        'sv' => 'svedese',
        'tr' => 'turco',
        'ar' => 'arabo',
        'ko' => 'coreano',
        'cs' => 'ceco',
        'da' => 'danese',
        'fi' => 'finlandese',
        'el' => 'greco',
        'hu' => 'ungherese',
        'no' => 'norvegese',
        'ro' => 'rumeno',
        'uk' => 'ucraino',
    ];

    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    public function translate(
        Page $page,
        AiAccount $account,
        string $model,
        string $targetLanguage,
        float $temperature = self::DEFAULT_TEMPERATURE,
    ): Translation {
        $blocks = $page->blocks()->get();

        if ($blocks->isEmpty()) {
            throw new TranslationException('La pagina non contiene testo da tradurre.');
        }

        $paramsHash = hash('sha256', json_encode(['temperature' => $temperature], JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', implode('|', [
            $page->id,
            $page->source_hash,
            $account->id,
            $model,
            $targetLanguage,
            self::PROMPT_VERSION,
            $paramsHash,
        ]));

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
            'prompt_version' => self::PROMPT_VERSION,
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
                $this->messages($page, $blocks->all(), $targetLanguage),
                $temperature,
            );

            $map = $this->extractTranslations($response['content']);

            if (count($map) !== $blocks->count()) {
                throw new TranslationException(sprintf(
                    'Il modello ha restituito %d segmenti invece di %d. Riprova o cambia modello.',
                    count($map),
                    $blocks->count(),
                ));
            }

            $translated = [];

            foreach ($blocks as $index => $block) {
                $text = $map[$index + 1] ?? null;

                if ($text === null) {
                    throw new TranslationException('Il modello ha omesso uno o più segmenti.');
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

    /**
     * @param  array<int, Block>  $blocks
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(Page $page, array $blocks, string $targetLanguage): array
    {
        $target = self::LANGUAGE_NAMES[$targetLanguage] ?? $targetLanguage;
        $source = $page->book?->language;
        $sourceName = $source ? (self::LANGUAGE_NAMES[$source] ?? $source) : 'rilevata automaticamente';

        $segments = [];

        foreach ($blocks as $index => $block) {
            $segments[] = '['.($index + 1).'] '.$block->text;
        }

        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Sei un traduttore letterario professionista e revisore editoriale.',
                    'Traduci ogni segmento numerato dal testo fornito nella lingua di destinazione richiesta.',
                    'Regole tassative:',
                    '- Rispondi ESCLUSIVAMENTE con un oggetto JSON valido nella forma {"translations":[{"id":1,"text":"..."}]}.',
                    '- Mantieni esattamente lo stesso numero di segmenti, nello stesso ordine e con gli stessi id.',
                    '- Non unire, dividere, omettere, riordinare o commentare i segmenti.',
                    '- Conserva registro, stile, punteggiatura e ritmo dell’originale.',
                    '- Non tradurre nomi propri di persona o luogo, salvo forme italiane consolidate.',
                    '- Se un segmento è già nella lingua di destinazione, riscrivilo fedelmente senza alterarlo.',
                    '- Se un segmento contiene solo numeri, simboli o testo non traducibile, restituiscilo invariato.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => implode("\n\n", [
                    'Lingua di origine: '.$sourceName,
                    'Lingua di destinazione: '.$target.' ('.$targetLanguage.')',
                    "Traduci i seguenti segmenti mantenendo gli id:\n\n".implode("\n\n", $segments),
                ]),
            ],
        ];
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
            throw new TranslationException('Il modello non ha restituito un JSON valido.');
        }

        $items = $decoded['translations'] ?? $decoded;

        if (! is_array($items)) {
            throw new TranslationException('La risposta del modello non contiene l’elenco delle traduzioni.');
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
