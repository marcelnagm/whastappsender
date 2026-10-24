<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Defines the locale of every web request.
 *
 * Priority: logged user preference > session > cookie > browser
 * (Accept-Language) > config('languages.default').
 */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale(config("languages.supported.{$locale}.carbon", $locale));

        return $next($request);
    }

    protected function resolve(Request $request): string
    {
        $supported = array_keys(config('languages.supported', []));

        $candidates = [
            optional($request->user())->locale,
            $request->hasSession() ? $request->session()->get(config('languages.session_key')) : null,
            $request->cookie(config('languages.cookie')),
            $this->fromBrowser($request, $supported),
            config('languages.default'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return config('app.fallback_locale', 'en');
    }

    /**
     * Matches the Accept-Language header against the supported locales.
     * "pt-BR" matches pt_BR exactly; "pt-PT" or "pt" falls back to pt_BR.
     */
    protected function fromBrowser(Request $request, array $supported): ?string
    {
        $lookup = [];
        foreach ($supported as $code) {
            $lookup[strtolower($code)] = $code;
        }

        foreach ($request->getLanguages() as $language) {
            $normalized = strtolower(str_replace('-', '_', $language));

            if (isset($lookup[$normalized])) {
                return $lookup[$normalized];
            }

            $primary = explode('_', $normalized)[0];
            foreach ($lookup as $key => $code) {
                if ($key === $primary || str_starts_with($key, $primary . '_')) {
                    return $code;
                }
            }
        }

        return null;
    }
}
