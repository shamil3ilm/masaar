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
 * The language a page is written in: a public page's own address first
 * (?lang=, see PUBLIC_PAGES), then the person's own choice, then the one
 * this browser picked before signing in, then the platform's default
 * (account.locale, whose person level is users.locale), then the
 * deployment's.
 */
class SetLocale
{
    /** The languages the interface is written in. */
    public const SUPPORTED = ['en', 'ar'];

    public const SESSION_KEY = 'locale';

    /** The query parameter that names a public page's language in its address. */
    public const QUERY = 'lang';

    /**
     * The public pages each language has an address of its own for -
     * /?lang=ar, /privacy?lang=en - so a search engine can list both and
     * a link can open either (sitemap.xml, the pages' hreflang links).
     */
    public const PUBLIC_PAGES = ['home', 'privacy', 'terms', 'developers'];

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromAddress($request)
            ?? $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null)
            ?? $this->customizations->own('account.locale', Level::Platform, Scope::platform());

        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * A public page's address in one language, or its language-neutral
     * address (null): the one search engines are told is the default.
     */
    public static function addressFor(string $page, ?string $locale): string
    {
        // route('home') has no trailing slash; the page's address does.
        $address = $page === 'home' ? rtrim(route($page), '/').'/' : route($page);

        return $locale === null ? $address : $address.'?'.self::QUERY.'='.$locale;
    }

    /**
     * A public page opened at one language's address is in that language,
     * whoever opens it, and the choice is kept for the pages after it as
     * the language switch would keep it. Anywhere else the parameter means
     * nothing.
     */
    private function fromAddress(Request $request): ?string
    {
        $locale = $request->query(self::QUERY);

        if (! $request->isMethod('GET') || ! is_string($locale) || ! in_array($locale, self::SUPPORTED, true) || ! $request->routeIs(...self::PUBLIC_PAGES)) {
            return null;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $locale);
        }

        return $locale;
    }

    /**
     * Whether the current language is written right to left.
     */
    public static function rtl(): bool
    {
        return app()->getLocale() === 'ar';
    }
}
