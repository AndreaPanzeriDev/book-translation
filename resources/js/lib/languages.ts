import axios from 'axios';

export const LANGUAGES = [
    { value: 'it', label: 'Italiano' },
    { value: 'en', label: 'Inglese' },
    { value: 'fr', label: 'Francese' },
    { value: 'de', label: 'Tedesco' },
    { value: 'es', label: 'Spagnolo' },
    { value: 'pt', label: 'Portoghese' },
    { value: 'nl', label: 'Olandese' },
    { value: 'ru', label: 'Russo' },
    { value: 'ja', label: 'Giapponese' },
    { value: 'zh', label: 'Cinese' },
    { value: 'la', label: 'Latino' },
    { value: 'pl', label: 'Polacco' },
    { value: 'sv', label: 'Svedese' },
    { value: 'tr', label: 'Turco' },
    { value: 'ar', label: 'Arabo' },
    { value: 'ko', label: 'Coreano' },
];

export function languageLabel(code: string | null | undefined): string {
    if (!code) {
        return 'Lingua non dichiarata';
    }

    return LANGUAGES.find((language) => language.value === code)?.label ?? code.toUpperCase();
}

export function errorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;

        if (data?.message) {
            return data.message;
        }

        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

        if (firstError) {
            return firstError;
        }

        return error.message;
    }

    return error instanceof Error ? error.message : 'Errore sconosciuto.';
}

export function providerLabel(provider: string): string {
    return (
        {
            openai: 'OpenAI',
            openrouter: 'OpenRouter',
            ollama: 'Ollama',
            custom: 'Compatibile OpenAI',
        }[provider] ?? provider
    );
}

export const PROVIDER_PRESETS = [
    {
        value: 'openai',
        label: 'OpenAI',
        baseUrl: 'https://api.openai.com/v1',
        hint: 'Chiave API OpenAI. Modelli: gpt-4o, gpt-4o-mini, o3-mini…',
    },
    {
        value: 'openrouter',
        label: 'OpenRouter',
        baseUrl: 'https://openrouter.ai/api/v1',
        hint: 'Un’unica chiave per moltissimi modelli. Ottimo per confronti.',
    },
    {
        value: 'ollama',
        label: 'Ollama (locale)',
        baseUrl: 'http://host.docker.internal:11434/v1',
        hint: 'Server Ollama sulla macchina host. Nessuna chiave richiesta.',
    },
    {
        value: 'custom',
        label: 'Compatibile OpenAI',
        baseUrl: '',
        hint: 'Qualsiasi server con endpoint /v1/chat/completions.',
    },
];
