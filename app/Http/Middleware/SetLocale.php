<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Applica la lingua scelta dall'utente (cookie) oppure quella dichiarata
     * dal browser. Il fallback è sempre la lingua predefinita dell'app.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_values(config('app.available_locales', ['it']));
        $locale = $request->cookie('locale');

        if (! is_string($locale) || ! in_array($locale, $available, true)) {
            $locale = $request->getPreferredLanguage($available) ?? config('app.locale');
        }

        if (! in_array($locale, $available, true)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
