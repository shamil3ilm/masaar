{{--
    Not found.

    Standalone rather than on the public layout: a 404 for an address no
    route matches is rendered without the web middleware, so there is no
    session - no CSRF token for the language form, no signed-in user to ask
    about. It reads only the theme and the public-site settings.

    The line under the heading points at the home page's contact section, so
    someone who followed a broken link can say what they wanted. With the
    parent brand's signs on (Settings: site.easter_eggs), it says so in that
    brand's words.
--}}
@php
    // Rendered by the exception handler as errors::404, a name the theme
    // composer does not match, so the theme is asked for here.
    $theme ??= app(\App\Domains\Branding\Services\ThemeService::class)->forRequest(request());
    $eggs = $publicSite->easterEggs();
    $contact = $publicSite->contactLink();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Page not found') }} - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="min-h-screen bg-ground text-fg" @if ($eggs) data-easter-eggs data-easter-close="{{ __('Close') }}" @endif>
    <div class="flex min-h-screen flex-col">
        <header class="mx-auto flex w-full max-w-page items-center px-4 py-3 sm:px-6">
            <a href="{{ url('/') }}" class="inline-flex rounded">
                <x-logo :size="32" name name-class="text-lg font-semibold tracking-tight" />
            </a>
        </header>

        <main id="content" class="flex flex-1 items-center px-4 pb-16 sm:px-6">
            <div class="mx-auto w-full max-w-xl">
                <p class="text-eyebrow font-semibold uppercase text-accent" dir="ltr">404</p>
                <h1 class="mt-2 text-title font-semibold">{{ __('Page not found') }}</h1>
                <p class="mt-4 text-lead text-muted" data-not-found-line>
                    {{ __('Couldn\'t find that.') }}
                    @if ($eggs)
                        <a href="{{ $contact }}" class="text-fg underline-offset-4 hover:underline"><x-parent-brand /> {{ __('what you were looking for') }}</a>
                    @else
                        <a href="{{ $contact }}" class="font-medium text-accent underline-offset-4 hover:underline">{{ __('Tell us what you were looking for.') }}</a>
                    @endif
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <x-ui.button size="lg" :href="url('/')">{{ __('Go to the home page') }}</x-ui.button>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
