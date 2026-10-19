<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Auth\Services\AccountSettings;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switching the interface language.
 *
 * Before sign-in it is kept in the session; after, on the account too, so it
 * follows the person to another browser.
 */
class LocaleController extends Controller
{
    public function __construct(
        private readonly AccountSettings $settings,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $locale = (string) $request->validate(['locale' => ['required', Rule::in(SetLocale::SUPPORTED)]])['locale'];

        $request->session()->put(SetLocale::SESSION_KEY, $locale);

        if ($request->user() !== null) {
            $this->settings->setLocale($request->user(), $locale);
        }

        return redirect()->to($this->withoutLanguage(url()->previous()));
    }

    /**
     * Back where the switch was pressed, without a language in the address:
     * on /?lang=ar the address would otherwise choose Arabic again over the
     * choice just made (SetLocale::QUERY).
     */
    private function withoutLanguage(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return $url;
        }

        parse_str($query, $parameters);
        unset($parameters[SetLocale::QUERY]);
        $base = strtok($url, '?');
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        return $base.($parameters === [] ? '' : '?'.http_build_query($parameters)).($fragment ? '#'.$fragment : '');
    }
}
