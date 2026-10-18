<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language a page is written in: the person's own choice, then the one
 * this browser picked before signing in, then the platform's default
 * (account.locale, whose person level is users.locale), then the
 * deployment's.
 */
class SetLocale
{
    /** The languages the interface is written in. */
    public const SUPPORTED = ['en', 'ar'];

    public const SESSION_KEY = 'locale';

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null)
            ?? $this->customizations->own('account.locale', Level::Platform, Scope::platform());

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
