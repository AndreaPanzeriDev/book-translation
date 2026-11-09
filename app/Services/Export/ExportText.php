<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\Lang;
use Locale;

final class ExportText
{
    private const SUPPORTED_LOCALES = ['it', 'en'];

    /**
     * Label for the table of contents, in the book's target language when available.
     */
    public static function tocTitle(string $language): string
    {
        return (string) Lang::get('messages.export.toc', [], self::locale($language));
    }

    /**
     * Label for the title page, in the book's target language when available.
     */
    public static function translationInto(string $language, string $languageName): string
    {
        return (string) Lang::get(
            'messages.export.translation_into',
            ['language' => $languageName],
            self::locale($language),
        );
    }

    /**
     * Human-readable language name in the book's own language.
     */
    public static function displayLanguage(string $language): string
    {
        $locale = str_replace('-', '_', $language);
        $display = Locale::getDisplayLanguage($locale, $locale);

        return $display !== '' ? $display : strtoupper($language);
    }

    private static function locale(string $language): string
    {
        $base = explode('-', strtolower($language))[0];

        return in_array($base, self::SUPPORTED_LOCALES, true)
            ? $base
            : (string) config('app.fallback_locale', 'en');
    }
}
