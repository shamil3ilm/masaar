<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language a page is written in: the person's own choice, then the one
 * this browser picked before signing in, then the deployment's default.
 */
class SetLocale
{
    /** The languages the interface is written in. */
    public const SUPPORTED = ['en', 'ar'];

    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null);

        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * Whether the current language is written right to left.
     */
    public static function rtl(): bool
    {
        return app()->getLocale() === 'ar';
    }
}
