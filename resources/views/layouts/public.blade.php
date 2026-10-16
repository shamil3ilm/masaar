<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}" class="h-full {{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - {{ $theme->name() }}</title>
    <meta name="description" content="{{ __('ZATCA e-invoicing for Saudi businesses: issue, sign, clear and report every invoice.') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="flex min-h-full flex-col bg-ink-900 text-white">
    <header class="flex flex-wrap items-center justify-between gap-4 px-6 py-6 sm:px-10">
        <a href="{{ url('/') }}"><x-logo :size="36" name name-class="text-xl font-semibold tracking-tight" /></a>
        <nav class="flex flex-wrap items-center gap-4 text-sm">
            <x-locale-switch on-dark />
            @auth
                <a href="{{ route('portal.dashboard') }}" class="font-semibold text-teal-300 hover:text-teal-200">{{ __('Open the portal') }}</a>
                @if (auth()->user()->isPlatformAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="text-white/80 hover:text-white">{{ __('Admin console') }}</a>
                @endif
            @else
                <a href="{{ route('login') }}" class="text-white/80 hover:text-white">{{ __('Sign in') }}</a>
                @if (config('signup.enabled'))
                    <a href="{{ route('register') }}" class="rounded-lg bg-teal-500 px-4 py-2 font-semibold text-ink-950 hover:bg-teal-400">{{ __('Create account') }}</a>
                @endif
            @endauth
        </nav>
    </header>

    <main class="flex-1">
        @yield('content')
    </main>

    <footer class="border-t border-white/10 px-6 py-8 text-sm text-white/60 sm:px-10">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4">
            <p>
                {{ config('company.legal_name') }}
                @if (config('company.cr_number'))
                    &middot; {{ __('CR') }} <span dir="ltr">{{ config('company.cr_number') }}</span>
                @endif
                @if (config('company.vat_number'))
                    &middot; {{ __('VAT number') }} <span dir="ltr">{{ config('company.vat_number') }}</span>
                @endif
            </p>
            <nav class="flex flex-wrap gap-4">
                <a href="{{ route('privacy') }}" class="hover:text-white">{{ __('Privacy') }}</a>
                <a href="{{ route('terms') }}" class="hover:text-white">{{ __('Terms') }}</a>
                @if (config('company.email'))
                    <a href="mailto:{{ config('company.email') }}" class="hover:text-white">{{ config('company.email') }}</a>
                @endif
            </nav>
        </div>
    </footer>
</body>
</html>
