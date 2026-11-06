import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import en from './locales/en.json';
import it from './locales/it.json';

export type Locale = 'it' | 'en';

export const SUPPORTED_LOCALES: Locale[] = ['it', 'en'];

export function normalizeLocale(locale: string | null | undefined): Locale {
    return locale === 'en' ? 'en' : 'it';
}

export function currentLocale(): Locale {
    return normalizeLocale(i18n.resolvedLanguage ?? i18n.language);
}

/**
 * Inizializza i18next in modo sincrono: le risorse sono incluse nel bundle e
 * la lingua iniziale arriva dal server (cookie o Accept-Language).
 */
export function initI18n(locale: string | null | undefined): void {
    const lng = normalizeLocale(locale);

    if (i18n.isInitialized) {
        if (i18n.resolvedLanguage !== lng) {
            void i18n.changeLanguage(lng);
        }

        return;
    }

    void i18n.use(initReactI18next).init({
        resources: {
            it: { translation: it },
            en: { translation: en },
        },
        lng,
        fallbackLng: 'it',
        supportedLngs: SUPPORTED_LOCALES,
        interpolation: {
            escapeValue: false,
        },
        returnNull: false,
        initAsync: false,
    });
}

export default i18n;
