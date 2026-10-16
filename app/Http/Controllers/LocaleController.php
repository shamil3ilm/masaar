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

        return back();
    }
}
