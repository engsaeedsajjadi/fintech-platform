<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active locale for web and API requests.
 *
 * Priority: explicit session choice (set by the locale switcher) →
 * Accept-Language header (first supported language) → app default.
 * Only `en` and `fa` are supported; anything else falls back to the
 * default instead of throwing or leaking an arbitrary locale into
 * translators/formatters. There is no per-user locale column yet, so the
 * choice lives in the session for web and in the header for API clients.
 */
final class SetLocale
{
    /** @var list<string> */
    public const SUPPORTED = ['en', 'fa'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        \Carbon\Carbon::setLocale($locale);

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        // API requests have no session; hasSession() guards the lookup.
        if ($request->hasSession()) {
            $sessionLocale = (string) $request->session()->get('locale', '');
            if (in_array($sessionLocale, self::SUPPORTED, true)) {
                return $sessionLocale;
            }
        }

        $header = (string) $request->header('Accept-Language', '');
        foreach (explode(',', $header) as $part) {
            $lang = strtolower(trim(explode(';', trim($part))[0]));
            $lang = explode('-', $lang)[0];
            if (in_array($lang, self::SUPPORTED, true)) {
                return $lang;
            }
        }

        $default = (string) config('app.locale', 'en');

        return in_array($default, self::SUPPORTED, true) ? $default : 'en';
    }
}
