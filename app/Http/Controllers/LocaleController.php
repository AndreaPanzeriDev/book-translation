<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Salva la lingua scelta dall'utente in un cookie di un anno e la applica
     * alla richiesta corrente.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('app.available_locales'))],
        ]);

        $locale = $data['locale'];

        App::setLocale($locale);
        Cookie::queue('locale', $locale, 60 * 24 * 365);

        return response()->json(['locale' => $locale]);
    }
}
