import axios from 'axios';

import i18n, { currentLocale } from '@/i18n';

export const LANGUAGES = [
    'it',
    'en',
    'fr',
    'de',
    'es',
    'pt',
    'nl',
    'ru',
    'ja',
    'zh',
    'la',
    'pl',
    'sv',
    'tr',
    'ar',
    'ko',
] as const;

export function languageLabel(code: string | null | undefined): string {
    if (!code) {
        return i18n.t('common.languageUnknown');
    }

    const known = (LANGUAGES as readonly string[]).includes(code);

    if (!known || typeof Intl.DisplayNames !== 'function') {
        return code.toUpperCase();
    }

    try {
        return (
            new Intl.DisplayNames([currentLocale()], { type: 'language' }).of(code) ??
            code.toUpperCase()
        );
    } catch {
        return code.toUpperCase();
    }
}

export function errorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as
            | { message?: string; errors?: Record<string, string[]> }
            | undefined;

        if (data?.message) {
            return data.message;
        }

        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

        if (firstError) {
            return firstError;
        }

        return error.message;
    }

    return error instanceof Error ? error.message : i18n.t('common.unknownError');
}

export function providerLabel(provider: string): string {
    const key = `providers.${provider}`;
    const label = i18n.t(key);

    return label === key ? provider : label;
}

export const PROVIDER_PRESETS = [
    {
        value: 'openai',
        baseUrl: 'https://api.openai.com/v1',
    },
    {
        value: 'openrouter',
        baseUrl: 'https://openrouter.ai/api/v1',
    },
    {
        value: 'ollama',
        baseUrl: 'http://host.docker.internal:11434/v1',
    },
    {
        value: 'custom',
        baseUrl: '',
    },
] as const;
