<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}" class="scroll-smooth {{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - {{ $theme->name() }}</title>
    <meta name="description" content="{{ __('E-invoicing for each country you sell in: issue, sign, file and keep every invoice, in Arabic and English.') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
{{--
    The pages anyone can open: the home page, the privacy notice, the terms.

    Warm paper rather than the navy it used to be: the product's documents
    are printed on white, and the front door now looks like them. Navy is
    kept for the one place it means something - the call to action.

    The section links point at the home page's sections from anywhere, so
    they work from the terms page too. Below md they give way to the two
    actions that matter on a phone: sign in, and start.
--}}
<body class="min-h-screen bg-ground text-fg">
    <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-button focus:bg-surface focus:px-4 focus:py-3 focus:shadow-raised">{{ __('Skip to content') }}</a>

    <header class="sticky top-0 z-30 border-b border-line bg-ground/90 backdrop-blur">
        <div class="mx-auto flex max-w-page items-center gap-4 px-4 py-2.5 sm:px-6">
            <a href="{{ url('/') }}" class="inline-flex shrink-0 rounded">
                <x-logo :size="32" name name-class="text-lg font-semibold tracking-tight" />
            </a>

            <nav class="hidden flex-1 items-center gap-1 text-sm lg:flex" aria-label="{{ __('Sections') }}">
                @foreach ([
                    'countries' => __('Countries'),
                    'business' => __('Your business'),
                    'features' => __('Features'),
                    'plans' => __('Plans'),
                    'contact' => __('Contact'),
                ] as $anchor => $label)
                    <a href="{{ url('/') }}#{{ $anchor }}" class="inline-flex min-h-11 items-center rounded-full px-3 text-muted transition-colors hover:bg-sunken hover:text-fg">{{ $label }}</a>
                @endforeach
            </nav>

            <div class="ms-auto flex items-center gap-1 text-sm sm:gap-3">
                <x-locale-switch class="hidden sm:inline" />
                @auth
                    @if (auth()->user()->isPlatformAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="hidden min-h-11 items-center px-2 font-medium text-muted hover:text-fg sm:inline-flex">{{ __('Admin console') }}</a>
                    @endif
                    <x-ui.button :href="route('portal.dashboard')">{{ __('Open the portal') }}</x-ui.button>
                @else
                    <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center px-2 font-medium text-fg hover:underline underline-offset-4">{{ __('Sign in') }}</a>
                    @if (config('signup.enabled'))
                        <x-ui.button :href="route('register')">{{ __('Start free') }}</x-ui.button>
                    @endif
                @endauth
            </div>
        </div>
    </header>

    <main id="content">
        @yield('content')
    </main>

    <footer class="border-t border-line">
        <div class="mx-auto flex max-w-page flex-wrap items-center justify-between gap-x-8 gap-y-3 px-4 py-8 text-sm text-subtle sm:px-6">
            <p>
                {{ config('company.legal_name') }}
                @if (config('company.cr_number'))
                    &middot; {{ __('CR') }} <span dir="ltr">{{ config('company.cr_number') }}</span>
                @endif
                @if (config('company.vat_number'))
                    &middot; {{ __('VAT number') }} <span dir="ltr">{{ config('company.vat_number') }}</span>
                @endif
            </p>
            <nav class="flex flex-wrap items-center gap-x-5" aria-label="{{ __('Legal') }}">
                <a href="{{ route('privacy') }}" class="inline-flex min-h-11 items-center hover:text-fg hover:underline underline-offset-4">{{ __('Privacy') }}</a>
                <a href="{{ route('terms') }}" class="inline-flex min-h-11 items-center hover:text-fg hover:underline underline-offset-4">{{ __('Terms') }}</a>
                @if (config('company.email'))
                    <a href="mailto:{{ config('company.email') }}" class="inline-flex min-h-11 items-center hover:text-fg hover:underline underline-offset-4" dir="ltr">{{ config('company.email') }}</a>
                @endif
                <x-locale-switch class="sm:hidden" />
            </nav>
        </div>
    </footer>
    <x-ui.confirm-dialog />
</body>
</html>
