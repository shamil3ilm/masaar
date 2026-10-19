{{--
    The frame for the errors the public layout cannot be trusted to draw:
    a failure on our side (500) and unavailability or maintenance (503).

    It looks like the public layout - the same header, ground and footer -
    but reads nothing it might not have. The theme is the platform's when it
    can be read and the shipped one when it cannot (the database may be what
    failed), and nothing here needs a session.
--}}
@php
    $theme = rescue(
        fn () => app(\App\Domains\Branding\Services\ThemeService::class)->forScope(\App\Domains\Branding\Services\ThemeService::PLATFORM),
        fn () => new \App\Domains\Branding\DTOs\ActiveTheme(
            scope: \App\Domains\Branding\Services\ThemeService::PLATFORM,
            definition: \App\Domains\Branding\DTOs\ThemeDefinition::shipped(),
            stylesheetUrl: asset('favicon.svg').'#none',
            faviconUrl: asset('favicon.svg'),
            faviconType: 'image/svg+xml',
            logoUrl: null,
        ),
        report: false,
    );

    // The logo and the head read the theme from the request (ThemeComposer);
    // given here, they do not ask the database again.
    request()->attributes->set(\App\Domains\Branding\Http\ThemeComposer::ATTRIBUTE, $theme);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-app-icons :theme="$theme" />
</head>
<body class="min-h-screen bg-ground text-fg">
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-line">
            <div class="mx-auto flex max-w-page items-center px-4 py-2.5 sm:px-6">
                <a href="{{ url('/') }}" class="inline-flex shrink-0 rounded">
                    <x-logo :size="32" name name-class="text-lg font-semibold tracking-tight" />
                </a>
            </div>
        </header>

        <main id="content" class="flex-1">
            @yield('content')
        </main>

        <footer class="border-t border-line">
            <nav class="mx-auto flex max-w-page flex-wrap items-center gap-x-5 px-4 py-8 text-sm text-subtle sm:px-6" aria-label="{{ __('Legal') }}">
                <a href="{{ route('privacy') }}" class="inline-flex min-h-11 items-center hover:text-fg hover:underline underline-offset-4">{{ __('Privacy') }}</a>
                <a href="{{ route('terms') }}" class="inline-flex min-h-11 items-center hover:text-fg hover:underline underline-offset-4">{{ __('Terms') }}</a>
            </nav>
        </footer>
    </div>
    <x-ui.strings />
    <x-ui.toasts />
</body>
</html>
